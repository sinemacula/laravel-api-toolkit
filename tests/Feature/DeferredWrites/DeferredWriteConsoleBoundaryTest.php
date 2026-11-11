<?php

declare(strict_types = 1);

namespace Tests\Feature\DeferredWrites;

use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Fixtures\Console\DeferMissingTableRowCommand;
use Tests\Fixtures\Console\DeferUsersCommand;
use Tests\TestCase;

/**
 * Feature test proving a real Artisan run flushes deferred writes at its
 * command-finished boundary.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePool::class)]
#[CoversClass(WritePoolFlushSubscriber::class)]
#[CoversTrait(Deferrable::class)]
final class DeferredWriteConsoleBoundaryTest extends TestCase
{
    /**
     * Set up each test by registering the deferring fixture command.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $kernel = $this->app->make(KernelContract::class); // @phpstan-ignore method.nonObject

        assert($kernel instanceof Kernel);

        // The framework mutes Symfony command events under test; reroute them
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->registerCommand(new DeferUsersCommand);
    }

    /**
     * Test that deferred rows persist only after the command-finished flush.
     *
     * @return void
     */
    public function testDeferredRowsFlushAtCommandBoundary(): void
    {
        self::assertSame(0, DB::table('users')->count());

        $this->artisan('fixtures:defer-users', ['--count' => 3])->assertSuccessful(); // @phpstan-ignore method.nonObject

        self::assertSame(3, DB::table('users')->count());
        self::assertSame(1, DB::table('users')->where('email', 'deferred-1@console.test')->count());

        /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePool $pool */
        $pool = $this->app->make(WritePool::class); // @phpstan-ignore method.nonObject

        self::assertTrue($pool->isEmpty());
    }

    /**
     * Test that a rethrown boundary flush failure is reported once, by the
     * console kernel, and fails the command.
     *
     * @return void
     */
    public function testRethrownBoundaryFailureIsReportedOnceByTheKernel(): void
    {
        Config::set('api-toolkit.deferred_writes.on_failure', 'throw');
        Config::set('api-toolkit.deferred_writes.rethrow_at_boundary', true);

        Exceptions::fake();

        $app = $this->app;

        assert($app instanceof Application);

        // The test kernel rethrows instead of reporting
        $kernel = new Kernel($app, $app->make(Dispatcher::class));

        $kernel->rerouteSymfonyCommandEvents();
        $kernel->registerCommand(new DeferMissingTableRowCommand);

        $status = $kernel->handle(new ArrayInput(['command' => 'fixtures:defer-missing-table-row']), new BufferedOutput);

        self::assertSame(1, $status);
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(WritePoolFlushException::class);
    }
}
