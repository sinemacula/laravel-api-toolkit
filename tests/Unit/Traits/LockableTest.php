<?php

namespace Tests\Unit\Traits;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\LockKeyProvider;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;
use SineMacula\ApiToolkit\Traits\Lockable;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Traits\StandaloneLockableFixture;
use Tests\TestCase;

/**
 * Tests for the Lockable trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Lockable::class)]
class LockableTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that lock acquires a cache lock successfully.
     *
     * @return void
     */
    public function testLockAcquiresCacheLock(): void
    {
        $consumer = $this->createConsumer('test-lock-id');

        $lock = $this->invokeMethod($consumer, 'lock');

        static::assertInstanceOf(Lock::class, $lock);

        $this->invokeMethod($consumer, 'unlock');
    }

    /**
     * Test that lock throws TooManyRequestsException when the lock is
     * unavailable.
     *
     * @return void
     */
    public function testLockThrowsTooManyRequestsExceptionWhenUnavailable(): void
    {
        $consumer = $this->createConsumer('conflict-lock-id');

        $lockKey = $consumer->getLockKey();

        $existingLock = Cache::lock($lockKey, 60);
        $existingLock->get();

        try {
            $this->expectException(TooManyRequestsException::class);

            $this->invokeMethod($consumer, 'lock');
        } finally {
            $existingLock->release();
        }
    }

    /**
     * Test that unlock releases the lock.
     *
     * @return void
     */
    public function testUnlockReleasesTheLock(): void
    {
        $consumer = $this->createConsumer('release-lock-id');

        $this->invokeMethod($consumer, 'lock');
        $this->invokeMethod($consumer, 'unlock');

        $lockKey = $consumer->getLockKey();
        $newLock = Cache::lock($lockKey, 60);

        static::assertTrue($newLock->get());

        $newLock->release();
    }

    /**
     * Test that getLockExpiration returns the default 60 seconds.
     *
     * @return void
     */
    public function testGetLockExpirationReturnsDefault60Seconds(): void
    {
        $consumer = $this->createConsumer('expiry-lock-id');

        $expiration = $this->invokeMethod($consumer, 'getLockExpiration');

        static::assertSame(60, $expiration);
    }

    /**
     * Test that the lock key is generated via LockKeyProvider.
     *
     * @return void
     */
    public function testLockKeyIsGeneratedViaLockKeyProvider(): void
    {
        $lockId   = 'custom-lock-id';
        $consumer = $this->createConsumer($lockId);

        $lockKey = $consumer->getLockKey();

        static::assertNotEmpty($lockKey);
        static::assertIsString($lockKey);
    }

    /**
     * Test that the Lockable trait works in a standalone class that does
     * not extend Service.
     *
     * @return void
     */
    public function testStandaloneFixtureCanAcquireAndReleaseLock(): void
    {
        $fixture = new StandaloneLockableFixture('standalone-test');

        $lock = $fixture->acquireLock();

        static::assertInstanceOf(Lock::class, $lock);

        $fixture->releaseLock();

        $verificationLock = Cache::lock(sha1(StandaloneLockableFixture::class . '|standalone-test'), 60);

        static::assertTrue($verificationLock->get());

        $verificationLock->release();
    }

    /**
     * Test that the lock expiration can be customized at construction
     * time.
     *
     * @return void
     */
    public function testCustomLockExpirationCanBeSetAtConstructionTime(): void
    {
        $fixture = new StandaloneLockableFixture('expiry-test', 120);

        static::assertSame(120, $fixture->lockExpiration());
    }

    /**
     * Test that lock throws RuntimeException when no LockKeyProvider is
     * implemented.
     *
     * @return void
     */
    public function testLockThrowsRuntimeExceptionWhenNoLockKeyProviderImplemented(): void
    {
        $consumer = new class {
            use Lockable;
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LockKeyProvider');

        $this->invokeMethod($consumer, 'lock');
    }

    /**
     * Create a test consumer class that uses the Lockable trait.
     *
     * @param  string  $lockId
     * @return \SineMacula\ApiToolkit\Contracts\LockKeyProvider
     */
    private function createConsumer(string $lockId): LockKeyProvider
    {
        return new class ($lockId) implements LockKeyProvider {
            use Lockable;

            /**
             * Create a new instance.
             *
             * @param  string  $lockId
             */
            public function __construct(
                private readonly string $lockId,
            ) {}

            /**
             * Generate the cache lock key.
             *
             * @return string
             */
            #[\Override]
            public function getLockKey(): string
            {
                return sha1(self::class . '|' . $this->lockId);
            }
        };
    }
}
