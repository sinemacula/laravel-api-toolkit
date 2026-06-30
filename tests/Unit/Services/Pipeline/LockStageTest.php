<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Pipeline;

use Illuminate\Contracts\Cache\Lock;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\LockUnavailableException;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Pipeline\LockStage;
use SineMacula\ApiToolkit\Services\Service;
use Tests\TestCase;

/**
 * Tests for the LockStage class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LockStage::class)]
final class LockStageTest extends TestCase
{
    /**
     * Test that wrap acquires the lock before $next, releases after, and
     * returns the value from $next.
     *
     * @return void
     */
    public function testWrapAcquiresAndReleasesLock(): void
    {
        $callOrder = [];

        $service = $this->createLockableService($callOrder);
        $concern = new LockStage;

        $result = $concern->wrap($service, function () use (&$callOrder): string {
            $callOrder[] = 'next';

            return 'result-value';
        });

        self::assertSame(['lock', 'next', 'unlock'], $callOrder);
        self::assertSame('result-value', $result);
    }

    /**
     * Test that wrap releases the lock in the finally block even when $next
     * throws.
     *
     * @return void
     */
    public function testWrapReleasesLockWhenNextThrows(): void
    {
        $callOrder = [];

        $service = $this->createLockableService($callOrder);
        $concern = new LockStage;

        try {

            $concern->wrap($service, function (): never {
                throw new \RuntimeException('pipeline failure');
            });

        } catch (\RuntimeException) {
            // Expected
        }

        self::assertSame(['lock', 'unlock'], $callOrder);
    }

    /**
     * Test that a contended lock causes LockUnavailableException to propagate.
     *
     * @return void
     */
    public function testWrapPropagatesContention(): void
    {
        $service = new class (new ArrayInput([])) extends Service {
            /**
             * Lock the task execution.
             *
             * @return never
             *
             * @throws \SineMacula\ApiToolkit\Exceptions\LockUnavailableException
             */
            #[\Override]
            public function lock(): Lock
            {
                throw new LockUnavailableException('Unable to acquire the cache lock; the resource is currently locked.');
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

        $concern = new LockStage;

        $this->expectException(LockUnavailableException::class);

        $concern->wrap($service, fn (): bool => true);
    }

    /**
     * Create a lockable service fixture that tracks call order.
     *
     * @param  array<int, string>  $callOrder
     * @return \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, mixed>
     */
    private function createLockableService(array &$callOrder): Service
    {
        return new class ($callOrder) extends Service {
            /** @var array<int, string> */
            public array $callOrder;

            /**
             * Create a new instance.
             *
             * @param  array<int, string>  $callOrder
             */
            public function __construct(array &$callOrder)
            {
                $this->callOrder = &$callOrder;

                parent::__construct(new ArrayInput([]));
            }

            /**
             * Lock the task execution.
             *
             * @return \Illuminate\Contracts\Cache\Lock
             */
            #[\Override]
            public function lock(): Lock
            {
                $this->callOrder[] = 'lock';

                return \Mockery::mock(Lock::class);
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
