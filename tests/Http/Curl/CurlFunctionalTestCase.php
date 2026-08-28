<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Curl;

use PHPUnit\Framework\TestCase;

/**
 * Base class for curl functional tests.
 *
 * A TestHttpServer runs in a forked child process while the test exercises
 * the transport in the parent. After the client finishes, the parent waits
 * for the child and asserts on the JSON summary of what the server observed,
 * which keeps server-side parsing deterministic and offline.
 */
abstract class CurlFunctionalTestCase extends TestCase
{
    /** @var list<int> */
    private array $childPids = [];

    protected function tearDown(): void
    {
        foreach ($this->childPids as $pid) {
            $reaped = pcntl_waitpid($pid, $status, WNOHANG);

            if ($reaped === 0) {
                @posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }

        $this->childPids = [];

        parent::tearDown();
    }

    /**
     * Forks a test server, runs the client against it and returns the server
     * summary. The client closure runs in the parent; the handler closure runs
     * in the child.
     *
     * @param  callable(string $request, TestHttpServer $server): array<string, mixed>  $handler
     * @param  callable(string $baseUrl): void  $client
     * @return array<string, mixed>
     */
    protected function withServer(callable $handler, callable $client): array
    {
        $server = new TestHttpServer;
        $summaryFile = tempnam(sys_get_temp_dir(), 'atp-curl-test-');

        if ($summaryFile === false) {
            throw new \RuntimeException('Unable to create the server summary file.');
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new \RuntimeException('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            fclose(STDOUT);
            fclose(STDERR);
            $server->serve($handler, $summaryFile);
            exit($server->error() === null ? 0 : 1);
        }

        $this->childPids[] = $pid;

        try {
            $client($server->url());
        } finally {
            pcntl_waitpid($pid, $status);
            $this->childPids = array_values(array_diff($this->childPids, [$pid]));
        }

        $exitCode = pcntl_wexitstatus($status);
        $summary = @file_get_contents($summaryFile);
        @unlink($summaryFile);

        if ($exitCode !== 0 || $summary === false) {
            $this->fail(sprintf(
                'The test HTTP server worker failed (exit %s): %s',
                var_export($exitCode, true),
                $summary === false ? '(no summary)' : $summary,
            ));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($summary, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
