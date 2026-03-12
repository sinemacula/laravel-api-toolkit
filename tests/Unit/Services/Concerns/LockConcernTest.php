<?php

namespace Tests\Unit\Services\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\LockKeyProvider;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;
use SineMacula\ApiToolkit\Services\Concerns\LockConcern;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Traits\Lockable;
use Tests\TestCase;

/**
 * Tests for the LockConcern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LockConcern::class)]
class LockConcernTest extends TestCase
{
    /**
     * Test that execute acquires the lock before calling the next closure.
     *
     * @return void
     */
    public function testExecuteAcquiresLockBeforeCallingNext(): void
    {
        $callOrder = [];

        $service = $this->createLockableService($callOrder);
        $concern = new LockConcern;

        $concern->execute($service, function () use (&$callOrder): bool {
            $callOrder[] = 'next';

            return true;
        });

        static::assertSame(['lock', 'next'], array_slice($callOrder, 0, 2));
    }

    /**
     * Test that execute releases the lock after the next closure returns.
     *
     * @return void
     */
    public function testExecuteReleasesLockAfterNext(): void
    {
        $callOrder = [];

        $service = $this->createLockableService($callOrder);
        $concern = new LockConcern;

        $concern->execute($service, function () use (&$callOrder): bool {
            $callOrder[] = 'next';

            return true;
        });

        static::assertSame(['lock', 'next', 'unlock'], $callOrder);
    }

    /**
     * Test that execute releases the lock even when the next closure
     * throws an exception.
     *
     * @return void
     */
    public function testExecuteReleasesLockOnException(): void
    {
        $callOrder = [];

        $service = $this->createLockableService($callOrder);
        $concern = new LockConcern;

        try {

            $concern->execute($service, function (): bool {
                throw new \RuntimeException('pipeline failure');
            });

        } catch (\RuntimeException) {
            // Expected
        }

        static::assertContains('unlock', $callOrder);
    }

    /**
     * Test that execute passes through without locking when the service
     * does not use the Lockable trait.
     *
     * @return void
     */
    public function testExecutePassesThroughWhenServiceDoesNotUseLockable(): void
    {
        $service = $this->createMock(Service::class);
        $concern = new LockConcern;

        $result = $concern->execute($service, fn (): bool => true);

        static::assertTrue($result);
    }

    /**
     * Test that TooManyRequestsException from lock() propagates to the
     * caller.
     *
     * @return void
     */
    public function testExecutePropagatesTooManyRequestsException(): void
    {
        $service = new class extends Service implements LockKeyProvider {
            use Lockable;

            /**
             * Lock the task execution.
             *
             * @return never
             *
             * @throws \SineMacula\ApiToolkit\Exceptions\TooManyRequestsException
             */
            #[\Override]
            public function lock(): \Illuminate\Contracts\Cache\Lock
            {
                throw new TooManyRequestsException;
            }

            /**
             * Generate the cache lock key.
             *
             * @return string
             */
            #[\Override]
            public function getLockKey(): string
            {
                return 'test-lock-key';
            }

            /**
             * Handle the service execution.
             *
             * @return bool
             */
            #[\Override]
            protected function handle(): bool
            {
                return true;
            }
        };

        $concern = new LockConcern;

        $this->expectException(TooManyRequestsException::class);

        $concern->execute($service, fn (): bool => true);
    }

    /**
     * Create a lockable service fixture that tracks call order.
     *
     * @param  array<int, string>  $callOrder
     * @return \SineMacula\ApiToolkit\Services\Service
     */
    private function createLockableService(array &$callOrder): Service
    {
        return new class ($callOrder) extends Service implements LockKeyProvider {
            use Lockable;

            /** @var array<int, string> */
            private array $callOrder;

            /**
             * Create a new instance.
             *
             * @param  array<int, string>  $callOrder
             */
            public function __construct(array &$callOrder)
            {
                $this->callOrder = &$callOrder;

                parent::__construct([]);
            }

            /**
             * Lock the task execution.
             *
             * @return \Illuminate\Contracts\Cache\Lock
             */
            #[\Override]
            public function lock(): \Illuminate\Contracts\Cache\Lock
            {
                $this->callOrder[] = 'lock';

                return \Mockery::mock(\Illuminate\Contracts\Cache\Lock::class);
            }

            /**
             * Unlock the task execution.
             *
             * @return void
             */
            #[\Override]
            public function unlock(): void
            {
                $this->callOrder[] = 'unlock';
            }

            /**
             * Generate the cache lock key.
             *
             * @return string
             */
            #[\Override]
            public function getLockKey(): string
            {
                return 'test-lock-key';
            }

            /**
             * Handle the service execution.
             *
             * @return bool
             */
            #[\Override]
            protected function handle(): bool
            {
                return true;
            }
        };
    }
}
