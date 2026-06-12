<?php

namespace Tests\Unit\Traits;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Exceptions\LockOperationException;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;
use SineMacula\ApiToolkit\Traits\Lockable;
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
#[CoversTrait(Lockable::class)]
class LockableTest extends TestCase
{
    /**
     * Test that lock acquires a cache lock successfully.
     *
     * @return void
     */
    public function testLockAcquiresCacheLock(): void
    {
        $consumer = $this->createConsumer('test-lock-id');

        $lock = $consumer->lock();

        static::assertInstanceOf(Lock::class, $lock);

        $consumer->unlock();
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

            $consumer->lock();

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

        $consumer->lock();
        $consumer->unlock();

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
        $fixture = new StandaloneLockableFixture('expiry-lock-id');

        static::assertSame(60, $fixture->lockExpiration());
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
     * Test that lock throws LockOperationException when no LockKeyProvider is
     * implemented.
     *
     * @return void
     */
    public function testLockThrowsLockOperationExceptionWhenNoLockKeyProviderImplemented(): void
    {
        $consumer = new class {
            use Lockable;
        };

        $this->expectException(LockOperationException::class);
        $this->expectExceptionMessage('LockKeyProvider');

        $consumer->lock();
    }

    /**
     * Test that the lock conflict exception carries the rate limit meta.
     *
     * @return void
     */
    public function testLockConflictExceptionCarriesRateLimitMeta(): void
    {
        $consumer = $this->createConsumer('meta-lock-id');

        $existingLock = Cache::lock($consumer->getLockKey(), 60);
        $existingLock->get();

        try {

            $consumer->lock();

            static::fail('Expected TooManyRequestsException was not thrown');
        } catch (TooManyRequestsException $exception) {
            static::assertSame(['X-RateLimit-Limit' => 1, 'X-RateLimit-Remaining' => 0], $exception->getCustomMeta());
        } finally {
            $existingLock->release();
        }
    }

    /**
     * Test that unlock is harmless when no lock has been acquired.
     *
     * @return void
     */
    public function testUnlockWithoutLockIsHarmless(): void
    {
        $consumer = $this->createConsumer('unlock-without-lock');

        $consumer->unlock();

        static::assertInstanceOf(Lock::class, $consumer->lock());

        $consumer->unlock();
    }

    /**
     * Test that getLockExpiration remains callable from a subclass of the
     * trait consumer.
     *
     * @return void
     */
    public function testGetLockExpirationIsCallableFromSubclass(): void
    {
        $child = new class ('expiry-child', 90) extends StandaloneLockableFixture {
            /**
             * Expose the inherited lock expiration.
             *
             * @return int
             */
            public function exposeExpiration(): int
            {
                return $this->getLockExpiration();
            }
        };

        static::assertSame(90, $child->exposeExpiration());
    }

    /**
     * Test that a preset lock key property is respected without requiring
     * the LockKeyProvider contract.
     *
     * @return void
     */
    public function testPresetLockKeyPropertyIsRespected(): void
    {
        $consumer = new class {
            use Lockable;

            /**
             * Create the consumer with a preset lock key.
             */
            public function __construct()
            {
                $this->lockKey = 'preset-lock-key';
            }
        };

        $lock = $consumer->lock();

        static::assertInstanceOf(Lock::class, $lock);

        $verificationLock = Cache::lock('preset-lock-key', 60);

        static::assertFalse($verificationLock->get());

        $consumer->unlock();
    }

    /**
     * Create a test consumer that uses the Lockable trait.
     *
     * @param  string  $lockId
     * @return \Tests\Fixtures\Traits\StandaloneLockableFixture
     */
    private function createConsumer(string $lockId): StandaloneLockableFixture
    {
        return new StandaloneLockableFixture($lockId);
    }
}
