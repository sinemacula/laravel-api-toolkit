<?php

namespace Tests\Unit\Listeners\Traits;

use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Listeners\Traits\ProvidesExclusiveLock;
use Tests\TestCase;

/**
 * Tests for the ProvidesExclusiveLock trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ProvidesExclusiveLock::class)]
class ProvidesExclusiveLockTest extends TestCase
{
    /**
     * Test that handleWithLock acquires lock and executes callback.
     *
     * @return void
     */
    public function testHandleWithLockAcquiresLockAndExecutesCallback(): void
    {
        $instance = $this->createTraitInstance();
        $executed = false;

        $instance->callHandleWithLock('test-id', function () use (&$executed): void {
            $executed = true;
        });

        static::assertTrue($executed);
    }

    /**
     * Test that handleWithLock releases lock after execution.
     *
     * @return void
     */
    public function testHandleWithLockReleasesLockAfterExecution(): void
    {
        $instance = $this->createTraitInstance();

        $instance->callHandleWithLock('release-test', function (): void {
            // Noop
        });

        // Acquire the same lock again; it should succeed if the previous lock was released
        $lock = Cache::lock('LISTENER_LOCK:release-test', 10);

        static::assertTrue($lock->get());

        $lock->release();
    }

    /**
     * Test that handleWithLock skips callback when lock is unavailable.
     *
     * @return void
     */
    public function testHandleWithLockSkipsCallbackWhenLockUnavailable(): void
    {
        // Acquire the lock first so it's unavailable
        $lock = Cache::lock('LISTENER_LOCK:busy-id', 10);
        $lock->get();

        $instance = $this->createTraitInstance();
        $executed = false;

        $instance->callHandleWithLock('busy-id', function () use (&$executed): void {
            $executed = true;
        });

        static::assertFalse($executed);

        $lock->release();
    }

    /**
     * Test that a custom prefix is used in the lock key.
     *
     * @return void
     */
    public function testCustomPrefixInLockKey(): void
    {
        $instance = $this->createTraitInstance();

        // Acquire with custom prefix first
        $lock = Cache::lock('CUSTOM_PREFIX:custom-id', 10);
        $lock->get();

        $executed = false;

        $instance->callHandleWithLock('custom-id', function () use (&$executed): void {
            $executed = true;
        }, 'CUSTOM_PREFIX');

        // Should not execute because the lock with this prefix is held
        static::assertFalse($executed);

        $lock->release();
    }

    /**
     * Test that the lock releases even on exception.
     *
     * @return void
     */
    public function testLockReleasesEvenOnException(): void
    {
        $instance = $this->createTraitInstance();

        try {
            $instance->callHandleWithLock('exception-id', function (): void {
                throw new \RuntimeException('Callback failed');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        // The lock should be released; try to acquire again
        $lock = Cache::lock('LISTENER_LOCK:exception-id', 10);

        static::assertTrue($lock->get());

        $lock->release();
    }

    /**
     * Create an anonymous class that uses the ProvidesExclusiveLock trait.
     *
     * @return object
     */
    private function createTraitInstance(): object
    {
        return new class {
            use ProvidesExclusiveLock;

            /**
             * Call the handle-with-lock method.
             *
             * @param  string  $id
             * @param  callable  $callback
             * @param  string  $prefix
             * @return void
             */
            public function callHandleWithLock(string $id, callable $callback, string $prefix = 'LISTENER_LOCK'): void
            {
                $this->handleWithLock($id, $callback, $prefix);
            }
        };
    }
}
