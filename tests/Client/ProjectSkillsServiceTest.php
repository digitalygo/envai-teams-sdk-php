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
use Digitalygo\Envai\Models\Common\OptionalField;
use Digitalygo\Envai\Models\Common\ResourceCollection;
use Digitalygo\Envai\Models\ProjectSkill\ProjectSkill;
use Digitalygo\Envai\Models\ProjectSkill\ProjectSkillCreateRequest;
use Digitalygo\Envai\Models\ProjectSkill\ProjectSkillUpdateRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** FakeTransport coverage of the five project skill CRUD operations. */
final class ProjectSkillsServiceTest extends TestCase
{
    private const KEY = 'sk-test-secret';

    private const OWNERSHIP_FIELDS = ['id', 'project_id', 'created_by_user_id', 'user_id', 'workspace_id', 'company_id', 'created_at', 'updated_at', 'team_id'];

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
        $transport->queueResponse(ManagementFixtures::collection([ManagementFixtures::projectSkill()]));

        $result = $this->client($transport)->projectSkills()->list(ManagementFixtures::PROJECT);

        $request = $this->requestOf($transport);
        self::assertSame('GET', $request->method);
        self::assertSame('/api/v1/projects/'.ManagementFixtures::PROJECT.'/skills', parse_url($request->uri, PHP_URL_PATH));
        self::assertSame(['Bearer '.self::KEY], $request->headers['authorization']);
        self::assertSame(RetrySafety::Safe, $request->retrySafety);
        self::assertInstanceOf(ResourceCollection::class, $result);
        self::assertSame(ProjectSkill::class, $result->itemClass);
        self::assertCount(1, $result->items);
        self::assertInstanceOf(ProjectSkill::class, $result->items[0]);
        self::assertSame(ManagementFixtures::projectSkill(), $result->items[0]->toArray());
    }

    public function test_list_hydrates_empty_collection(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::collection([]));
        $result = $this->client($transport)->projectSkills()->list(ManagementFixtures::PROJECT);
        self::assertSame([], $result->items);
        self::assertNull($this->requestOf($transport)->body);
    }

    public function test_create_sends_only_name_content_description_and_hydrates_201(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::projectSkill(), 201));

        $skill = $this->client($transport)->projectSkills()->create(
            ManagementFixtures::PROJECT,
            ProjectSkillCreateRequest::withDescription('incident_response', "# Incident response\n\nEscalate after triage.\n", 'Safe incident handling'),
        );

        $http = $this->requestOf($transport);
        self::assertSame('POST', $http->method);
        self::assertSame('/api/v1/projects/'.ManagementFixtures::PROJECT.'/skills', parse_url($http->uri, PHP_URL_PATH));
        self::assertSame(['Bearer '.self::KEY], $http->headers['authorization']);
        self::assertSame('application/json', $http->headers['content-type'][0] ?? null);
        self::assertSame(RetrySafety::NeverRetry, $http->retrySafety);
        self::assertSame([
            'name' => 'incident_response',
            'content' => "# Incident response\n\nEscalate after triage.\n",
            'description' => 'Safe incident handling',
        ], json_decode((string) $http->body, true, 512, JSON_THROW_ON_ERROR));
        self::assertInstanceOf(ProjectSkill::class, $skill);
        self::assertSame(ManagementFixtures::projectSkill(), $skill->toArray());
    }

    public function test_create_omits_absent_description_and_preserves_significant_whitespace(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::projectSkill(), 201));
        $content = "  \n# Heading\n\n  indented body  \n\t\n";

        $this->client($transport)->projectSkills()->create(ManagementFixtures::PROJECT, new ProjectSkillCreateRequest('runbook', $content));

        $body = json_decode((string) $this->requestOf($transport)->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['name' => 'runbook', 'content' => $content], $body);
        self::assertArrayNotHasKey('description', $body);
        foreach (self::OWNERSHIP_FIELDS as $field) {
            self::assertArrayNotHasKey($field, $body);
        }
    }

    public function test_create_can_send_explicit_null_description(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::projectSkill(), 201));
        $this->client($transport)->projectSkills()->create(
            ManagementFixtures::PROJECT,
            ProjectSkillCreateRequest::withDescription('runbook', '# Body', null),
        );
        self::assertSame(['name' => 'runbook', 'content' => '# Body', 'description' => null], json_decode((string) $this->requestOf($transport)->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_get_sends_single_resource_get(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::projectSkill()));

        $skill = $this->client($transport)->projectSkills()->get(ManagementFixtures::PROJECT, ManagementFixtures::SKILL);

        $http = $this->requestOf($transport);
        self::assertSame('GET', $http->method);
        self::assertSame('/api/v1/projects/'.ManagementFixtures::PROJECT.'/skills/'.ManagementFixtures::SKILL, parse_url($http->uri, PHP_URL_PATH));
        self::assertSame(['Bearer '.self::KEY], $http->headers['authorization']);
        self::assertSame(RetrySafety::Safe, $http->retrySafety);
        self::assertInstanceOf(ProjectSkill::class, $skill);
        self::assertSame(ManagementFixtures::projectSkill(), $skill->toArray());
    }

    public function test_update_is_a_partial_put_and_is_never_retried(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::projectSkill(['name' => 'renamed'])));

        $request = new ProjectSkillUpdateRequest(
            name: OptionalField::of('renamed'),
            content: OptionalField::of("  # Renamed\n"),
        );
        $skill = $this->client($transport)->projectSkills()->update(ManagementFixtures::PROJECT, ManagementFixtures::SKILL, $request);

        $http = $this->requestOf($transport);
        self::assertSame('PUT', $http->method);
        self::assertSame('/api/v1/projects/'.ManagementFixtures::PROJECT.'/skills/'.ManagementFixtures::SKILL, parse_url($http->uri, PHP_URL_PATH));
        self::assertSame(['Bearer '.self::KEY], $http->headers['authorization']);
        self::assertSame(RetrySafety::NeverRetry, $http->retrySafety);
        self::assertSame(['name' => 'renamed', 'content' => "  # Renamed\n"], json_decode((string) $http->body, true, 512, JSON_THROW_ON_ERROR));
        self::assertInstanceOf(ProjectSkill::class, $skill);
        self::assertSame('renamed', $skill->name);
    }

    public function test_update_can_clear_description_with_null_and_never_sends_ownership(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::projectSkill(['description' => null])));

        $this->client($transport)->projectSkills()->update(ManagementFixtures::PROJECT, ManagementFixtures::SKILL, ProjectSkillUpdateRequest::clearDescription());

        $body = json_decode((string) $this->requestOf($transport)->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['description' => null], $body);
        foreach (self::OWNERSHIP_FIELDS as $field) {
            self::assertArrayNotHasKey($field, $body);
        }
    }

    public function test_delete_expects_204_and_is_never_retried(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::noContent());

        $result = $this->client($transport)->projectSkills()->delete(ManagementFixtures::PROJECT, ManagementFixtures::SKILL);

        $http = $this->requestOf($transport);
        self::assertSame('DELETE', $http->method);
        self::assertSame('/api/v1/projects/'.ManagementFixtures::PROJECT.'/skills/'.ManagementFixtures::SKILL, parse_url($http->uri, PHP_URL_PATH));
        self::assertSame(['Bearer '.self::KEY], $http->headers['authorization']);
        self::assertSame(RetrySafety::NeverRetry, $http->retrySafety);
        self::assertNull($http->body);
        self::assertNull($result);
    }

    public function test_project_and_skill_ids_are_percent_escaped(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::projectSkill()));
        $this->client($transport)->projectSkills()->get('p/1', 's 2');
        self::assertSame('/api/v1/projects/p%2F1/skills/s%202', parse_url($this->requestOf($transport)->uri, PHP_URL_PATH));
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
        $code = 'skill_'.$status;
        $details = ['field' => 'name', 'status' => $status];
        $transport->queueResponse(ManagementFixtures::error($status, $type, $code, 'Rejected.', $details));

        try {
            $this->client($transport)->projectSkills()->get(ManagementFixtures::PROJECT, ManagementFixtures::SKILL);
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

    public function test_create_conflict_is_surfaced_without_retry(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(ManagementFixtures::error(409, 'conflict', 'skill_name_taken', 'Name already exists.', ['name' => 'runbook']));

        try {
            $this->client($transport)->projectSkills()->create(ManagementFixtures::PROJECT, new ProjectSkillCreateRequest('runbook', '# Body'));
            self::fail('Expected ConflictException');
        } catch (ConflictException $e) {
            self::assertSame(409, $e->httpStatus);
            self::assertSame('skill_name_taken', $e->apiError()?->code);
        }
        self::assertCount(1, $transport->requests);
    }

    public function test_strict_validation_accepts_canonical_skill_requests_and_responses(): void
    {
        $transport = new FakeTransport;
        $client = $this->client($transport, strict: true);

        $transport->queueResponse(ManagementFixtures::collection([ManagementFixtures::projectSkill()]));
        self::assertCount(1, $client->projectSkills()->list(ManagementFixtures::PROJECT)->items);

        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::projectSkill(), 201));
        self::assertInstanceOf(ProjectSkill::class, $client->projectSkills()->create(
            ManagementFixtures::PROJECT,
            new ProjectSkillCreateRequest('incident_response', "# Incident response\n\nEscalate after triage.\n", OptionalField::of('Safe incident handling')),
        ));

        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::projectSkill()));
        self::assertInstanceOf(ProjectSkill::class, $client->projectSkills()->get(ManagementFixtures::PROJECT, ManagementFixtures::SKILL));

        $transport->queueResponse(ManagementFixtures::envelope(ManagementFixtures::projectSkill()));
        self::assertInstanceOf(ProjectSkill::class, $client->projectSkills()->update(ManagementFixtures::PROJECT, ManagementFixtures::SKILL, ProjectSkillUpdateRequest::content('  # Updated  ')));

        $transport->queueResponse(ManagementFixtures::noContent());
        self::assertNull($client->projectSkills()->delete(ManagementFixtures::PROJECT, ManagementFixtures::SKILL));
    }

    public function test_strict_validation_rejects_reserved_skill_name_before_any_network(): void
    {
        $transport = new FakeTransport;
        try {
            $this->client($transport, strict: true)->projectSkills()->create(
                ManagementFixtures::PROJECT,
                new ProjectSkillCreateRequest('filesystem', '# Reserved'),
            );
            self::fail('A reserved built-in skill name must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $transport->requests);
            self::assertSame([], $transport->preflightRequests);
        }
    }
}
