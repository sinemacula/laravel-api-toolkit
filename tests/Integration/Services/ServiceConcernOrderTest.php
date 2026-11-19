<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Events\ServiceFailed;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Fixtures\Exceptions\ServiceExecutionException;
use Tests\Fixtures\Services\ConcernedService;
use Tests\Fixtures\Services\Concerns\InnerRecordingConcern;
use Tests\Fixtures\Services\Concerns\OuterRecordingConcern;
use Tests\Fixtures\Services\Concerns\RecordingConcern;
use Tests\Fixtures\Services\Concerns\ThrowingConcern;
use Tests\TestCase;

/**
 * Integration test for synchronous concern composition over a real transaction.
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

        self::assertSame(
            ['outer:before', 'inner:before', 'core', 'inner:after', 'outer:after'],
            RecordingConcern::$trace,
        );

        // One transaction wraps the pipeline, one level above the ambient level
        $expected = $baseLevel + 1;

        self::assertSame($expected, RecordingConcern::$levels['outer']);
        self::assertSame($expected, RecordingConcern::$levels['inner']);
        self::assertSame($expected, RecordingConcern::$levels['core']);
    }

    /**
     * Test that a concern throwing after handle() wrote rolls the write back
     * and fails the run.
     *
     * @return void
     */
    public function testThrowingConcernRollsBackAndFails(): void
    {
        Event::fake([ServiceFailed::class]);

        $service = new ConcernedService([ThrowingConcern::class]);

        $result = $service->run();

        self::assertTrue($result->failed());
        self::assertInstanceOf(ServiceExecutionException::class, $result->exception);
        self::assertSame('The concern refused the result.', $result->exception->getMessage());
        self::assertSame(['prepare', 'handle', 'onFailure'], $service->calls);
        self::assertSame(0, DB::table('users')->count());

        Event::assertDispatched(ServiceFailed::class, static fn (ServiceFailed $event): bool => $event->result === $result);
    }
}
