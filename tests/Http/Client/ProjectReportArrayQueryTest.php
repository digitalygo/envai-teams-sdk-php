<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Client;

use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Http\Client\StrictValidationTransport;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Tests\Client\FakeTransport;
use PHPUnit\Framework\TestCase;

final class ProjectReportArrayQueryTest extends TestCase
{
    private const PROJECT_ID = 'prj_01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private const KEY_ID = 'key_01j5abcdefghjkmnpqrstvwxyz';

    private const CHAT_ID = 'cht_01j5abcdefghjkmnpqrstvwxyz';

    private const WORKSPACE_ID = 'wsp_01j5abcdefghjkmnpqrstvwxyz';

    public function test_valid_list_encodings_and_error_responses(): void
    {
        $queries = [
            'types[]=alpha&types[]=beta',
            'types[0]=alpha&types[1]=beta',
            'workspace_ids[0]='.self::WORKSPACE_ID.'&api_key_ids[]='.self::KEY_ID,
            'types[0]=alpha&workspace_ids[]='.self::WORKSPACE_ID,
            'view=events&from=2026-10-01&to=2026-10-10&type=alpha&api_key_id='.self::KEY_ID.'&chat_id='.self::CHAT_ID.'&page=2&per_page=25',
            'view=analytics&from=2026-10-01&to=2026-10-10&api_key_id='.self::KEY_ID.'&chat_id='.self::CHAT_ID,
            'view=filters&from=2026-10-01&to=2026-10-10',
            'typescript=fixture&workspace_idsuffix=fixture&api_key_idsuffix=fixture',
        ];

        foreach ($queries as $query) {
            $fake = new FakeTransport;
            $fake->queueResponse(new HttpResponse(422, [], '{}'));
            $strict = new StrictValidationTransport($fake, new UriResolver('https://example.test'));
            self::assertSame(422, $strict->send(HttpRequest::get($this->url().'?'.$query))->status);
            self::assertCount(1, $fake->requests);
        }
    }

    public function test_invalid_list_encodings_are_rejected_before_sending(): void
    {
        $queries = [
            'types=alpha',
            'types[first]=alpha',
            'types[0][]=alpha',
            'types[1]=alpha',
            'types[0]=alpha&types[2]=beta',
            'types[]=alpha&types[1]=beta',
            'types[0]=alpha&types[]=beta',
            'types[0]=alpha&types[0]=beta',
            'types[]=alpha&types[]=alpha',
            'types[]=',
            'types[]='.str_repeat('a', 81),
            'workspace_ids='.self::WORKSPACE_ID,
            'workspace_ids[first]='.self::WORKSPACE_ID,
            'workspace_ids[0][]='.self::WORKSPACE_ID,
            'workspace_ids[1]='.self::WORKSPACE_ID,
            'workspace_ids[]=',
            'workspace_ids[]='.self::WORKSPACE_ID.'&workspace_ids[]='.self::WORKSPACE_ID,
            'workspace_ids[]='.str_repeat('w', 31),
            'api_key_ids='.self::KEY_ID,
            'api_key_ids[first]='.self::KEY_ID,
            'api_key_ids[0][]='.self::KEY_ID,
            'api_key_ids[1]='.self::KEY_ID,
            'api_key_ids[]=',
            'api_key_ids[]='.self::KEY_ID.'&api_key_ids[]='.self::KEY_ID,
            'api_key_ids[]='.str_repeat('k', 31),
            'page=1&page=2',
            'type=alpha&type=beta',
            'type=alpha&types[]=beta',
            'api_key_id='.self::KEY_ID.'&api_key_ids[]='.self::KEY_ID,
            'view=analytics&types[]=alpha',
            'view=analytics&type=alpha',
            'view=analytics&page=1',
            'view=analytics&per_page=25',
            'view=filters&api_key_id='.self::KEY_ID,
            'view=filters&chat_id='.self::CHAT_ID,
            'view=filters&page=1',
            'view=filters&types[]=alpha',
            'view=other',
            'page=0',
            'per_page=101',
            http_build_query(['types' => array_map(static fn (int $index): string => 'type.'.$index, range(0, 50))]),
            http_build_query(['workspace_ids' => array_map(static fn (int $index): string => 'wsp_'.$index, range(0, 50))]),
            http_build_query(['api_key_ids' => array_map(static fn (int $index): string => 'key_'.$index, range(0, 50))]),
        ];

        foreach ($queries as $query) {
            $fake = new FakeTransport;
            $strict = new StrictValidationTransport($fake, new UriResolver('https://example.test'));
            try {
                $strict->send(HttpRequest::get($this->url().'?'.$query));
                self::fail('Invalid query accepted: '.$query);
            } catch (\InvalidArgumentException) {
                self::assertSame([], $fake->requests);
            }
        }
    }

    public function test_only_empty_log_metadata_arrays_are_accepted(): void
    {
        foreach ([[], (object) [], ['unexpected'], 'invalid'] as $metadata) {
            $fake = new FakeTransport;
            $entry = [
                'id' => 'evt_1',
                'project_id' => self::PROJECT_ID,
                'api_key_id' => null,
                'actor_api_key_id' => null,
                'chat_id' => self::CHAT_ID,
                'workspace_id' => self::WORKSPACE_ID,
                'seq' => 0,
                'turn_id' => null,
                'type' => 'agent.started',
                'ts' => '2026-10-20T00:00:00Z',
                'agent' => $metadata,
                'data' => $metadata,
            ];
            $body = json_encode(['data' => [$entry], 'links' => (object) [], 'meta' => (object) []], JSON_THROW_ON_ERROR);
            $fake->queueResponse(new HttpResponse(200, [], $body));
            $strict = new StrictValidationTransport($fake, new UriResolver('https://example.test'));
            try {
                self::assertSame($body, $strict->send(HttpRequest::get($this->url()))->body);
                self::assertTrue($metadata === [] || $metadata instanceof \stdClass);
            } catch (ProtocolViolationException) {
                self::assertTrue($metadata === ['unexpected'] || $metadata === 'invalid');
            }
        }
    }

    private function url(): string
    {
        return 'https://example.test/api/v1/projects/'.self::PROJECT_ID.'/logs';
    }
}
