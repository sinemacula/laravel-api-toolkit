<?php

declare(strict_types = 1);

namespace Tests\Unit\Runtime;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
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

        static::assertTrue($context->isServingUnderOctane());
    }

    /**
     * Test that isServingUnderOctane returns false when the Octane server
     * global is absent (the php-fpm case).
     *
     * @return void
     */
    public function testIsServingUnderOctaneReturnsFalseWhenOctaneServerGlobalAbsent(): void
    {
        unset($_SERVER['LARAVEL_OCTANE']);

        $context = new RuntimeContext;

        static::assertFalse($context->isServingUnderOctane());
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

        static::assertTrue($context->isServingAsQueueWorker('database'));
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

        static::assertFalse($context->isServingAsQueueWorker('sync'));
    }

    /**
     * Test that isServingAsQueueWorker resolves the default connection when
     * called with null and that connection uses a non-sync driver.
     *
     * @return void
     */
    public function testIsServingAsQueueWorkerResolvesDefaultConnectionWhenNull(): void
    {
        Config::set('queue.default', 'redis');
        Config::set('queue.connections.redis', ['driver' => 'redis']);

        $context = new RuntimeContext;

        static::assertTrue($context->isServingAsQueueWorker(null));
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

        static::assertFalse($context->isServingAsQueueWorker('nonexistent-connection'));
    }

    /**
     * Test that an empty default connection name is treated as not-a-worker,
     * even when a connection registered under the empty name resolves a
     * non-sync driver.
     *
     * @return void
     */
    public function testIsServingAsQueueWorkerReturnsFalseForEmptyDefaultConnection(): void
    {
        Config::set('queue.default', '');
        Config::set('queue.connections..driver', 'database');

        $context = new RuntimeContext;

        static::assertFalse($context->isServingAsQueueWorker());
    }
}
