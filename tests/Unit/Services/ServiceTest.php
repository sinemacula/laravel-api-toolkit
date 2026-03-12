<?php

namespace Tests\Unit\Services;

use Closure;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\LockKeyProvider;
use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Fixtures\Services\FailingService;
use Tests\Fixtures\Services\LockableService;
use Tests\Fixtures\Services\NoTransactionService;
use Tests\Fixtures\Services\SimpleService;
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
     * Test that the service implements the LockKeyProvider contract.
     *
     * @return void
     */
    public function testServiceImplementsLockKeyProvider(): void
    {
        $service = new SimpleService;

        static::assertInstanceOf(LockKeyProvider::class, $service);
    }

    /**
     * Test that getLockKey returns a SHA-1 hash of the class name and
     * lock ID.
     *
     * @return void
     */
    public function testGetLockKeyReturnsSha1OfClassAndLockId(): void
    {
        $service = new LockableService;

        $expected = sha1(LockableService::class . '|lockable-test');

        static::assertSame($expected, $service->getLockKey());
    }

    /**
     * Test that the constructor converts an array payload to a Collection.
     *
     * @return void
     */
    public function testConstructorConvertsArrayPayloadToCollection(): void
    {
        $service = new SimpleService(['key' => 'value']);

        $payload = (new \ReflectionProperty($service, 'payload'))->getValue($service);

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

        $payload = (new \ReflectionProperty($service, 'payload'))->getValue($service);

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
     * Test that the base concerns() method returns an empty array.
     *
     * @return void
     */
    public function testConcernsDefaultsToEmptyArray(): void
    {
        $service = new class extends Service {
            /**
             * Handle the service execution.
             *
             * @return bool
             */
            protected function handle(): bool
            {
                return true;
            }

            /**
             * Expose the protected concerns() method for testing.
             *
             * @return array<int, class-string<ServiceConcern>>
             */
            public function getConcerns(): array
            {
                return $this->concerns();
            }
        };

        static::assertSame([], $service->getConcerns());
    }

    /**
     * Test that concerns execute in declaration order.
     *
     * The first concern in the array is the outermost wrapper,
     * so it runs first (before) and last (after).
     *
     * @return void
     */
    public function testConcernsExecuteInDeclarationOrder(): void
    {
        $order = [];

        $concernA = new class ($order) implements ServiceConcern {
            /** @var array<int, string> */
            private array $order;

            /**
             * Create a new instance.
             *
             * @param  array<int, string>  $order
             */
            public function __construct(array &$order)
            {
                $this->order = &$order;
            }

            /**
             * Execute the concern.
             *
             * @param  Service   $service
             * @param  \Closure  $next
             * @return bool
             */
            public function execute(Service $service, Closure $next): bool
            {
                $this->order[] = 'A:before';
                $result = $next();
                $this->order[] = 'A:after';

                return $result;
            }
        };

        $concernB = new class ($order) implements ServiceConcern {
            /** @var array<int, string> */
            private array $order;

            /**
             * Create a new instance.
             *
             * @param  array<int, string>  $order
             */
            public function __construct(array &$order)
            {
                $this->order = &$order;
            }

            /**
             * Execute the concern.
             *
             * @param  Service   $service
             * @param  \Closure  $next
             * @return bool
             */
            public function execute(Service $service, Closure $next): bool
            {
                $this->order[] = 'B:before';
                $result = $next();
                $this->order[] = 'B:after';

                return $result;
            }
        };

        $this->app->instance($concernA::class, $concernA);
        $this->app->instance($concernB::class, $concernB);

        $service = new class ($concernA, $concernB) extends Service {
            /** @var class-string<ServiceConcern> */
            private string $classA;

            /** @var class-string<ServiceConcern> */
            private string $classB;

            /**
             * Create a new instance.
             *
             * @param  ServiceConcern  $concernA
             * @param  ServiceConcern  $concernB
             */
            public function __construct(ServiceConcern $concernA, ServiceConcern $concernB)
            {
                $this->classA = $concernA::class;
                $this->classB = $concernB::class;

                parent::__construct([]);
            }

            /**
             * Return the ordered list of concern classes for this service.
             *
             * @return array<int, class-string<ServiceConcern>>
             */
            #[\Override]
            protected function concerns(): array
            {
                return [$this->classA, $this->classB];
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

        $service->run();

        static::assertSame(['A:before', 'B:before', 'B:after', 'A:after'], $order);
    }

    /**
     * Test that a concern can short-circuit the pipeline by not
     * calling $next().
     *
     * @return void
     */
    public function testConcernCanShortCircuitPipeline(): void
    {
        $handleCalled = false;

        $concern = new class implements ServiceConcern {
            /**
             * Execute the concern, short-circuiting the pipeline.
             *
             * @param  Service   $service
             * @param  \Closure  $next
             * @return bool
             */
            public function execute(Service $service, Closure $next): bool
            {
                return false;
            }
        };

        $this->app->instance($concern::class, $concern);

        $service = new class ($concern, $handleCalled) extends Service {
            /** @var class-string<ServiceConcern> */
            private string $concernClass;

            /** @var bool */
            private bool $handleCalled;

            /**
             * Create a new instance.
             *
             * @param  ServiceConcern  $concern
             * @param  bool            $handleCalled
             */
            public function __construct(ServiceConcern $concern, bool &$handleCalled)
            {
                $this->concernClass = $concern::class;
                $this->handleCalled = &$handleCalled;

                parent::__construct([]);
            }

            /**
             * Return the ordered list of concern classes for this service.
             *
             * @return array<int, class-string<ServiceConcern>>
             */
            #[\Override]
            protected function concerns(): array
            {
                return [$this->concernClass];
            }

            /**
             * Handle the service execution.
             *
             * @return bool
             */
            protected function handle(): bool
            {
                $this->handleCalled = true;

                return true;
            }
        };

        $result = $service->run();

        static::assertFalse($result);
        static::assertFalse($handleCalled);
    }

    /**
     * Test that a service with no concerns runs successfully.
     *
     * @return void
     */
    public function testNoTransactionServiceRunsWithoutConcerns(): void
    {
        $service = new NoTransactionService;

        $result = $service->run();

        static::assertTrue($result);
        static::assertTrue($service->successCalled);
    }
}
