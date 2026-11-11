<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\ChatCronsService;
use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Client\OperationInventory;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\AccessDeniedException;
use Digitalygo\Envai\Exceptions\AuthenticationException;
use Digitalygo\Envai\Exceptions\ConflictException;
use Digitalygo\Envai\Exceptions\NotFoundException;
use Digitalygo\Envai\Exceptions\ValidationException;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\ChatCron\ChatCron;
use Digitalygo\Envai\Models\ChatCron\ChatCronFilters;
use Digitalygo\Envai\Models\ChatCron\ChatCronStatus;
use Digitalygo\Envai\Models\ChatCron\ChatCronTargetMode;
use Digitalygo\Envai\Models\ChatCron\ChatCronUpdateRequest;
use Digitalygo\Envai\Models\Common\OptionalField;
use Digitalygo\Envai\Models\Common\ResourceCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** FakeTransport coverage of the four workspace-scoped chat cron operations. */
final class ChatCronsServiceTest extends TestCase
{
    private const KEY = 'sk-test-secret';

    private function client(FakeTransport $transport, bool $strict = false): EnvaiClient
    {
        return new EnvaiClient(
            new EnvaiConfig('https://envai.example.com', new SensitiveString(self::KEY), strictValidation: $strict),
            $transport,
        );
    }

    private function requestOf(FakeTransport $transport): HttpRequest
    {
        self::assertCount(1, $transport->requests);

        return $transport->requests[0];
    }

    public function test_list_sends_bearer_get_and_hydrates_collection(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::collection([ManagementFixtures::chatCron()]));

        $result = $this->client($transport)->chatCrons()->list(ManagementFixtures::WORKSPACE);

        $request = $this->requestOf($transport);
        self::assertSame('GET', $request->method);
        self::assertSame('/api/v1/workspaces/'.ManagementFixtures::WORKSPACE.'/crons', parse_url($request->uri, PHP_URL_PATH));
        self::assertNull(parse_url($request->uri, PHP_URL_QUERY));
        self::assertSame(['Bearer '.self::KEY], $request->headers['authorization']);
        self::assertSame(RetrySafety::Safe, $request->retrySafety);
        self::assertNull($request->body);

        self::assertInstanceOf(ResourceCollection::class, $result);
        self::assertSame(ChatCron::class, $result->itemClass);
        self::assertCount(1, $result->items);
        self::assertInstanceOf(ChatCron::class, $result->items[0]);
        self::assertSame(ManagementFixtures::CRON, $result->items[0]->id);
        self::assertSame(ChatCronStatus::Active, $result->items[0]->status);
        self::assertSame(ChatCronTargetMode::SameChat, $result->items[0]->targetMode);
        self::assertSame([], $result->items[0]->extra);
        self::assertSame(ManagementFixtures::chatCron(), $result->items[0]->toArray());
    }

    public function test_list_serializes_origin_chat_and_status_filters(): void
    {
        $cases = [[ChatCronStatus::Active, 'active'], [ChatCronStatus::Completed, 'completed']];
        foreach ($cases as [$status, $value]) {
            $transport = new FakeTransport;
            $transport->queueResponse(ManagementFixtures::collection([]));
            $this->client($transport)->chatCrons()->list(
                ManagementFixtures::WORKSPACE,
                new ChatCronFilters(originChatId: ManagementFixtures::CHAT, status: $status),
            );
            parse_str((string) parse_url($this->requestOf($transport)->uri, PHP_URL_QUERY), $query);
            self::assertSame(ManagementFixtures::CHAT, $query['origin_chat_id'] ?? null);
            self::assertSame($value, $query['status'] ?? null);
        }
    }

    public function test_list_hydrates_empty_collection_and_omits_absent_filters(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::collection([]));
        $result = $this->client($transport)->chatCrons()->list(ManagementFixtures::WORKSPACE, new ChatCronFilters);
        self::assertSame([], $result->items);
        self::assertNull(parse_url($this->requestOf($transport)->uri, PHP_URL_QUERY));
    }

    public function test_get_sends_single_resource_get(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::chatCron()));

        $cron = $this->client($transport)->chatCrons()->get(ManagementFixtures::WORKSPACE, ManagementFixtures::CRON);

        $request = $this->requestOf($transport);
        self::assertSame('GET', $request->method);
        self::assertSame('/api/v1/workspaces/'.ManagementFixtures::WORKSPACE.'/crons/'.ManagementFixtures::CRON, parse_url($request->uri, PHP_URL_PATH));
        self::assertSame(['Bearer '.self::KEY], $request->headers['authorization']);
        self::assertSame(RetrySafety::Safe, $request->retrySafety);
        self::assertInstanceOf(ChatCron::class, $cron);
        self::assertSame(ManagementFixtures::chatCron(), $cron->toArray());
    }

    public function test_update_sends_only_present_fields_and_is_never_retried(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::chatCron(['target_mode' => 'new_chat'])));

        $request = new ChatCronUpdateRequest(
            targetMode: OptionalField::of(ChatCronTargetMode::NewChat),
            prompt: OptionalField::of('New prompt'),
            intervalMinutes: OptionalField::of(30),
            timezone: OptionalField::of('Europe/Rome'),
        );
        $cron = $this->client($transport)->chatCrons()->update(ManagementFixtures::WORKSPACE, ManagementFixtures::CRON, $request);

        $http = $this->requestOf($transport);
        self::assertSame('PATCH', $http->method);
        self::assertSame('/api/v1/workspaces/'.ManagementFixtures::WORKSPACE.'/crons/'.ManagementFixtures::CRON, parse_url($http->uri, PHP_URL_PATH));
        self::assertSame(['Bearer '.self::KEY], $http->headers['authorization']);
        self::assertSame('application/json', $http->headers['content-type'][0] ?? null);
        self::assertSame(RetrySafety::NeverRetry, $http->retrySafety);
        self::assertSame([
            'target_mode' => 'new_chat',
            'prompt' => 'New prompt',
            'interval_minutes' => 30,
            'timezone' => 'Europe/Rome',
        ], json_decode((string) $http->body, true, 512, JSON_THROW_ON_ERROR));
        self::assertInstanceOf(ChatCron::class, $cron);
        self::assertSame(ChatCronTargetMode::NewChat, $cron->targetMode);
    }

    public function test_update_can_clear_every_nullable_schedule_field_without_sending_immutable_scope(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::chatCron()));

        $request = new ChatCronUpdateRequest(
            intervalMinutes: OptionalField::of(null),
            dailyAt: OptionalField::of(null),
            timezone: OptionalField::of(null),
            startAt: OptionalField::of(null),
            repeatCount: OptionalField::of(null),
            until: OptionalField::of(null),
        );
        $this->client($transport)->chatCrons()->update(ManagementFixtures::WORKSPACE, ManagementFixtures::CRON, $request);

        $body = json_decode((string) $this->requestOf($transport)->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([
            'interval_minutes' => null,
            'daily_at' => null,
            'timezone' => null,
            'start_at' => null,
            'repeat_count' => null,
            'until' => null,
        ], $body);
        foreach (['workspace_id', 'project_id', 'origin_chat_id', 'team_id', 'status', 'scheduled_count', 'delivery_count', 'max_until', 'created_at', 'updated_at', 'api_key_id', 'turn_id', 'call_id'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $body);
        }
    }

    public function test_delete_expects_204_and_is_never_retried(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::noContent());

        $result = $this->client($transport)->chatCrons()->delete(ManagementFixtures::WORKSPACE, ManagementFixtures::CRON);

        $http = $this->requestOf($transport);
        self::assertSame('DELETE', $http->method);
        self::assertSame('/api/v1/workspaces/'.ManagementFixtures::WORKSPACE.'/crons/'.ManagementFixtures::CRON, parse_url($http->uri, PHP_URL_PATH));
        self::assertSame(['Bearer '.self::KEY], $http->headers['authorization']);
        self::assertSame(RetrySafety::NeverRetry, $http->retrySafety);
        self::assertNull($http->body);
        self::assertNull($result);
    }

    public function test_workspace_and_cron_ids_are_percent_escaped(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::chatCron()));
        $this->client($transport)->chatCrons()->get('w/1', 'c 2');
        self::assertSame('/api/v1/workspaces/w%2F1/crons/c%202', parse_url($this->requestOf($transport)->uri, PHP_URL_PATH));
    }

    public function test_cron_service_exposes_only_manual_management_operations(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(ChatCronsService::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);
        self::assertSame(['__construct', 'delete', 'get', 'list', 'update'], $methods);
        self::assertNotContains('create', $methods);
        self::assertArrayNotHasKey('createChatCron', OperationInventory::OPERATIONS);
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
        $code = 'err_'.$status;
        $details = ['field' => 'cron_id', 'status' => $status];
        $transport->queueResponse(ManagementFixtures::error($status, $type, $code, 'Request rejected.', $details));

        try {
            $this->client($transport)->chatCrons()->get(ManagementFixtures::WORKSPACE, ManagementFixtures::CRON);
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

    public function test_update_conflict_is_surfaced_without_retry_or_automatic_cancel(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::error(409, 'conflict', 'cron_not_active', 'Schedule is not active.', ['active' => false]));

        try {
            $this->client($transport)->chatCrons()->update(
                ManagementFixtures::WORKSPACE,
                ManagementFixtures::CRON,
                ChatCronUpdateRequest::prompt('Keep going'),
            );
            self::fail('Expected ConflictException');
        } catch (ConflictException $e) {
            self::assertSame(409, $e->httpStatus);
            self::assertSame('cron_not_active', $e->apiError()?->code);
            self::assertSame(['active' => false], $e->apiError()?->details);
        }

        self::assertCount(1, $transport->requests, 'A 409 must not be retried.');
        self::assertSame('PATCH', $transport->requests[0]->method, 'A 409 must not trigger an automatic cancel/delete.');
    }

    public function test_strict_validation_accepts_canonical_cron_requests_and_responses(): void
    {
        $transport = new FakeTransport;
        $client = $this->client($transport, strict: true);

        $transport->queueResponse(ManagementFixtures::collection([ManagementFixtures::chatCron()]));
        self::assertCount(1, $client->chatCrons()->list(ManagementFixtures::WORKSPACE, new ChatCronFilters(status: ChatCronStatus::Active))->items);

        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::chatCron()));
        self::assertInstanceOf(ChatCron::class, $client->chatCrons()->get(ManagementFixtures::WORKSPACE, ManagementFixtures::CRON));

        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::chatCron()));
        self::assertInstanceOf(ChatCron::class, $client->chatCrons()->update(
            ManagementFixtures::WORKSPACE,
            ManagementFixtures::CRON,
            new ChatCronUpdateRequest(prompt: OptionalField::of('  Keep whitespace  '), intervalMinutes: OptionalField::of(15)),
        ));

        $transport->queueResponse(ManagementFixtures::noContent());
        self::assertNull($client->chatCrons()->delete(ManagementFixtures::WORKSPACE, ManagementFixtures::CRON));
    }

    public function test_strict_validation_rejects_out_of_range_interval_before_any_network(): void
    {
        $transport = new FakeTransport;
        try {
            $this->client($transport, strict: true)->chatCrons()->update(
                ManagementFixtures::WORKSPACE,
                ManagementFixtures::CRON,
                ChatCronUpdateRequest::intervalMinutes(0),
            );
            self::fail('An interval below the canonical minimum must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $transport->requests);
            self::assertSame([], $transport->preflightRequests);
        }
    }
}
