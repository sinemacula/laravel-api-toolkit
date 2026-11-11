<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Pipeline\LockStage;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\TestCase;

/**
 * Integration test for lock release when the core throws.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceRunner::class)]
#[CoversClass(LockStage::class)]
final class ServiceLockReleaseTest extends TestCase
{
    /**
     * Test that a throwing handle() releases the lock and fails the result.
     *
     * @return void
     */
    public function testThrowingHandleReleasesLock(): void
    {
        $service = new class (new ArrayInput([])) extends Service {
            /** @var bool Whether this service acquires a cache lock */
            protected bool $lockable = true;

            /**
             * Throw from the core to force the lock to release under failure.
             *
             * @return never
             *
             * @throws \RuntimeException
             */
            #[\Override]
            protected function handle(): never
            {
                throw new \RuntimeException('handle failed under lock');
            }

            /**
             * Return the unique lock identity for this invocation.
             *
             * @return string
             */
            #[\Override]
            protected function lockId(): string
            {
                return 'lock-release-on-throw';
            }
        };

        $result = $service->run();

        self::assertTrue($result->failed());
        self::assertInstanceOf(\RuntimeException::class, $result->exception);

        // A fresh acquisition of the same key proves the lock was released
        $lock = Cache::lock($service->getLockKey(), 60);

        self::assertTrue($lock->get());

        $lock->release();
    }
}
