<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class ServiceAndLockableTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    public function testServiceRunExecutesLifecycleCallbacksAndStoresStatus(): void
    {
        LifecycleService::$initialized       = false;
        LifecycleService::$traitSuccessCalls = 0;

        $service = new LifecycleService(['id' => 1]);

        static::assertTrue(LifecycleService::$initialized);

        $result = $service->run();

        static::assertTrue($result);
        static::assertTrue($service->prepared);
        static::assertTrue($service->successful);
        static::assertSame(1, LifecycleService::$traitSuccessCalls);
        static::assertTrue($service->getStatus());
    }

    public function testServiceSupportsTransactionAndLockToggles(): void
    {
        $service = new LifecycleService;

        static::assertSame($service, $service->dontUseTransaction());
        static::assertSame($service, $service->useTransaction());
        static::assertSame($service, $service->dontUseLock());

        $service->enableLocking('abc')->useLock();

        static::assertTrue($service->run());
    }

    public function testServiceUseLockThrowsWhenNoLockIdIsConfigured(): void
    {
        $service = new LifecycleService;

        $this->expectException(\RuntimeException::class);

        $service->useLock();
    }

    public function testServiceFailedCallbackIsTriggeredWhenHandleThrows(): void
    {
        $service = new FailingService;

        $this->expectException(\RuntimeException::class);

        try {
            $service->run();
        } catch (\RuntimeException $exception) {
            static::assertTrue($service->failedCalled);
            throw $exception;
        }
    }

    public function testServiceConstructorPreservesCollectionAndStdClassPayloads(): void
    {
        $collection = collect(['x' => 1]);
        $std        = new \stdClass;

        $collectionService = new LifecycleService($collection);
        $stdService        = new LifecycleService($std);

        static::assertSame($collection, $this->getNonPublicProperty($collectionService, 'payload'));
        static::assertSame($std, $this->getNonPublicProperty($stdService, 'payload'));
    }

    public function testLockableTraitThrowsTooManyRequestsWhenLockCannotBeAcquired(): void
    {
        $lock = new class implements Lock {
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

        Cache::shouldReceive('lock')->once()->andReturn($lock);

        $service = (new LifecycleService)->enableLocking('cannot-acquire')->useLock();

        $this->expectException(TooManyRequestsException::class);

        $service->run();
    }

    public function testLockablePrivateHelpersExposeDefaultExpirationAndCachedKey(): void
    {
        $service = (new LifecycleService)->enableLocking('abc');

        static::assertSame(60, $this->invokeNonPublic($service, 'getLockExpiration'));

        $keyOne = $this->invokeNonPublic($service, 'getLockKey');
        $keyTwo = $this->invokeNonPublic($service, 'getLockKey');

        static::assertSame($keyOne, $keyTwo);
        static::assertSame($service->exposedGenerateLockKey(), $keyOne);
    }

    public function testServiceDefaultLockIdIsEmptyString(): void
    {
        static::assertSame('', $this->invokeNonPublic(new FailingService, 'getLockId'));
    }

    public function testBaseServiceSuccessAndFailedCallbacksAreInvokable(): void
    {
        $service = new class extends Service {
            protected function handle(): bool
            {
                return true;
            }
        };

        $service->success();
        $service->failed(new \RuntimeException('noop'));

        static::assertTrue(true);
    }
}

trait ServiceTestHooks
{
    public static bool $initialized      = false;
    public static int $traitSuccessCalls = 0;

    protected static function initializeServiceTestHooks(): void
    {
        self::$initialized = true;
    }

    protected function serviceTestHooksSuccess(): void
    {
        self::$traitSuccessCalls++;
    }
}

class LifecycleService extends Service
{
    use ServiceTestHooks;
    public bool $prepared   = false;
    public bool $successful = false;
    private string $lockId  = '';

    public function enableLocking(string $id): self
    {
        $this->lockId = $id;

        return $this;
    }

    public function exposedGenerateLockKey(): string
    {
        return $this->generateLockKey();
    }

    public function prepare(): void
    {
        $this->prepared = true;
    }

    public function success(): void
    {
        $this->successful = true;
    }

    protected function getLockId(): string
    {
        return $this->lockId;
    }

    protected function handle(): bool
    {
        return true;
    }
}

class FailingService extends Service
{
    public bool $failedCalled = false;

    public function failed(\Throwable $exception): void
    {
        $this->failedCalled = true;
    }

    protected function handle(): bool
    {
        throw new \RuntimeException('failed');
    }
}
