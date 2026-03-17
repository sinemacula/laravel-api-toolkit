<?php

namespace Tests\Unit\Services;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Enums\ServiceStatus;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceResult;
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

        static::assertInstanceOf(ServiceResult::class, $result);
        static::assertTrue($result->succeeded());
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
     * Test that run returns a successful ServiceResult for a successful
     * service.
     *
     * @return void
     */
    public function testRunReturnsSuccessfulResultForSuccessfulService(): void
    {
        $service = new SimpleService;

        $result = $service->run();

        static::assertInstanceOf(ServiceResult::class, $result);
        static::assertSame(ServiceStatus::Succeeded, $result->status);
        static::assertTrue($result->succeeded());
        static::assertFalse($result->failed());
    }

    /**
     * Test that run returns a failed ServiceResult when the service throws.
     *
     * @return void
     */
    public function testRunReturnsFailedResultOnException(): void
    {
        $service = new FailingService;

        $result = $service->run();

        static::assertInstanceOf(ServiceResult::class, $result);
        static::assertSame(ServiceStatus::Failed, $result->status);
        static::assertTrue($result->failed());
        static::assertFalse($result->succeeded());
    }

    /**
     * Test that run calls failed and captures the exception on failure.
     *
     * @return void
     */
    public function testRunCallsFailedAndCapturesExceptionOnFailure(): void
    {
        $service = new FailingService;

        $result = $service->run();

        static::assertTrue($service->failedCalled);
        static::assertNotNull($service->failedException);
        static::assertSame('Service execution failed', $service->failedException->getMessage());
        static::assertSame($service->failedException, $result->exception);
    }

    /**
     * Test that a successful result has a null exception.
     *
     * @return void
     */
    public function testSuccessfulResultHasNullException(): void
    {
        $service = new SimpleService;

        $result = $service->run();

        static::assertNull($result->exception);
    }

    /**
     * Test that dontUseTransaction runs without a database transaction.
     *
     * @return void
     */
    public function testDontUseTransactionRunsWithoutTransaction(): void
    {
        $service = new SimpleService;

        $returnedService = $service->dontUseTransaction();

        static::assertSame($service, $returnedService);
        static::assertFalse($this->getProperty($service, 'useTransaction'));

        $result = $service->run();

        static::assertTrue($result->succeeded());
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

        $returnedService = $service->useTransaction();

        static::assertSame($service, $returnedService);
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

        static::assertTrue($result->succeeded());
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

        $result = $service->run();

        // Base failed() was called and did nothing — no secondary exception
        static::assertTrue($result->failed());
        static::assertNotNull($result->exception);
        static::assertSame('handled', $result->exception->getMessage());
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

    /**
     * Test that run captures result data from the service.
     *
     * @return void
     */
    public function testRunCapturesResultData(): void
    {
        $service = new class extends Service {
            /**
             * Handle the service execution.
             *
             * @return bool
             */
            protected function handle(): bool
            {
                $this->data = ['created' => true];

                return true;
            }
        };

        $result = $service->run();

        static::assertTrue($result->succeeded());
        static::assertSame(['created' => true], $result->data);
    }

    /**
     * Test that run returns null data when the service produces no output.
     *
     * @return void
     */
    public function testRunReturnsNullDataWhenServiceProducesNoOutput(): void
    {
        $service = new SimpleService;

        $result = $service->run();

        static::assertTrue($result->succeeded());
        static::assertNull($result->data);
    }

    /**
     * Test that a failed result captures data set before the exception.
     *
     * @return void
     */
    public function testFailedResultCapturesDataSetBeforeException(): void
    {
        $service = new class extends Service {
            /**
             * Handle the service execution.
             *
             * @SuppressWarnings("php:S112")
             *
             * @return never
             *
             * @throws \RuntimeException
             */
            protected function handle(): bool
            {
                $this->data = 'partial';

                throw new \RuntimeException('failed after setting data');
            }
        };

        $result = $service->run();

        static::assertTrue($result->failed());
        static::assertSame('partial', $result->data);
        static::assertNotNull($result->exception);
        static::assertSame('failed after setting data', $result->exception->getMessage());
    }

    /**
     * Test that the failed hook receives the exception on failure.
     *
     * @return void
     */
    public function testFailedHookReceivesExceptionOnFailure(): void
    {
        $service = new FailingService;

        $result = $service->run();

        static::assertTrue($service->failedCalled);
        static::assertSame($result->exception, $service->failedException);
    }

    /**
     * Test that handle returning false produces a failed result.
     *
     * @return void
     */
    public function testHandleReturningFalseProducesFailedResult(): void
    {
        $service = new class extends Service {
            /**
             * Handle the service execution.
             *
             * @return bool
             */
            protected function handle(): bool
            {
                return false;
            }
        };

        $result = $service->run();

        static::assertTrue($result->failed());
        static::assertNotNull($result->exception);
    }

    /**
     * Test that prepare exception produces a failed result.
     *
     * @return void
     */
    public function testPrepareExceptionProducesFailedResult(): void
    {
        $service = new class extends Service {
            /** @var bool Track whether failed() was called */
            public bool $failedCalled = false;

            /**
             * Prepare the service for execution.
             *
             * @SuppressWarnings("php:S112")
             *
             * @return void
             *
             * @throws \RuntimeException
             */
            public function prepare(): void
            {
                throw new \RuntimeException('prepare failed');
            }

            /**
             * Method is triggered if the handle method failed.
             *
             * @param  \Throwable  $exception
             * @return void
             */
            public function failed(\Throwable $exception): void
            {
                $this->failedCalled = true;
            }

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

        $result = $service->run();

        static::assertTrue($result->failed());
        static::assertTrue($service->failedCalled);
        static::assertNotNull($result->exception);
        static::assertSame('prepare failed', $result->exception->getMessage());
    }
}
