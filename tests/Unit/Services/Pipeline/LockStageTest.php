<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Pipeline;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
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
    /** @var array<int, string> The lock calls and pipeline steps, in the order they ran */
    private array $calls = [];

    /**
     * Test that wrap releases the lock it took once $next returns.
     *
     * @return void
     */
    public function testWrapReleasesTheLockItTookOnceNextReturns(): void
    {
        $result = (new LockStage)->wrap($this->lockingService(), $this->next(...));

        self::assertSame('result-value', $result);
        self::assertSame(['lock', 'next', 'release'], $this->calls);
    }

    /**
     * Test that wrap releases the lock at once and rethrows when $next throws.
     *
     * @return void
     */
    public function testWrapReleasesTheLockAndRethrowsWhenNextThrows(): void
    {
        $failure = new \RuntimeException('pipeline failure');

        $this->assertThrows(fn (): mixed => (new LockStage)->wrap($this->lockingService(), static fn (): never => throw $failure), \RuntimeException::class, 'pipeline failure');

        self::assertSame(['lock', 'release'], $this->calls);
    }

    /**
     * Test that wrap holds the lock until the caller's transaction commits.
     *
     * @return void
     */
    public function testWrapHoldsTheLockUntilTheCallerCommits(): void
    {
        $service = $this->lockingService();

        DB::transaction(function () use ($service): void {
            (new LockStage)->wrap($service, $this->next(...));

            self::assertSame(['lock', 'next'], $this->calls);
        });

        self::assertSame(['lock', 'next', 'release'], $this->calls);
    }

    /**
     * Test that wrap releases the lock once when the caller's transaction rolls
     * back.
     *
     * @return void
     */
    public function testWrapReleasesTheLockOnceWhenTheCallerRollsBack(): void
    {
        $service = $this->lockingService();

        DB::beginTransaction();

        (new LockStage)->wrap($service, $this->next(...));

        self::assertSame(['lock', 'next'], $this->calls);

        DB::rollBack();

        self::assertSame(['lock', 'next', 'release'], $this->calls);
    }

    /**
     * Test that a failed release is reported instead of thrown.
     *
     * @return void
     */
    public function testWrapReportsAFailedReleaseInsteadOfThrowingIt(): void
    {
        Exceptions::fake();

        $error = new \RuntimeException('release failed');

        self::assertSame('result-value', (new LockStage)->wrap($this->lockingService($error), $this->next(...)));

        Exceptions::assertReported(static fn (\RuntimeException $e): bool => $e === $error);
    }

    /**
     * Test that a failed release after $next throws does not replace the
     * original failure.
     *
     * @return void
     */
    public function testWrapRethrowsTheOriginalFailureWhenReleaseAlsoFails(): void
    {
        Exceptions::fake();

        $failure = new \RuntimeException('pipeline failure');
        $error   = new \RuntimeException('release failed');

        $service = $this->lockingService($error);

        $this->assertThrows(static fn (): mixed => (new LockStage)->wrap($service, static fn (): never => throw $failure), \RuntimeException::class, 'pipeline failure');

        Exceptions::assertReported(static fn (\RuntimeException $e): bool => $e === $error);
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

        $this->expectException(LockUnavailableException::class);

        (new LockStage)->wrap($service, fn (): bool => true);
    }

    /**
     * Record the pipeline step and return its value.
     *
     * @return string
     */
    private function next(): string
    {
        $this->calls[] = 'next';

        return 'result-value';
    }

    /**
     * Create a service whose lock() records the call and returns a lock that
     * records its release, throwing the given error from it when set.
     *
     * unlock() records too, so a release through the service would show up.
     *
     * @param  \Throwable|null  $releaseError
     * @return \SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, mixed>
     */
    private function lockingService(?\Throwable $releaseError = null): Service
    {
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('release')->andReturnUsing(function () use ($releaseError): bool {
            $this->calls[] = 'release';

            if ($releaseError !== null) {
                throw $releaseError;
            }

            return true;
        });

        return new class ($this->calls, $lock) extends Service {
            /** @var array<int, string> */
            public array $calls;

            /** @var \Illuminate\Contracts\Cache\Lock The lock lock() returns */
            private readonly Lock $heldLock;

            /**
             * Create a new instance.
             *
             * @param  array<int, string>  $calls
             * @param  \Illuminate\Contracts\Cache\Lock  $heldLock
             */
            public function __construct(array &$calls, Lock $heldLock)
            {
                $this->calls    = &$calls;
                $this->heldLock = $heldLock;

                parent::__construct(new ArrayInput([]));
            }

            /**
             * Record the call and return the lock.
             *
             * @return \Illuminate\Contracts\Cache\Lock
             */
            #[\Override]
            public function lock(): Lock
            {
                $this->calls[] = 'lock';

                return $this->heldLock;
            }

            /**
             * Record a release through the service.
             *
             * @return void
             */
            #[\Override]
            public function unlock(): void
            {
                $this->calls[] = 'unlock';
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
