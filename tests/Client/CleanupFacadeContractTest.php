<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\AccessDeniedException;
use Digitalygo\Envai\Exceptions\AuthenticationException;
use Digitalygo\Envai\Exceptions\ConflictException;
use Digitalygo\Envai\Exceptions\NotFoundException;
use Digitalygo\Envai\Exceptions\ValidationException;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\Chat\Chat;
use Digitalygo\Envai\Models\Chat\ChatUpdateRequest;
use Digitalygo\Envai\Models\Common\CleanupResult;
use Digitalygo\Envai\Tests\Http\Client\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Cleanup, chat update and workspace chat facade contract coverage. */
final class CleanupFacadeContractTest extends TestCase
{
    private const KEY = 'sk-test-secret';

    private function client(FakeTransport $transport, bool $strict = false, ?LoggerInterface $logger = null): EnvaiClient
    {
        return new EnvaiClient(
            new EnvaiConfig('https://envai.example.com', new SensitiveString(self::KEY), strictValidation: $strict),
            $transport,
            $logger,
        );
    }

    private function requestOf(FakeTransport $transport, int $index = 0): HttpRequest
    {
        return $transport->requests[$index];
    }

    public function test_workspace_delete_returns_pending_cleanup_from_http_200_not_a_purge(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::cleanup()));

        $result = $this->client($transport)->workspaces()->delete(ManagementFixtures::WORKSPACE);

        $http = $this->requestOf($transport);
        self::assertSame('DELETE', $http->method);
        self::assertSame('/api/v1/workspaces/'.ManagementFixtures::WORKSPACE, parse_url($http->uri, PHP_URL_PATH));
        self::assertSame(['Bearer '.self::KEY], $http->headers['authorization']);
        self::assertSame(RetrySafety::NeverRetry, $http->retrySafety);
        self::assertNull($http->body);

        self::assertInstanceOf(CleanupResult::class, $result);
        self::assertSame('workspace', $result->resourceType);
        self::assertSame(ManagementFixtures::WORKSPACE, $result->resourceId);
        self::assertCount(1, $result->cleanup);
        self::assertSame('pending', $result->cleanup[0]->status);
        self::assertSame(0, $result->cleanup[0]->attempts);
        self::assertSame(ManagementFixtures::cleanup(), $result->toArray());
    }

    public function test_chat_delete_direct_and_facade_share_path_and_return_pending_cleanup(): void
    {
        $chatCleanup = ManagementFixtures::cleanup(['resource_type' => 'chat', 'resource_id' => ManagementFixtures::CHAT]);

        $direct = new FakeTransport;
        $direct->queueResponse(ManagementFixtures::envelope($chatCleanup));
        $directResult = $this->client($direct)->chats()->deleteChat(ManagementFixtures::WORKSPACE, ManagementFixtures::CHAT);

        $facade = new FakeTransport;
        $facade->queueResponse(ManagementFixtures::envelope($chatCleanup));
        $facadeResult = $this->client($facade)->workspace(ManagementFixtures::WORKSPACE)->chats()->delete(ManagementFixtures::CHAT);

        foreach ([[$direct, $directResult], [$facade, $facadeResult]] as [$transport, $result]) {
            $http = $this->requestOf($transport);
            self::assertSame('DELETE', $http->method);
            self::assertSame('/api/v1/workspaces/'.ManagementFixtures::WORKSPACE.'/chats/'.ManagementFixtures::CHAT, parse_url($http->uri, PHP_URL_PATH));
            self::assertSame(['Bearer '.self::KEY], $http->headers['authorization']);
            self::assertSame(RetrySafety::NeverRetry, $http->retrySafety);
            self::assertInstanceOf(CleanupResult::class, $result);
            self::assertSame('pending', $result->cleanup[0]->status);
            self::assertSame($chatCleanup, $result->toArray());
        }
    }

    public function test_update_chat_direct_and_facade_send_only_title(): void
    {
        $direct = new FakeTransport;
        $direct->queueResponse(ManagementFixtures::envelope(ManagementFixtures::chat(['title' => 'Q3 rollout plan'])));
        $directResult = $this->client($direct)->chats()->updateChat(ManagementFixtures::WORKSPACE, ManagementFixtures::CHAT, new ChatUpdateRequest('Q3 rollout plan'));

        $facade = new FakeTransport;
        $facade->queueResponse(ManagementFixtures::envelope(ManagementFixtures::chat(['title' => 'Q3 rollout plan'])));
        $facadeResult = $this->client($facade)->workspace(ManagementFixtures::WORKSPACE)->chats()->update(ManagementFixtures::CHAT, new ChatUpdateRequest('Q3 rollout plan'));

        foreach ([[$direct, $directResult], [$facade, $facadeResult]] as [$transport, $result]) {
            $http = $this->requestOf($transport);
            self::assertSame('PUT', $http->method);
            self::assertSame('/api/v1/workspaces/'.ManagementFixtures::WORKSPACE.'/chats/'.ManagementFixtures::CHAT, parse_url($http->uri, PHP_URL_PATH));
            self::assertSame(['Bearer '.self::KEY], $http->headers['authorization']);
            self::assertSame('application/json', $http->headers['content-type'][0] ?? null);
            self::assertSame(RetrySafety::NeverRetry, $http->retrySafety);
            self::assertSame(['title' => 'Q3 rollout plan'], json_decode((string) $http->body, true, 512, JSON_THROW_ON_ERROR));
            self::assertInstanceOf(Chat::class, $result);
            self::assertSame('Q3 rollout plan', $result->title);
        }
    }

    public function test_update_chat_null_title_clears_and_ownership_is_never_sent(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::chat()));
        $this->client($transport)->workspace(ManagementFixtures::WORKSPACE)->chats()->update(ManagementFixtures::CHAT, new ChatUpdateRequest(null));

        $body = json_decode((string) $this->requestOf($transport)->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['title' => null], $body);
        foreach (['id', 'workspace_id', 'project_id', 'created_by_user_id', 'team_id', 'user_id', 'created_at', 'updated_at'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $body);
        }
    }

    public function test_cleanup_update_and_delete_ids_are_percent_escaped(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::chat()));
        $this->client($transport)->chats()->updateChat('w/1', 'c 2', new ChatUpdateRequest('x'));
        self::assertSame('/api/v1/workspaces/w%2F1/chats/c%202', parse_url($this->requestOf($transport)->uri, PHP_URL_PATH));
    }

    /** @return iterable<string, array{int, string, class-string<\Throwable>}> */
    public static function errorCases(): iterable
    {
        yield '401' => [401, 'unauthenticated', AuthenticationException::class];
        yield '403' => [403, 'access_denied', AccessDeniedException::class];
        yield '404' => [404, 'not_found', NotFoundException::class];
        yield '409' => [409, 'conflict', ConflictException::class];
        yield '422' => [422, 'validation_error', ValidationException::class];
    }

    #[DataProvider('errorCases')]
    public function test_error_envelopes_preserve_status_code_and_details(int $status, string $type, string $class): void
    {
        $transport = new FakeTransport;
        $code = 'cleanup_'.$status;
        $details = ['field' => 'title', 'status' => $status];
        $transport->queueResponse(ManagementFixtures::error($status, $type, $code, 'Rejected.', $details));

        try {
            $this->client($transport)->chats()->updateChat(ManagementFixtures::WORKSPACE, ManagementFixtures::CHAT, new ChatUpdateRequest('x'));
            self::fail('Expected '.$class);
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e);
            self::assertSame($status, $e->httpStatus);
            self::assertSame($type, $e->apiError()?->type);
            self::assertSame($code, $e->apiError()?->code);
            self::assertSame($details, $e->apiError()?->details);
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
        }
        self::assertCount(1, $transport->requests);
    }

    public function test_409_active_cron_guard_is_not_retried_or_cancelled(): void
    {
        foreach (['workspace', 'chat'] as $kind) {
            $transport = new FakeTransport;
            $transport->queueResponse(ManagementFixtures::error(409, 'conflict', 'active_cron', 'An active schedule protects this resource.', ['active' => true]));
            try {
                if ($kind === 'workspace') {
                    $this->client($transport)->workspaces()->delete(ManagementFixtures::WORKSPACE);
                } else {
                    $this->client($transport)->chats()->deleteChat(ManagementFixtures::WORKSPACE, ManagementFixtures::CHAT);
                }
                self::fail('Expected ConflictException for '.$kind);
            } catch (ConflictException $e) {
                self::assertSame(409, $e->httpStatus);
                self::assertSame('active_cron', $e->apiError()?->code);
                self::assertSame(['active' => true], $e->apiError()?->details);
            }
            self::assertCount(1, $transport->requests, 'A 409 must not be retried or followed by an automatic cron cancel.');
            self::assertSame('DELETE', $transport->requests[0]->method);
        }
    }

    public function test_no_credentials_are_logged_or_exposed_on_error(): void
    {
        $transport = new FakeTransport;
        $logger = new RecordingLogger;
        $transport->queueResponse(ManagementFixtures::error(409, 'conflict', 'active_cron', 'Blocked.', null));

        try {
            $this->client($transport, logger: $logger)->chats()->deleteChat(ManagementFixtures::WORKSPACE, ManagementFixtures::CHAT);
            self::fail('Expected ConflictException');
        } catch (ConflictException $e) {
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
        }

        self::assertNotEmpty($logger->records);
        foreach ($logger->records as $record) {
            self::assertStringNotContainsString(self::KEY, $record['message']);
            self::assertStringNotContainsString(self::KEY, json_encode($record['context'], JSON_THROW_ON_ERROR));
        }
    }

    public function test_strict_validation_accepts_canonical_cleanup_and_update_requests(): void
    {
        $transport = new FakeTransport;
        $client = $this->client($transport, strict: true);

        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::cleanup()));
        self::assertInstanceOf(CleanupResult::class, $client->workspaces()->delete(ManagementFixtures::WORKSPACE));

        $chatCleanup = ManagementFixtures::cleanup(['resource_type' => 'chat', 'resource_id' => ManagementFixtures::CHAT]);
        $transport->queueResponse(ManagementFixtures::envelope($chatCleanup));
        self::assertInstanceOf(CleanupResult::class, $client->chats()->deleteChat(ManagementFixtures::WORKSPACE, ManagementFixtures::CHAT));

        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::chat(['title' => 'Q3 rollout plan'])));
        self::assertInstanceOf(Chat::class, $client->chats()->updateChat(ManagementFixtures::WORKSPACE, ManagementFixtures::CHAT, new ChatUpdateRequest('Q3 rollout plan')));
    }
}
