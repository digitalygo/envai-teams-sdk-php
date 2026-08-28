<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\State;

use Digitalygo\Envai\Tools\State\ClientToolCallStatus;
use Digitalygo\Envai\Tools\State\InMemoryClientToolCallStore;
use Digitalygo\Envai\Tools\State\ToolCallIdentity;
use Digitalygo\Envai\Tools\State\ToolCallStatus;
use PHPUnit\Framework\TestCase;

final class InMemoryClientToolCallStoreTest extends TestCase
{
    private InMemoryClientToolCallStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryClientToolCallStore;
    }

    private function identity(string $callId = 'call_01', string $chatId = 'cht_01', ?string $turnId = 'cmpl_01'): ToolCallIdentity
    {
        return new ToolCallIdentity($chatId, $callId, 'lookup_order', $turnId);
    }

    public function test_begin_claims_a_new_call_with_started_status(): void
    {
        $begin = $this->store->begin($this->identity());

        $this->assertSame(ToolCallStatus::Started, $begin->status);
        $this->assertNull($begin->previous);

        $records = $this->store->records();
        $this->assertCount(1, $records);
        $this->assertSame(ClientToolCallStatus::Started, $records[0]->status);
        $this->assertSame('lookup_order', $records[0]->identity->toolName);
        $this->assertNotNull($records[0]->updatedAt);
    }

    public function test_begin_twice_reports_already_running(): void
    {
        $this->store->begin($this->identity());
        $begin = $this->store->begin($this->identity());

        $this->assertSame(ToolCallStatus::AlreadyRunning, $begin->status);
        $this->assertSame(ClientToolCallStatus::Started, $begin->previous?->status);
    }

    public function test_begin_after_submitted_reports_already_running(): void
    {
        $identity = $this->identity();
        $this->store->begin($identity);
        $this->store->markSubmitted($identity);

        $begin = $this->store->begin($identity);

        $this->assertSame(ToolCallStatus::AlreadyRunning, $begin->status);
        $this->assertSame(ClientToolCallStatus::Submitted, $begin->previous?->status);
    }

    public function test_begin_after_resolved_reports_already_resolved(): void
    {
        $identity = $this->identity();
        $this->store->begin($identity);
        $this->store->markSubmitted($identity);
        $this->store->markResolved($identity);

        $begin = $this->store->begin($identity);

        $this->assertSame(ToolCallStatus::AlreadyResolved, $begin->status);
        $this->assertSame(ClientToolCallStatus::Resolved, $begin->previous?->status);
    }

    public function test_begin_after_ambiguous_reports_already_ambiguous(): void
    {
        $identity = $this->identity();
        $this->store->begin($identity);
        $this->store->markAmbiguous($identity);

        $begin = $this->store->begin($identity);

        $this->assertSame(ToolCallStatus::AlreadyAmbiguous, $begin->status);
        $this->assertSame(ClientToolCallStatus::Ambiguous, $begin->previous?->status);
    }

    public function test_marks_advance_the_lifecycle_and_timestamp(): void
    {
        $identity = $this->identity();
        $this->store->begin($identity);
        $this->store->markSubmitted($identity);
        $this->store->markResolved($identity);

        $record = $this->store->records()[0];

        $this->assertSame(ClientToolCallStatus::Resolved, $record->status);
        $this->assertNotNull($record->updatedAt);
    }

    public function test_marks_require_an_existing_record(): void
    {
        $this->expectException(\LogicException::class);

        $this->store->markResolved($this->identity());
    }

    public function test_calls_are_keyed_by_chat_and_call_id(): void
    {
        $this->store->begin($this->identity(callId: 'call_01', chatId: 'cht_01'));
        $this->store->begin($this->identity(callId: 'call_01', chatId: 'cht_02'));
        $this->store->begin($this->identity(callId: 'call_02', chatId: 'cht_01'));

        $this->assertCount(3, $this->store->records());

        $begin = $this->store->begin($this->identity(callId: 'call_01', chatId: 'cht_01'));
        $this->assertSame(ToolCallStatus::AlreadyRunning, $begin->status);
    }

    public function test_turn_id_is_preserved_on_the_record(): void
    {
        $this->store->begin($this->identity(turnId: null));

        $record = $this->store->records()[0];

        $this->assertNull($record->identity->turnId);
    }
}
