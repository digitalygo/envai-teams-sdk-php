<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Support;

use Digitalygo\Envai\Tools\Execution\ToolOutputSender;

/**
 * ToolOutputSender fake recording every submission attempt.
 *
 * `attempts` counts every submit() call (including those that throw), so
 * tests can assert the "no blind retry" guarantee; `throw` makes submit()
 * fail while still counting the attempt.
 */
final class RecordingToolOutputSender implements ToolOutputSender
{
    public int $attempts = 0;

    /**
     * @var list<array{chatId: string, callId: string, output: string, isError: bool}>
     */
    public array $submissions = [];

    public ?\Throwable $throw = null;

    public function submit(string $chatId, string $callId, string $output, bool $isError): void
    {
        $this->attempts++;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        $this->submissions[] = ['chatId' => $chatId, 'callId' => $callId, 'output' => $output, 'isError' => $isError];
    }

    /**
     * @return array{chatId: string, callId: string, output: string, isError: bool}|null
     */
    public function byCallId(string $callId): ?array
    {
        foreach (array_reverse($this->submissions) as $submission) {
            if ($submission['callId'] === $callId) {
                return $submission;
            }
        }

        return null;
    }
}
