<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Models;

use Digitalygo\Envai\Models\Common\OptionalField;
use Digitalygo\Envai\Models\ProjectSkill\ProjectSkill;
use Digitalygo\Envai\Models\ProjectSkill\ProjectSkillCreateRequest;
use Digitalygo\Envai\Models\ProjectSkill\ProjectSkillUpdateRequest;
use Digitalygo\Envai\Tests\Client\ManagementFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ProjectSkill DTO and create/update request round trips and validation. */
final class ProjectSkillModelTest extends TestCase
{
    private const OWNERSHIP_FIELDS = ['id', 'project_id', 'created_by_user_id', 'user_id', 'workspace_id', 'company_id', 'created_at', 'updated_at', 'team_id'];

    public function test_from_array_round_trips_every_field_and_preserves_extra(): void
    {
        $payload = ManagementFixtures::projectSkill(['future_flag' => true, 'server_label' => 'new']);
        $skill = ProjectSkill::fromArray($payload);

        self::assertSame('psk_01j5abcdefghjkmnpqrstvwxyz', $skill->id);
        self::assertSame('incident_response', $skill->name);
        self::assertSame("# Incident response\n\nEscalate after triage.\n", $skill->content);
        self::assertNull($skill->createdByUserId);
        self::assertTrue($skill->extra['future_flag'] ?? false);
        self::assertSame('new', $skill->extra['server_label'] ?? null);
        self::assertSame($payload, $skill->toArray());
    }

    public function test_content_whitespace_is_preserved_verbatim(): void
    {
        $content = "  \n# Runbook\n\n  keep indentation  \n\t\n";
        $skill = ProjectSkill::fromArray(ManagementFixtures::projectSkill(['content' => $content]));
        self::assertSame($content, $skill->content);
        self::assertSame($content, $skill->toArray()['content']);
    }

    public function test_absent_nullable_fields_default_to_null(): void
    {
        $payload = ManagementFixtures::projectSkill();
        unset($payload['description'], $payload['created_by_user_id']);
        $skill = ProjectSkill::fromArray($payload);
        self::assertNull($skill->description);
        self::assertNull($skill->createdByUserId);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPayloads(): iterable
    {
        $base = ManagementFixtures::projectSkill();
        yield 'missing id' => [self::without($base, 'id')];
        yield 'id wrong type' => [self::with($base, 'id', 1)];
        yield 'missing project_id' => [self::without($base, 'project_id')];
        yield 'name wrong type' => [self::with($base, 'name', null)];
        yield 'missing content' => [self::without($base, 'content')];
        yield 'content wrong type' => [self::with($base, 'content', 42)];
        yield 'description wrong type' => [self::with($base, 'description', 5)];
        yield 'created_by_user_id wrong type' => [self::with($base, 'created_by_user_id', false)];
        yield 'created_at wrong type' => [self::with($base, 'created_at', [])];
        yield 'missing updated_at' => [self::without($base, 'updated_at')];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payloads_are_rejected(array $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProjectSkill::fromArray($payload);
    }

    public function test_create_request_emits_only_name_and_content_by_default(): void
    {
        $request = new ProjectSkillCreateRequest('runbook', '# Body');
        self::assertSame(['name' => 'runbook', 'content' => '# Body'], $request->toArray());
        self::assertTrue($request->description->isAbsent());
        foreach (self::OWNERSHIP_FIELDS as $field) {
            self::assertArrayNotHasKey($field, $request->toArray());
        }
    }

    public function test_create_request_omits_absent_description_preserves_whitespace_and_accepts_null(): void
    {
        $content = "  \n# Runbook\n\t";
        self::assertSame(['name' => 'runbook', 'content' => $content], (new ProjectSkillCreateRequest('runbook', $content))->toArray());
        self::assertSame(
            ['name' => 'runbook', 'content' => $content, 'description' => 'note'],
            ProjectSkillCreateRequest::withDescription('runbook', $content, 'note')->toArray(),
        );
        self::assertSame(
            ['name' => 'runbook', 'content' => $content, 'description' => null],
            ProjectSkillCreateRequest::withDescription('runbook', $content, null)->toArray(),
        );
    }

    public function test_create_request_rejects_wrong_description_type(): void
    {
        $this->expectException(\LogicException::class);
        (new ProjectSkillCreateRequest('runbook', '# Body', OptionalField::of(5)))->toArray();
    }

    public function test_empty_update_request_omits_every_field(): void
    {
        $request = new ProjectSkillUpdateRequest;
        self::assertSame([], $request->toArray());
        self::assertTrue($request->name->isAbsent());
        self::assertTrue($request->description->isAbsent());
        self::assertTrue($request->content->isAbsent());
    }

    public function test_update_request_supports_partial_fields_null_clear_and_whitespace(): void
    {
        $content = "  # Updated\n";
        $request = new ProjectSkillUpdateRequest(
            name: OptionalField::of('renamed'),
            content: OptionalField::of($content),
        );
        self::assertSame(['name' => 'renamed', 'content' => $content], $request->toArray());

        self::assertSame(['description' => null], ProjectSkillUpdateRequest::clearDescription()->toArray());
        self::assertSame(['name' => 'x'], ProjectSkillUpdateRequest::name('x')->toArray());
        self::assertSame(['content' => '# y'], ProjectSkillUpdateRequest::content('# y')->toArray());
        self::assertSame(['description' => 'note'], ProjectSkillUpdateRequest::description('note')->toArray());
    }

    public function test_update_request_never_emits_ownership_or_audit_fields(): void
    {
        $request = new ProjectSkillUpdateRequest(
            name: OptionalField::of('renamed'),
            description: OptionalField::of(null),
            content: OptionalField::of('# Body'),
        );
        foreach (self::OWNERSHIP_FIELDS as $field) {
            self::assertArrayNotHasKey($field, $request->toArray());
        }
    }

    /** @return iterable<string, array{ProjectSkillUpdateRequest}> */
    public static function invalidUpdateRequests(): iterable
    {
        yield 'name not string' => [new ProjectSkillUpdateRequest(name: OptionalField::of(5))];
        yield 'content not string' => [new ProjectSkillUpdateRequest(content: OptionalField::of(null))];
        yield 'description not string' => [new ProjectSkillUpdateRequest(description: OptionalField::of(false))];
    }

    #[DataProvider('invalidUpdateRequests')]
    public function test_update_request_rejects_wrong_field_types(ProjectSkillUpdateRequest $request): void
    {
        $this->expectException(\LogicException::class);
        $request->toArray();
    }

    /** @param array<string, mixed> $base */
    private static function without(array $base, string $key): array
    {
        unset($base[$key]);

        return $base;
    }

    /** @param array<string, mixed> $base */
    private static function with(array $base, string $key, mixed $value): array
    {
        $base[$key] = $value;

        return $base;
    }
}
