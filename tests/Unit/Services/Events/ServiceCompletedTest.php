<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Events;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Events\ServiceCompleted;
use SineMacula\ApiToolkit\Services\ServiceResult;
use Tests\Fixtures\Services\StubActor;

/**
 * Tests for the ServiceCompleted event payload.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceCompleted::class)]
final class ServiceCompletedTest extends TestCase
{
    /**
     * Test all six properties are readable; inputSummary defaults to [].
     *
     * @return void
     */
    public function testExposesPayload(): void
    {
        $actor         = new StubActor;
        $service       = 'App\Services\ExampleService';
        $result        = ServiceResult::success(['id' => 1]);
        $duration      = 0.042;
        $correlationId = 'corr-abc-123';

        $event = new ServiceCompleted($actor, $service, $result, $duration, $correlationId);

        self::assertSame($actor, $event->actor);
        self::assertSame($service, $event->service);
        self::assertSame($result, $event->result);
        self::assertSame($duration, $event->duration);
        self::assertSame($correlationId, $event->correlationId);
        self::assertSame([], $event->inputSummary);
    }

    /**
     * Test that inputSummary is stored when explicitly provided.
     *
     * @return void
     */
    public function testAcceptsInputSummary(): void
    {
        $actor        = new StubActor;
        $inputSummary = ['name' => 'Alice', 'age' => 30];

        $event = new ServiceCompleted(
            $actor,
            'App\Services\ExampleService',
            ServiceResult::success(),
            0.1,
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
        $reflection = new \ReflectionClass(ServiceCompleted::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }
}
