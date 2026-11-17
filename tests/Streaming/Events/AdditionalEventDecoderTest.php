<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Streaming\Events;

use Digitalygo\Envai\Streaming\Atp\AtpEventDecoder;
use Digitalygo\Envai\Streaming\Atp\TurnAwait;
use Digitalygo\Envai\Streaming\Events\BillingCreditExhaustedEvent;
use Digitalygo\Envai\Streaming\Events\BillingLimitReachedEvent;
use Digitalygo\Envai\Streaming\Events\ChatCronWakeupEvent;
use Digitalygo\Envai\Streaming\Events\ChatTitleUpdatedEvent;
use Digitalygo\Envai\Streaming\Events\ProtocolViolationEvent;
use Digitalygo\Envai\Streaming\Events\UnknownEvent;
use Digitalygo\Envai\Tests\Streaming\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdditionalEventDecoderTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $agent
     */
    private static function decode(string $type, array $data, ?string $turn = Fixtures::TURN_ID, int $version = 3, ?array $agent = null): object
    {
        return (new AtpEventDecoder)->decode(json_encode(['v' => $version, 'id' => 'evt', 'seq' => 1, 'ts' => '2026-01-01T00:00:00Z', 'chat_id' => 'chat', 'turn_id' => $turn, 'agent' => $agent, 'type' => $type, 'data' => (object) $data], JSON_THROW_ON_ERROR));
    }

    public function test_new_types_and_constructor_fields(): void
    {
        $title = self::decode('chat.title.updated', ['title' => str_repeat('a', 255), 'future' => 7]);
        self::assertInstanceOf(ChatTitleUpdatedEvent::class, $title);
        self::assertSame(str_repeat('a', 255), $title->title);
        self::assertSame(['future' => 7], $title->extra);
        self::assertSame(Fixtures::TURN_ID, $title->envelope->turnId);
        self::assertNull($title->envelope->agent);

        $cronData = self::cron() + ['future' => ['nested' => true]];
        $cron = self::decode('chat.cron.wakeup', $cronData);
        self::assertInstanceOf(ChatCronWakeupEvent::class, $cron);
        self::assertSame($cronData['cron_id'], $cron->cronId);
        self::assertSame($cronData['occurrence_id'], $cron->occurrenceId);
        self::assertSame($cronData['scheduled_at'], $cron->scheduledAt);
        self::assertSame($cronData['delivered_at'], $cron->deliveredAt);
        self::assertSame($cronData['prompt'], $cron->prompt);
        self::assertSame('same_chat', $cron->targetMode);
        self::assertSame('turn_injected', $cron->dispatchMode);
        self::assertSame(['future' => ['nested' => true]], $cron->extra);
        self::assertSame(Fixtures::TURN_ID, $cron->envelope->turnId);
        self::assertNull($cron->envelope->agent);

        $limitData = self::limit() + ['future' => 'keep'];
        $limit = self::decode('billing.limit_reached', $limitData);
        self::assertInstanceOf(BillingLimitReachedEvent::class, $limit);
        self::assertSame('limit_reserved', $limit->reason);
        self::assertSame('api_key', $limit->scope);
        self::assertSame('EUR', $limit->currency);
        self::assertSame('2026-12-01T00:00:00+00:00', $limit->resetAt);
        self::assertSame('1.000000000001', $limit->amount);
        self::assertSame('0.000000000000', $limit->remaining);
        self::assertSame(['future' => 'keep'], $limit->extra);
        self::assertSame(Fixtures::TURN_ID, $limit->envelope->turnId);
        self::assertNull($limit->envelope->agent);

        $creditData = self::credit() + ['future' => ['keep' => 1]];
        $credit = self::decode('billing.credit_exhausted', $creditData);
        self::assertInstanceOf(BillingCreditExhaustedEvent::class, $credit);
        self::assertSame('credit_insufficient', $credit->reason);
        self::assertSame('company', $credit->scope);
        self::assertSame('EUR', $credit->currency);
        self::assertSame(['future' => ['keep' => 1]], $credit->extra);
        self::assertSame(Fixtures::TURN_ID, $credit->envelope->turnId);
        self::assertNull($credit->envelope->agent);

        $await = new TurnAwait(Fixtures::TURN_ID);
        foreach ([$title, $cron, $limit, $credit] as $event) {
            self::assertFalse($await->push($event));
        }

        self::assertSame('chat.title.updated', ChatTitleUpdatedEvent::type());
        self::assertSame('chat.cron.wakeup', ChatCronWakeupEvent::type());
        self::assertSame('billing.limit_reached', BillingLimitReachedEvent::type());
        self::assertSame('billing.credit_exhausted', BillingCreditExhaustedEvent::type());
        self::assertInstanceOf(UnknownEvent::class, self::decode('future.event', ['new' => 1]));
        self::assertInstanceOf(ChatCronWakeupEvent::class, self::decode('chat.cron.wakeup', self::cron(['prompt' => str_repeat('a', 16000)])));
    }

    /** @return iterable<string, array{string, array<string, mixed>, class-string}> */
    public static function billingTypesProvider(): iterable
    {
        yield 'limit' => ['billing.limit_reached', self::limit(), BillingLimitReachedEvent::class];
        yield 'credit' => ['billing.credit_exhausted', self::credit(), BillingCreditExhaustedEvent::class];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  class-string  $class
     */
    #[DataProvider('billingTypesProvider')]
    public function test_billing_events_allow_null_turn_before_admission_and_real_turn_midturn(string $type, array $data, string $class): void
    {
        $await = new TurnAwait(Fixtures::TURN_ID);

        foreach ([null, Fixtures::TURN_ID] as $turn) {
            $event = self::decode($type, $data, $turn);
            self::assertInstanceOf($class, $event);
            self::assertSame($turn, $event->envelope->turnId);
            self::assertNull($event->envelope->agent);
            self::assertFalse($await->push($event));
        }
    }

    public function test_v2_title_is_the_only_additional_event(): void
    {
        $decoder = new AtpEventDecoder;
        $title = $decoder->decode(json_encode(self::v2Wire('chat.title.updated', ['title' => 'Title']), JSON_THROW_ON_ERROR));
        self::assertInstanceOf(ChatTitleUpdatedEvent::class, $title);

        foreach ([
            ['chat.cron.wakeup', self::cron()],
            ['billing.limit_reached', self::limit()],
            ['billing.credit_exhausted', self::credit()],
        ] as [$type, $data]) {
            $event = $decoder->decode(json_encode(self::v2Wire($type, $data), JSON_THROW_ON_ERROR));
            self::assertInstanceOf(ProtocolViolationEvent::class, $event, $type);
            self::assertNotEmpty($event->violations, $type);
            if ($type === 'chat.cron.wakeup') {
                self::assertContains('Event requires ATP v3.', $event->violations);
            }
        }
    }

    public function test_invalid_payloads_and_turns(): void
    {
        foreach (['', "a\nb", str_repeat('a', 256)] as $title) {
            self::assertInstanceOf(ProtocolViolationEvent::class, self::decode('chat.title.updated', ['title' => $title]));
        }
        self::assertInstanceOf(ProtocolViolationEvent::class, self::decode('chat.title.updated', ['title' => 'valid'], null));
        self::assertInstanceOf(ProtocolViolationEvent::class, self::decode('chat.cron.wakeup', self::cron(), null));

        foreach ([
            ['prompt', '  '],
            ['prompt', str_repeat('a', 16001)],
            ['cron_id', 'bad'],
            ['occurrence_id', 'bad'],
            ['scheduled_at', 'bad'],
            ['scheduled_at', '2026-02-30T00:00:00Z'],
            ['delivered_at', 'bad'],
            ['delivered_at', '2026-01-01T00:00:00+99:99'],
            ['target_mode', 'bad'],
            ['dispatch_mode', 'bad'],
        ] as [$field, $value]) {
            self::assertInstanceOf(ProtocolViolationEvent::class, self::decode('chat.cron.wakeup', self::cron([$field => $value])), $field);
        }
        self::assertInstanceOf(ProtocolViolationEvent::class, self::decode('chat.cron.wakeup', []));

        foreach ([
            ['reason' => 'wrong', 'scope' => 'project', 'currency' => 'EUR'],
            ['reason' => 'limit_reached', 'scope' => 'company', 'currency' => 'EUR'],
            ['reason' => 'limit_reached', 'scope' => 'project', 'currency' => 'eur'],
            ['reason' => 'limit_reached', 'scope' => 'project', 'currency' => 'EU'],
            ['reason' => 'limit_reached', 'scope' => 'project', 'currency' => 'EUR', 'amount' => 1],
            ['reason' => 'limit_reached', 'scope' => 'project', 'currency' => 'EUR', 'amount' => null],
            ['reason' => 'limit_reached', 'scope' => 'project', 'currency' => 'EUR', 'amount' => '1.2.3'],
            ['reason' => 'limit_reached', 'scope' => 'project', 'currency' => 'EUR', 'remaining' => '-1'],
            ['reason' => 'limit_reached', 'scope' => 'project', 'currency' => 'EUR', 'remaining' => null],
            ['reason' => 'limit_reached', 'scope' => 'project', 'currency' => 'EUR', 'reset_at' => 'bad'],
            ['reason' => 'limit_reached', 'scope' => 'project', 'currency' => 'EUR', 'reset_at' => '2026-02-30T00:00:00Z'],
            ['reason' => 'limit_reached', 'scope' => 'project', 'currency' => 'EUR', 'reset_at' => '2026-01-01T00:00:00+99:99'],
            [],
        ] as $data) {
            self::assertInstanceOf(ProtocolViolationEvent::class, self::decode('billing.limit_reached', $data));
        }

        foreach ([
            ['reason' => 'wrong', 'scope' => 'company', 'currency' => 'EUR'],
            ['reason' => 'credit_exhausted', 'scope' => 'project', 'currency' => 'EUR'],
            ['reason' => 'credit_exhausted', 'scope' => 'company', 'currency' => 'eur'],
            ['reason' => 'credit_exhausted', 'scope' => 'company', 'currency' => 'EU'],
            ['reason' => 'credit_exhausted', 'scope' => 'company', 'currency' => 978],
            [],
        ] as $data) {
            self::assertInstanceOf(ProtocolViolationEvent::class, self::decode('billing.credit_exhausted', $data));
        }

        foreach ([
            ['chat.title.updated', ['title' => 'valid']],
            ['chat.cron.wakeup', self::cron()],
            ['billing.limit_reached', self::limit()],
            ['billing.credit_exhausted', self::credit()],
        ] as [$type, $data]) {
            self::assertInstanceOf(ProtocolViolationEvent::class, self::decode($type, $data, agent: Fixtures::orchestrator()), $type);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function cron(array $overrides = []): array
    {
        return array_replace([
            'cron_id' => 'cron_01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'occurrence_id' => 'occ_01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'scheduled_at' => '2026-01-01T00:00:00Z',
            'delivered_at' => '2026-01-01T00:01:00Z',
            'prompt' => 'Do it',
            'target_mode' => 'same_chat',
            'dispatch_mode' => 'turn_injected',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function limit(array $overrides = []): array
    {
        return array_replace([
            'reason' => 'limit_reserved',
            'scope' => 'api_key',
            'currency' => 'EUR',
            'reset_at' => '2026-12-01T00:00:00+00:00',
            'amount' => '1.000000000001',
            'remaining' => '0.000000000000',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function credit(array $overrides = []): array
    {
        return array_replace([
            'reason' => 'credit_insufficient',
            'scope' => 'company',
            'currency' => 'EUR',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function v2Wire(string $type, array $data, ?string $turn = 'cmpl_01ARZ3NDEKTSV4RRFFQ69G5FAV'): array
    {
        return [
            'v' => 2,
            'id' => 'evt_01ARZ3NDEKTSV4RRFFQ69G5FA0',
            'seq' => 1,
            'ts' => '2026-10-12T22:00:00Z',
            'chat_id' => 'cht_01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'turn_id' => $turn,
            'agent' => null,
            'type' => $type,
            'data' => $data,
        ];
    }
}
