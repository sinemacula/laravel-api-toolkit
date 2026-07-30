<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Fixtures\Services\Concerns\InnerRecordingConcern;
use Tests\Fixtures\Services\Concerns\OuterRecordingConcern;
use Tests\Fixtures\Services\Concerns\RecordingConcern;
use Tests\TestCase;

/**
 * Integration test for synchronous concern composition over a real transaction.
 *
 * Proves that multiple synchronous concerns wrap the core in declaration order
 * (the first-declared concern being the outermost) and that every concern and
 * the core execute inside the one shared database transaction rather than each
 * opening its own.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceRunner::class)]
final class ServiceConcernOrderTest extends TestCase
{
    /**
     * Reset the shared concern capture before each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        RecordingConcern::reset();
    }

    /**
     * Test that concerns wrap the core in order within one transaction.
     *
     * @return void
     */
    public function testConcernsWrapCoreInOrderWithinOneTransaction(): void
    {
        $baseLevel = DB::transactionLevel();

        $service = new class (new ArrayInput([])) extends Service {
            /**
             * Return the concerns in declaration order.
             *
             * @return array<int, class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>>
             */
            #[\Override]
            protected function concerns(): array
            {
                return [OuterRecordingConcern::class, InnerRecordingConcern::class];
            }

            /**
             * Record the core marker and its transaction level.
             *
             * @return string
             */
            #[\Override]
            protected function handle(): mixed
            {
                RecordingConcern::push('core');
                RecordingConcern::recordLevel('core', DB::transactionLevel());

                return 'done';
            }
        };

        $result = $service->run();

        self::assertTrue($result->succeeded());
        self::assertSame('done', $result->output());

        // The first-declared concern is outermost and the core runs innermost.
        self::assertSame(
            ['outer:before', 'inner:before', 'core', 'inner:after', 'outer:after'],
            RecordingConcern::$trace,
        );

        // The runner opens exactly one transaction for the whole pipeline, so
        // every concern and the core observe the same single level one deeper
        // than the ambient level.
        $expected = $baseLevel + 1;

        self::assertSame($expected, RecordingConcern::$levels['outer']);
        self::assertSame($expected, RecordingConcern::$levels['inner']);
        self::assertSame($expected, RecordingConcern::$levels['core']);
    }
}
