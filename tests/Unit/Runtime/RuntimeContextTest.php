<?php

declare(strict_types = 1);

namespace Tests\Unit\Runtime;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
use Tests\TestCase;

/**
 * Tests for the RuntimeContext serving-runtime detector.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RuntimeContext::class)]
final class RuntimeContextTest extends TestCase
{
    /** @var bool|null Whether LARAVEL_OCTANE was set before each test ran. */
    private ?bool $octaneWasSet = null;

    /**
     * Record the pre-test state of the Octane server global.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->octaneWasSet = isset($_SERVER['LARAVEL_OCTANE']);
    }

    /**
     * Restore the Octane server global to its pre-test state.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if ($this->octaneWasSet === false) {
            unset($_SERVER['LARAVEL_OCTANE']);
        } elseif ($this->octaneWasSet === true) {
            $_SERVER['LARAVEL_OCTANE'] = 1;
        }

        parent::tearDown();
    }

    /**
     * Test that isServingUnderOctane returns true when the Octane server global
     * is set.
     *
     * @return void
     */
    public function testIsServingUnderOctaneReturnsTrueWhenOctaneServerGlobalSet(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = 1;

        $context = new RuntimeContext;

        self::assertTrue($context->isServingUnderOctane());
    }

    /**
     * Test that isServingUnderOctane returns false when the Octane server
     * global is absent.
     *
     * @return void
     */
    public function testIsServingUnderOctaneReturnsFalseWhenOctaneServerGlobalAbsent(): void
    {
        unset($_SERVER['LARAVEL_OCTANE']);

        $context = new RuntimeContext;

        self::assertFalse($context->isServingUnderOctane());
    }

    /**
     * Test that isServingAsQueueWorker returns true for a non-sync driver.
     *
     * @return void
     */
    public function testIsServingAsQueueWorkerReturnsTrueForNonSyncDriver(): void
    {
        Config::set('queue.connections.database', ['driver' => 'database']);

        $context = new RuntimeContext;

        self::assertTrue($context->isServingAsQueueWorker('database'));
    }

    /**
     * Test that isServingAsQueueWorker returns false for the sync driver.
     *
     * @return void
     */
    public function testIsServingAsQueueWorkerReturnsFalseForSyncDriver(): void
    {
        Config::set('queue.connections.sync', ['driver' => 'sync']);

        $context = new RuntimeContext;

        self::assertFalse($context->isServingAsQueueWorker('sync'));
    }

    /**
     * Provide queue drivers with whether their jobs run outside the request.
     *
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function queueDriverProvider(): iterable
    {
        yield 'deferred runs the job in the request after the response' => ['deferred', false];
        yield 'background runs the job in a child process' => ['background', true];
        yield 'failover is worked by a queue worker' => ['failover', true];
    }

    /**
     * Test that isServingAsQueueWorker treats only a driver that runs the job
     * outside the request as a worker.
     *
     * @param  string  $driver
     * @param  bool  $isWorker
     * @return void
     */
    #[DataProvider('queueDriverProvider')]
    public function testIsServingAsQueueWorkerJudgesWhereTheDriverRunsTheJob(string $driver, bool $isWorker): void
    {
        Config::set('queue.connections.probe', ['driver' => $driver]);

        $context = new RuntimeContext;

        self::assertSame($isWorker, $context->isServingAsQueueWorker('probe'));
    }

    /**
     * Test that isServingAsQueueWorker returns false when the connection driver
     * cannot be resolved.
     *
     * @return void
     */
    public function testIsServingAsQueueWorkerReturnsFalseWhenDriverUnresolved(): void
    {
        $context = new RuntimeContext;

        self::assertFalse($context->isServingAsQueueWorker('nonexistent-connection'));
    }

    /**
     * Test that a connection with an empty or non-string driver is not taken
     * for a queue worker.
     *
     * @return void
     */
    public function testIsServingAsQueueWorkerReturnsFalseForAnUnusableDriver(): void
    {
        Config::set('queue.connections.blank', ['driver' => '']);
        Config::set('queue.connections.numeric', ['driver' => 1]);

        $context = new RuntimeContext;

        self::assertFalse($context->isServingAsQueueWorker('blank'));
        self::assertFalse($context->isServingAsQueueWorker('numeric'));
    }

    /**
     * Test that a job without a connection name is judged by the default
     * connection, and by none when the default is not a name.
     *
     * @return void
     */
    public function testIsServingAsQueueWorkerFallsBackToTheDefaultConnection(): void
    {
        Config::set('queue.connections.database', ['driver' => 'database']);
        Config::set('queue.connections.sync', ['driver' => 'sync']);

        $context = new RuntimeContext;

        Config::set('queue.default', 'database');
        self::assertTrue($context->isServingAsQueueWorker(null));

        Config::set('queue.default', 'sync');
        self::assertFalse($context->isServingAsQueueWorker(null));

        Config::set('queue.default', null);
        self::assertFalse($context->isServingAsQueueWorker(null));

        Config::set('queue.connections.1', ['driver' => 'database']);
        Config::set('queue.default', 1);
        self::assertFalse($context->isServingAsQueueWorker(null));
    }
}
