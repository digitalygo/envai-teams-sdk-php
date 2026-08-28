<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Streaming\Sse;

use Digitalygo\Envai\Streaming\Sse\SseFrame;
use Digitalygo\Envai\Streaming\Sse\SseParser;
use PHPUnit\Framework\TestCase;

final class SseParserTest extends TestCase
{
    public function test_single_frame_with_lf(): void
    {
        $parser = new SseParser;
        $parser->feed("data: hello\n\n");

        $frame = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $frame);
        $this->assertNull($frame->eventType);
        $this->assertSame('hello', $frame->data);
        $this->assertNull($parser->nextFrame());
    }

    public function test_single_frame_with_crlf(): void
    {
        $parser = new SseParser;
        $parser->feed("data: hello\r\n\r\n");

        $frame = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $frame);
        $this->assertSame('hello', $frame->data);
    }

    public function test_single_frame_with_bare_cr(): void
    {
        $parser = new SseParser;
        $parser->feed("data: hello\r\r");
        $this->assertNull($parser->nextFrame()); // trailing \r may be the start of \r\n
        $parser->feed("data: x\n\n");

        $this->assertSame('hello', $parser->nextFrame()->data);
        $this->assertSame('x', $parser->nextFrame()->data);
    }

    public function test_event_field_is_carried_and_data_is_empty_string(): void
    {
        $parser = new SseParser;
        $parser->feed("event: turn.started\n\n");

        $frame = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $frame);
        $this->assertSame('turn.started', $frame->eventType);
        $this->assertSame('', $frame->data);
    }

    public function test_multiple_data_lines_are_joined_with_newline_in_order(): void
    {
        $parser = new SseParser;
        $parser->feed("data: a\ndata: b\ndata: c\n\n");

        $frame = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $frame);
        $this->assertSame("a\nb\nc", $frame->data);
    }

    public function test_empty_data_line_appends_an_empty_fragment(): void
    {
        $parser = new SseParser;
        $parser->feed("data: a\ndata:\ndata: b\n\n");

        $frame = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $frame);
        $this->assertSame("a\n\nb", $frame->data);
    }

    public function test_comment_lines_are_ignored(): void
    {
        $parser = new SseParser;
        $parser->feed(": keep-alive comment\n:another\ndata: x\n\n");

        $frame = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $frame);
        $this->assertSame('x', $frame->data);
    }

    public function test_unknown_fields_are_ignored(): void
    {
        $parser = new SseParser;
        $parser->feed("id: evt_123\nretry: 1000\nx-custom: whatever\ndata: x\n\n");

        $frame = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $frame);
        $this->assertSame('x', $frame->data);
    }

    public function test_single_leading_space_after_colon_is_stripped(): void
    {
        $parser = new SseParser;
        $parser->feed("data:  two spaces\n\ndata: one\n\n");

        $first = $parser->nextFrame();
        $second = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $first);
        $this->assertSame(' two spaces', $first->data);
        $this->assertInstanceOf(SseFrame::class, $second);
        $this->assertSame('one', $second->data);
    }

    public function test_field_without_colon_has_empty_value(): void
    {
        $parser = new SseParser;
        $parser->feed("data\n\n");

        $frame = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $frame);
        $this->assertSame('', $frame->data);
    }

    public function test_multiple_event_lines_keep_the_last_one(): void
    {
        $parser = new SseParser;
        $parser->feed("event: first\nevent: second\ndata: x\n\n");

        $frame = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $frame);
        $this->assertSame('second', $frame->eventType);
        $this->assertSame('x', $frame->data);
    }

    public function test_fragmented_chunk_mid_line(): void
    {
        $parser = new SseParser;
        $parser->feed('data: he');
        $this->assertNull($parser->nextFrame());
        $parser->feed("llo\n\n");

        $frame = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $frame);
        $this->assertSame('hello', $frame->data);
    }

    public function test_utf8_sequence_split_across_chunks(): void
    {
        $parser = new SseParser;
        $parser->feed("data: caff\xC3");
        $this->assertNull($parser->nextFrame());
        $parser->feed("\xA8\n\n");

        $frame = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $frame);
        $this->assertSame('caffè', $frame->data);
    }

    public function test_multiple_frames_in_one_chunk(): void
    {
        $parser = new SseParser;
        $parser->feed("data: a\n\ndata: b\n\ndata: c\n\n");

        $this->assertSame('a', $parser->nextFrame()->data);
        $this->assertSame('b', $parser->nextFrame()->data);
        $this->assertSame('c', $parser->nextFrame()->data);
        $this->assertNull($parser->nextFrame());
    }

    public function test_frames_split_across_chunks(): void
    {
        $parser = new SseParser;
        $parser->feed("data: a\n");
        $this->assertNull($parser->nextFrame());
        $parser->feed("\ndata: b\n");
        $parser->feed("\n");

        $this->assertSame('a', $parser->nextFrame()->data);
        $this->assertSame('b', $parser->nextFrame()->data);
        $this->assertNull($parser->nextFrame());
    }

    public function test_crlf_split_across_chunks_is_reassembled(): void
    {
        $parser = new SseParser;
        $parser->feed("data: x\r");
        $this->assertNull($parser->nextFrame());
        $parser->feed("\n\n");

        $this->assertSame('x', $parser->nextFrame()->data);

        $parser->feed("data: y\n\n");

        $this->assertSame('y', $parser->nextFrame()->data);
        $this->assertNull($parser->nextFrame());
    }

    public function test_no_trailing_newline_is_never_flushed(): void
    {
        $parser = new SseParser;
        $parser->feed('data: incomplete');
        $this->assertNull($parser->nextFrame());

        $parser->feed('data: still incomplete');
        $this->assertNull($parser->nextFrame());
    }

    public function test_utf8_bom_at_start_is_tolerated(): void
    {
        $parser = new SseParser;
        $parser->feed("\xEF\xBB\xBFdata: x\n\n");

        $frame = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $frame);
        $this->assertSame('x', $frame->data);
    }

    public function test_utf8_bom_split_across_first_chunks_is_tolerated(): void
    {
        $parser = new SseParser;
        $parser->feed("\xEF\xBB");
        $this->assertNull($parser->nextFrame());
        $parser->feed("\xBFdata: x\n\n");

        $frame = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $frame);
        $this->assertSame('x', $frame->data);
    }

    public function test_stray_blank_lines_do_not_produce_frames(): void
    {
        $parser = new SseParser;
        $parser->feed("\n\ndata: x\n\n\n");

        $frame = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $frame);
        $this->assertSame('x', $frame->data);
        $this->assertNull($parser->nextFrame());
    }

    public function test_empty_data_field_still_dispatches_a_frame(): void
    {
        $parser = new SseParser;
        $parser->feed("data:\n\n");

        $frame = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $frame);
        $this->assertNull($frame->eventType);
        $this->assertSame('', $frame->data);
    }

    public function test_event_and_data_together(): void
    {
        $parser = new SseParser;
        $parser->feed("event: agent.text.delta\ndata: {\"delta\":\"ciao\"}\n\n");

        $frame = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $frame);
        $this->assertSame('agent.text.delta', $frame->eventType);
        $this->assertSame('{"delta":"ciao"}', $frame->data);
    }

    public function test_empty_feed_and_empty_buffer_yield_no_frame(): void
    {
        $parser = new SseParser;

        $this->assertNull($parser->nextFrame());
        $parser->feed('');
        $this->assertNull($parser->nextFrame());
    }

    public function test_state_resets_between_frames(): void
    {
        $parser = new SseParser;
        $parser->feed("event: first\ndata: a\n\ndata: b\n\n");

        $first = $parser->nextFrame();
        $second = $parser->nextFrame();

        $this->assertInstanceOf(SseFrame::class, $first);
        $this->assertSame('first', $first->eventType);
        $this->assertSame('a', $first->data);

        $this->assertInstanceOf(SseFrame::class, $second);
        $this->assertNull($second->eventType);
        $this->assertSame('b', $second->data);
    }

    public function test_one_byte_at_a_time(): void
    {
        $parser = new SseParser;

        foreach (str_split("data: abc\n\ndata: def\n\n") as $byte) {
            $parser->feed($byte);
        }

        $this->assertSame('abc', $parser->nextFrame()->data);
        $this->assertSame('def', $parser->nextFrame()->data);
        $this->assertNull($parser->nextFrame());
    }
}
