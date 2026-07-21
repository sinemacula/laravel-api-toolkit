<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Tests\Fixtures\Console\DeferUsersCommand;
use Tests\TestCase;

/**
 * Feature test proving a real Artisan run flushes deferred writes at its
 * command-finished boundary.
 *
 * A fixture command defers user rows through the deferrable repository during
 * its handler. The rows remain buffered in the scoped pool until the framework
 * fires the command-finished lifecycle event, so a genuine Artisan run proves
 * the subscriber flushes the buffer once the command completes rather than
 * during the handler.
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

        // The framework suppresses the Symfony command lifecycle events while
        // running the test suite, so reroute them before Artisan is resolved to
        // prove the real command-finished boundary flush fires.
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->registerCommand(new DeferUsersCommand);
    }

    /**
     * Test that deferred rows persist only after the command-finished boundary
     * flush completes.
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
}
