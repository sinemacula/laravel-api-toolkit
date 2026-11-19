<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Contracts;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Fixtures\Services\ConcernedService;
use Tests\Fixtures\Services\Concerns\ContextCapturingConcern;
use Tests\Fixtures\Services\StubActor;
use Tests\TestCase;

/**
 * Tests for the ServiceConcern contract as a service run honours it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceRunner::class)]
final class ServiceConcernTest extends TestCase
{
    /**
     * Test that a concern receives the run's context and a next step that runs
     * prepare() and handle() and returns the output.
     *
     * @return void
     */
    public function testConcernReceivesTheRunContextAndANextThatRunsTheService(): void
    {
        $concern = new ContextCapturingConcern;
        $context = ServiceContext::for(new StubActor);
        $service = (new ConcernedService([ContextCapturingConcern::class]))->withContext($context);

        app()->instance(ContextCapturingConcern::class, $concern);

        $result = $service->run();

        self::assertSame($context, $concern->context);
        self::assertSame('handled', $result->output());
        self::assertSame(['prepare', 'handle', 'afterCommit'], $service->calls);
    }
}
