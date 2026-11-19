<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Events;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Events\ServiceEvent;
use SineMacula\ApiToolkit\Services\ServiceResult;
use Tests\Fixtures\Services\SimpleService;
use Tests\Fixtures\Services\StubActor;

/**
 * Tests for the shared service lifecycle event payload.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceEvent::class)]
final class ServiceEventTest extends TestCase
{
    /**
     * Test that every lifecycle event exposes the payload it was raised with.
     *
     * @return void
     */
    public function testExposesThePayload(): void
    {
        $actor  = new StubActor;
        $result = ServiceResult::success(['id' => 1]);

        $event = new readonly class ($actor, SimpleService::class, $result, 0.25, 'corr-1', ['name' => 'Alice']) extends ServiceEvent {};

        self::assertSame($actor, $event->actor);
        self::assertSame(SimpleService::class, $event->service);
        self::assertSame($result, $event->result);
        self::assertSame(0.25, $event->duration);
        self::assertSame('corr-1', $event->correlationId);
        self::assertSame(['name' => 'Alice'], $event->inputSummary);
    }

    /**
     * Test that an event raised without an input summary carries an empty one.
     *
     * @return void
     */
    public function testInputSummaryDefaultsToEmpty(): void
    {
        $event = new readonly class (new StubActor, SimpleService::class, ServiceResult::success(), 0.1, 'corr-2') extends ServiceEvent {};

        self::assertSame([], $event->inputSummary);
    }
}
