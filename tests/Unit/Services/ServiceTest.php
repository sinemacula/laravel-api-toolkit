<?php

namespace Tests\Unit\Services;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Services\FailingService;
use Tests\Fixtures\Services\LockableService;
use Tests\Fixtures\Services\SimpleService;
use Tests\Fixtures\Traits\HasTrackableCallbacks;
use Tests\TestCase;

/**
 * Tests for the Service base class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Service::class)]
class ServiceTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that run executes handle within a transaction by default.
     *
     * @return void
     */
    public function testRunExecutesHandleInTransactionByDefault(): void
    {
        $service = new SimpleService;

        static::assertTrue($this->getProperty($service, 'useTransaction'));

        $result = $service->run();

        static::assertTrue($result);
    }

    /**
     * Test that run calls success on successful execution.
     *
     * @return void
     */
    public function testRunCallsSuccessOnSuccessfulExecution(): void
    {
        $service = new SimpleService;

        $service->run();

        static::assertTrue($service->successCalled);
    }

    /**
     * Test that run returns true for a successful service.
     *
     * @return void
     */
    public function testRunReturnsTrueForSuccessfulService(): void
    {
        $service = new SimpleService;

        $result = $service->run();

        static::assertTrue($result);
    }

    /**
     * Test that getStatus returns null before run and true after success.
     *
     * @return void
     */
    public function testGetStatusReturnsNullBeforeRunAndTrueAfterSuccess(): void
    {
        $service = new SimpleService;

        static::assertNull($service->getStatus());

        $service->run();

        static::assertTrue($service->getStatus());
    }

    /**
     * Test that run calls failed and rethrows the exception on failure.
     *
     * @return void
     */
    public function testRunCallsFailedAndRethrowsOnException(): void
    {
        $service = new FailingService;

        try {
            $service->run();
            static::fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $exception) {
            static::assertTrue($service->failedCalled);
            static::assertSame($exception, $service->failedException);
            static::assertSame('Service execution failed', $exception->getMessage());
        }
    }

    /**
     * Test that dontUseTransaction runs without a database transaction.
     *
     * @return void
     */
    public function testDontUseTransactionRunsWithoutTransaction(): void
    {
        $service = new SimpleService;

        $result = $service->dontUseTransaction();

        static::assertSame($service, $result);
        static::assertFalse($this->getProperty($service, 'useTransaction'));

        static::assertTrue($service->run());
    }

    /**
     * Test that useTransaction enables the transaction.
     *
     * @return void
     */
    public function testUseTransactionEnablesTransaction(): void
    {
        $service = new SimpleService;

        $service->dontUseTransaction();

        static::assertFalse($this->getProperty($service, 'useTransaction'));

        $result = $service->useTransaction();

        static::assertSame($service, $result);
        static::assertTrue($this->getProperty($service, 'useTransaction'));
    }

    /**
     * Test that useLock throws RuntimeException when no lock ID is defined.
     *
     * @return void
     */
    public function testUseLockThrowsRuntimeExceptionWhenNoLockId(): void
    {
        $service = new SimpleService;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Lock key is not set');

        $service->useLock();
    }

    /**
     * Test that useLock enables locking when a lock ID is defined.
     *
     * @return void
     */
    public function testUseLockEnablesLocking(): void
    {
        $service = new LockableService;

        $result = $service->useLock();

        static::assertSame($service, $result);
        static::assertTrue($this->getProperty($service, 'useLock'));
    }

    /**
     * Test that dontUseLock disables locking.
     *
     * @return void
     */
    public function testDontUseLockDisablesLocking(): void
    {
        $service = new LockableService;

        $result = $service->dontUseLock();

        static::assertSame($service, $result);
        static::assertFalse($this->getProperty($service, 'useLock'));
    }

    /**
     * Test that the constructor converts an array payload to a Collection.
     *
     * @return void
     */
    public function testConstructorConvertsArrayPayloadToCollection(): void
    {
        $service = new SimpleService(['key' => 'value']);

        $payload = $this->getProperty($service, 'payload');

        static::assertInstanceOf(Collection::class, $payload);
        static::assertSame('value', $payload->get('key'));
    }

    /**
     * Test that the constructor accepts a Collection payload.
     *
     * @return void
     */
    public function testConstructorAcceptsCollectionPayload(): void
    {
        $collection = collect(['key' => 'value']);

        $service = new SimpleService($collection);

        $payload = $this->getProperty($service, 'payload');

        static::assertInstanceOf(Collection::class, $payload);
        static::assertSame('value', $payload->get('key'));
    }

    /**
     * Test that prepare is called before handle.
     *
     * @return void
     */
    public function testPrepareIsCalledBeforeHandle(): void
    {
        $callOrder = [];

        $service = new class ($callOrder) extends Service {
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
             * Prepare the service for execution.
             *
             * @return void
             */
            public function prepare(): void
            {
                $this->callOrder[] = 'prepare';
            }

            /**
             * Handle the service execution.
             *
             * @return bool
             */
            protected function handle(): bool
            {
                $this->callOrder[] = 'handle';

                return true;
            }

            /**
             * Get the recorded call order.
             *
             * @return array<int, string>
             */
            public function getCallOrder(): array
            {
                return $this->callOrder;
            }
        };

        $service->run();

        static::assertSame(['prepare', 'handle'], $service->getCallOrder());
    }

    /**
     * Test that the lockable service runs successfully with locking.
     *
     * @return void
     */
    public function testLockableServiceRunsSuccessfully(): void
    {
        $service = new LockableService;

        $result = $service->run();

        static::assertTrue($result);
    }

    /**
     * Test that the base failed() implementation is a no-op.
     *
     * @SuppressWarnings("php:S112")
     *
     * @return void
     */
    public function testBaseFailedIsANoop(): void
    {
        $service = new class extends Service {
            /**
             * Handle the service execution.
             *
             * @return never
             *
             * @throws \RuntimeException
             */
            protected function handle(): bool
            {
                throw new \RuntimeException('handled');
            }
        };

        try {
            $service->run();
        } catch (\RuntimeException) {
            // Base failed() was called and did nothing — no secondary exception
        }

        static::assertFalse($service->getStatus() ?? false);
    }

    /**
     * Test that initializeTraits calls the initialize* method on used traits.
     *
     * @return void
     */
    public function testInitializeTraitsCallsTraitInitializer(): void
    {
        $service = new class extends Service {
            use HasTrackableCallbacks;

            /**
             * Handle the service execution.
             *
             * @return bool
             */
            protected function handle(): bool
            {
                return true;
            }
        };

        // The static property lives on the using (anonymous) class, not on the
        // trait directly, because forward_static_call uses late static binding.
        $class = $service::class;

        static::assertTrue($class::$traitInitialized);
    }

    /**
     * Test that callTraitsSuccessCallbacks invokes the *Success method on
     * used traits.
     *
     * @return void
     */
    public function testCallTraitsSuccessCallbacksInvokesTraitSuccessMethod(): void
    {
        $service = new class extends Service {
            use HasTrackableCallbacks;

            /**
             * Handle the service execution.
             *
             * @return bool
             */
            protected function handle(): bool
            {
                return true;
            }
        };

        $service->run();

        static::assertTrue($service->traitSuccessRan);
    }
}
