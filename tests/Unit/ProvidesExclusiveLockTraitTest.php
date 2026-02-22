<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use SineMacula\ApiToolkit\Listeners\Traits\ProvidesExclusiveLock;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class ProvidesExclusiveLockTraitTest extends TestCase
{
    public function testHandleWithLockExecutesCallbackWhenLockCanBeAcquired(): void
    {
        $lock = new class implements Lock {
            public bool $released = false;

            public function get($callback = null): bool
            {
                return true;
            }

            public function block($seconds, $callback = null): mixed
            {
                return null;
            }

            public function release(): bool
            {
                $this->released = true;

                return true;
            }

            public function owner(): string
            {
                return 'owner';
            }

            public function forceRelease(): bool
            {
                return true;
            }
        };

        Cache::shouldReceive('lock')->once()->with('LISTENER_LOCK:abc', 10)->andReturn($lock);

        $handler  = new LockingListenerHarness;
        $executed = false;

        $handler->runWithLock('abc', function () use (&$executed): void {
            $executed = true;
        });

        static::assertTrue($executed);
        static::assertTrue($lock->released);
    }

    public function testHandleWithLockSkipsCallbackWhenLockCannotBeAcquiredButStillReleases(): void
    {
        $lock = new class implements Lock {
            public bool $released = false;

            public function get($callback = null): bool
            {
                return false;
            }

            public function block($seconds, $callback = null): mixed
            {
                return null;
            }

            public function release(): bool
            {
                $this->released = true;

                return true;
            }

            public function owner(): string
            {
                return 'owner';
            }

            public function forceRelease(): bool
            {
                return true;
            }
        };

        Cache::shouldReceive('lock')->once()->with('PREFIX:xyz', 10)->andReturn($lock);

        $handler  = new LockingListenerHarness;
        $executed = false;

        $handler->runWithLock('xyz', function () use (&$executed): void {
            $executed = true;
        }, 'PREFIX');

        static::assertFalse($executed);
        static::assertTrue($lock->released);
    }
}

class LockingListenerHarness
{
    use ProvidesExclusiveLock;

    public function runWithLock(string $id, callable $callback, string $prefix = 'LISTENER_LOCK'): void
    {
        $this->handleWithLock($id, $callback, $prefix);
    }
}
