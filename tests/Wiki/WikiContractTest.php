<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Wiki;

use Digitalygo\Envai\Models\Usage\BillingState;
use Digitalygo\Envai\Models\Usage\CurrencyAmount;
use Digitalygo\Envai\Models\Usage\UsageCost;
use Digitalygo\Envai\Models\Wiki\Categories;
use Digitalygo\Envai\Models\Wiki\CategoryIndex;
use Digitalygo\Envai\Models\Wiki\WikiOperation;
use Digitalygo\Envai\Models\Wiki\WikiSource;
use Digitalygo\Envai\Webhooks\WebhookPayload;
use Digitalygo\Envai\Webhooks\Wiki\WikiWebhookEvent;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WikiContractTest extends TestCase
{
    public static function fixture(string $name): array
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/fixtures/wiki/'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function cases(): iterable
    {
        foreach (glob(dirname(__DIR__).'/fixtures/wiki/*.json') as $path) {
            if (basename($path) !== 'webhook-signature.json') {
                yield basename($path) => [basename($path, '.json')];
            }
        }
    }

    #[DataProvider('cases')]
    public function test_shared_schema_and_typed_models_roundtrip(string $name): void
    {
        $fixture = self::fixture($name);
        $schema = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/wiki.json'));
        $schema->{'$ref'} = '#/$defs/'.$fixture['schema'];
        $result = (new Validator)->validate(json_decode(json_encode($fixture['data'])), $schema);
        self::assertTrue($result->isValid(), json_encode($result->error()));
        $classes = ['UsageCost' => UsageCost::class, 'WikiSource' => WikiSource::class, 'WikiOperation' => WikiOperation::class, 'Categories' => Categories::class, 'CategoryIndex' => CategoryIndex::class];
        $data = $fixture['data'];
        $data['future_field'] = ['preserved' => 1];
        if (isset($classes[$fixture['schema']])) {
            $model = $classes[$fixture['schema']]::fromArray($data);
            self::assertEquals($data, $model->toArray());
        } else {
            $event = WikiWebhookEvent::fromPayload(WebhookPayload::fromJson(json_encode($data)));
            self::assertSame($data, $event->payload->toArray());
            self::assertSame($data['event_id'], $event->eventId);
            self::assertSame($data['file_id'] ?? null, $event->fileId);
            self::assertSame($data['status'] ?? null, $event->status);
        }
    }

    public function test_empty_operation_phase_map_retains_json_object_shape(): void
    {
        $data = self::fixture('operation-completed')['data'];
        $data['phases'] = [];
        $model = WikiOperation::fromArray($data);
        self::assertSame([], $model->phases);
        self::assertInstanceOf(\stdClass::class, json_decode(json_encode($model->toArray()))->phases);
    }

    public function test_pending_cost_never_becomes_zero_and_currencies_are_preserved(): void
    {
        $usage = self::fixture('usage-pending')['data'];
        $usage['priced_subtotals'][] = ['currency' => 'EUR', 'amount' => '0.000000012345'];
        $model = UsageCost::fromArray($usage);
        self::assertNull($model->totals);
        self::assertSame('0.000000012345', $model->pricedSubtotals[1]->amount);
        self::assertEquals($usage, $model->toArray());
        $empty = UsageCost::fromArray(self::fixture('usage-empty')['data']);
        self::assertSame([], $empty->totals);
        self::assertSame(0, $empty->invocationCount);
        $usage['status'] = 'complete';
        $usage['pending_invocation_count'] = 0;
        $usage['totals'] = $usage['priced_subtotals'];
        self::assertEquals($usage, UsageCost::fromArray($usage)->toArray());
    }

    public static function invalidUsage(): iterable
    {
        yield 'pending final total' => [['totals' => []]];
        yield 'invalid status' => [['status' => 'charged']];
        yield 'invalid basis' => [['basis' => 'guess']];
        yield 'invalid scope' => [['scope_status' => 'settled']];
        yield 'negative revision' => [['revision' => -1]];
        yield 'negative count' => [['invocation_count' => -1]];
        yield 'excess unknown' => [['pending_invocation_count' => 10]];
        yield 'premature complete' => [['status' => 'complete']];
        yield 'not list' => [['priced_subtotals' => ['bad' => []]]];
        yield 'not object item' => [['priced_subtotals' => [1]]];
        yield 'totals not list' => [['totals' => ['bad' => []]]];
        yield 'totals not object' => [['totals' => [1]]];
    }

    #[DataProvider('invalidUsage')]
    public function test_invalid_usage_is_rejected(array $changes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UsageCost::fromArray(array_replace(self::fixture('usage-pending')['data'], $changes));
    }

    public function test_invalid_money_is_rejected(): void
    {
        foreach ([['usd', '0.01'], ['USD', 'NaN'], ['USD', '1e-8'], ['USD', '-1']] as [$currency, $amount]) {
            try {
                new CurrencyAmount($currency, $amount);
                self::fail('Invalid money accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new BillingState('charged');
    }

    public function test_empty_index_ranges_and_list_validation(): void
    {
        $data = self::fixture('category-index')['data'];
        $data['entries'] = [];
        $data['actual_range'] = null;
        $data['requested_range'] = null;
        $data['returned_count'] = 0;
        self::assertSame($data, CategoryIndex::fromArray($data)->toArray());
        foreach ([['entries' => 2], ['entries' => [null]]] as $change) {
            try {
                CategoryIndex::fromArray(array_replace($data, $change));
                self::fail('Invalid index accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach ([['categories' => 'bad'], ['categories' => [null]]] as $change) {
            try {
                Categories::fromArray(array_replace(self::fixture('categories')['data'], $change));
                self::fail('Invalid category list accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach ([['phases' => 'bad'], ['phases' => [null]], ['phases' => [[]]], ['phases' => ['inbox' => array_replace(self::fixture('operation-completed')['data']['phases']['inbox'], ['files' => 1])]], ['phases' => ['inbox' => array_replace(self::fixture('operation-completed')['data']['phases']['inbox'], ['files' => [null]])]]] as $change) {
            try {
                WikiOperation::fromArray(array_replace(self::fixture('operation-completed')['data'], $change));
                self::fail('Invalid phase accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
