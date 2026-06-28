<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Listeners;

/**
 * Fixture child listener invoking the inherited exclusive lock helper.
 *
 * Used to assert that handleWithLock remains accessible from subclasses of a
 * listener that uses the ProvidesExclusiveLock trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ChildExclusiveLockListenerFixture extends ExclusiveLockListenerFixture
{
    /**
     * Run the given callback under the inherited exclusive lock.
     *
     * @param  string  $id
     * @param  callable(): void  $callback
     * @return void
     */
    public function run(string $id, callable $callback): void
    {
        $this->handleWithLock($id, $callback);
    }
}
