<?php

namespace Tests\Unit\Traits;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;
use SineMacula\ApiToolkit\Traits\Lockable;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Traits\StandaloneLockConsumer;
use Tests\TestCase;

/**
 * Tests proving the Lockable trait works on a non-Service class using
 * an explicit named consumer.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Lockable::class)]
class LockableStandaloneTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that the Lockable trait can be used without extending Service.
     *
     * @return void
     */
    public function testLockableCanBeUsedWithoutExtendingService(): void
    {
        $consumer = new StandaloneLockConsumer('standalone-lock-id');

        $lock = $this->invokeMethod($consumer, 'lock');

        static::assertInstanceOf(Lock::class, $lock);

        $this->invokeMethod($consumer, 'unlock');
    }

    /**
     * Test that the standalone consumer generates a lock key.
     *
     * @return void
     */
    public function testStandaloneConsumerGeneratesLockKey(): void
    {
        $consumer = new StandaloneLockConsumer('key-gen-lock-id');

        $lockKey = $this->invokeMethod($consumer, 'generateLockKey');

        static::assertIsString($lockKey);
        static::assertNotEmpty($lockKey);
    }

    /**
     * Test that the standalone consumer throws when the lock is
     * unavailable.
     *
     * @return void
     */
    public function testStandaloneConsumerThrowsWhenLockIsUnavailable(): void
    {
        $consumer = new StandaloneLockConsumer('conflict-standalone-lock-id');

        $lockKey      = $this->invokeMethod($consumer, 'generateLockKey');
        $existingLock = Cache::lock($lockKey, 60);
        $existingLock->get();

        try {
            $this->expectException(TooManyRequestsException::class);

            $this->invokeMethod($consumer, 'lock');
        } finally {
            $existingLock->release();
        }
    }
}
