<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Events;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Events\ServiceFailed;
use SineMacula\ApiToolkit\Services\ServiceResult;
use Tests\Fixtures\Services\StubActor;

/**
 * Tests for the ServiceFailed event payload.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceFailed::class)]
final class ServiceFailedTest extends TestCase
{
    /**
     * Test that the result carries the failure exception as the cause.
     *
     * @return void
     */
    public function testCarriesFailureResult(): void
    {
        $actor         = new StubActor;
        $service       = 'App\Services\ExampleService';
        $cause         = new \RuntimeException('something went wrong');
        $result        = ServiceResult::failure($cause);
        $duration      = 0.007;
        $correlationId = 'corr-fail-456';

        $event = new ServiceFailed($actor, $service, $result, $duration, $correlationId);

        self::assertSame($actor, $event->actor);
        self::assertSame($service, $event->service);
        self::assertSame($result, $event->result);
        self::assertSame($duration, $event->duration);
        self::assertSame($correlationId, $event->correlationId);
        self::assertSame([], $event->inputSummary);
        self::assertSame($cause, $event->result->exception);
    }

    /**
     * Test that inputSummary is stored when explicitly provided.
     *
     * @return void
     */
    public function testAcceptsInputSummary(): void
    {
        $actor        = new StubActor;
        $inputSummary = ['user_id' => 99];

        $event = new ServiceFailed(
            $actor,
            'App\Services\ExampleService',
            ServiceResult::failure(),
            0.05,
            'corr-xyz',
            $inputSummary,
        );

        self::assertSame($inputSummary, $event->inputSummary);
    }

    /**
     * Test that the event class is final and readonly (immutable payload).
     *
     * @return void
     */
    public function testIsImmutable(): void
    {
        $reflection = new \ReflectionClass(ServiceFailed::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }
}
