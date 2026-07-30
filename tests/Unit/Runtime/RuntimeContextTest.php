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
     * global is absent (the php-fpm case).
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
     * Test that the no-connection check detects an actual worker from the
     * running command, not from the default queue driver: a web request whose
     * default queue is non-sync is not a worker.
     *
     * @return void
     */
    public function testIsServingAsQueueWorkerDetectsTheWorkerCommand(): void
    {
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database', ['driver' => 'database']);

        $context = new RuntimeContext;
        $argv    = $_SERVER['argv'] ?? null;

        try {
            $_SERVER['argv'] = ['artisan', 'queue:work', 'redis'];
            self::assertTrue($context->isServingAsQueueWorker(null));

            $_SERVER['argv'] = ['artisan', 'queue:listen'];
            self::assertTrue($context->isServingAsQueueWorker(null));

            $_SERVER['argv'] = ['artisan', 'route:list'];
            self::assertFalse($context->isServingAsQueueWorker(null));
        } finally {
            if ($argv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $argv;
            }
        }
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
}
