<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Unit\Exceptions;

use Digitalygo\Envai\Exceptions\AccessDeniedException;
use Digitalygo\Envai\Exceptions\ApiException;
use Digitalygo\Envai\Exceptions\AuthenticationException;
use Digitalygo\Envai\Exceptions\ConflictException;
use Digitalygo\Envai\Exceptions\DefinitionConflictException;
use Digitalygo\Envai\Exceptions\DefinitionDriftException;
use Digitalygo\Envai\Exceptions\DefinitionsNotSynchronizedException;
use Digitalygo\Envai\Exceptions\EnvaiException;
use Digitalygo\Envai\Exceptions\FileException;
use Digitalygo\Envai\Exceptions\NotFoundException;
use Digitalygo\Envai\Exceptions\OperationCancelledException;
use Digitalygo\Envai\Exceptions\ProtocolVersionException;
use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Exceptions\ProviderException;
use Digitalygo\Envai\Exceptions\RateLimitException;
use Digitalygo\Envai\Exceptions\ReplayGapException;
use Digitalygo\Envai\Exceptions\ServerException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Exceptions\UnsupportedCapabilityException;
use Digitalygo\Envai\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class ExceptionHierarchyTest extends TestCase
{
    public function test_envai_exception_extends_runtime_exception_and_is_abstract(): void
    {
        $reflection = new \ReflectionClass(EnvaiException::class);

        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue(is_subclass_of(EnvaiException::class, \RuntimeException::class));
    }

    public function test_api_exceptions_extend_api_exception(): void
    {
        $apiExceptions = [
            AuthenticationException::class,
            AccessDeniedException::class,
            NotFoundException::class,
            ConflictException::class,
            ValidationException::class,
            RateLimitException::class,
            ProviderException::class,
            FileException::class,
            ServerException::class,
        ];

        foreach ($apiExceptions as $class) {
            $this->assertTrue(is_subclass_of($class, ApiException::class), $class.' must extend ApiException');
            $this->assertTrue(is_subclass_of($class, EnvaiException::class), $class.' must extend EnvaiException');
        }
    }

    public function test_api_exception_is_not_final(): void
    {
        $reflection = new \ReflectionClass(ApiException::class);

        $this->assertFalse($reflection->isFinal());
    }

    public function test_concrete_api_exceptions_are_final(): void
    {
        foreach ([AuthenticationException::class, RateLimitException::class, ServerException::class] as $class) {
            $this->assertTrue((new \ReflectionClass($class))->isFinal(), $class.' must be final');
        }
    }

    public function test_top_level_exceptions_extend_envai_exception_directly(): void
    {
        $topLevel = [
            TransportException::class,
            ReplayGapException::class,
            UnsupportedCapabilityException::class,
            DefinitionsNotSynchronizedException::class,
            DefinitionConflictException::class,
            DefinitionDriftException::class,
            OperationCancelledException::class,
        ];

        foreach ($topLevel as $class) {
            $this->assertTrue(is_subclass_of($class, EnvaiException::class), $class.' must extend EnvaiException');
            $this->assertFalse(is_subclass_of($class, ApiException::class), $class.' must not extend ApiException');
            $this->assertTrue((new \ReflectionClass($class))->isFinal(), $class.' must be final');
        }

        // ProtocolViolationException must stay extensible: ProtocolVersionException derives from it.
        $this->assertFalse((new \ReflectionClass(ProtocolViolationException::class))->isFinal());
        $this->assertTrue((new \ReflectionClass(ProtocolVersionException::class))->isFinal());
    }

    public function test_protocol_version_exception_extends_protocol_violation_exception(): void
    {
        $this->assertTrue(is_subclass_of(ProtocolVersionException::class, ProtocolViolationException::class));
        $this->assertTrue(is_subclass_of(ProtocolVersionException::class, EnvaiException::class));
    }

    public function test_replay_gap_exposes_missing_bounds(): void
    {
        $exception = new ReplayGapException('Replay window gap detected', 12, 15);

        $this->assertSame(12, $exception->missingFrom);
        $this->assertSame(15, $exception->missingTo);
        $this->assertInstanceOf(EnvaiException::class, $exception);
    }

    public function test_top_level_exceptions_can_carry_request_id_context(): void
    {
        $exception = new TransportException('Connection refused', ['request_id' => 'req_xyz']);

        $this->assertSame('req_xyz', $exception->requestId());
        $this->assertSame(['request_id' => 'req_xyz'], $exception->context());
    }
}
