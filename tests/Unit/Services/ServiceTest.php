<?php

namespace Tests\Unit\Services;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\LockKeyProvider;
use SineMacula\ApiToolkit\Services\Contracts\HasSuccessCallback;
use SineMacula\ApiToolkit\Services\Contracts\Initializable;
use SineMacula\ApiToolkit\Services\Service;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Services\FailingService;
use Tests\Fixtures\Services\LockableService;
use Tests\Fixtures\Services\NoTransactionService;
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
     * Test that the default property values are correct.
     *
     * @return void
     */
    public function testDefaultPropertyValues(): void
    {
        $service = new SimpleService;

        static::assertTrue($this->getProperty($service, 'useTransaction'));
        static::assertFalse($this->getProperty($service, 'useLock'));
    }

    /**
     * Test that the configuration properties are readonly.
     *
     * @return void
     */
    public function testConfigurationPropertiesAreReadonly(): void
    {
        $service = new SimpleService;

        $useTransaction = new \ReflectionProperty($service, 'useTransaction');
        $useLock        = new \ReflectionProperty($service, 'useLock');

        static::assertTrue($useTransaction->isReadOnly());
        static::assertTrue($useLock->isReadOnly());
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
     * Test that a subclass can disable transactions via property override.
     *
     * @return void
     */
    public function testPropertyOverrideDisablesTransaction(): void
    {
        $service = new NoTransactionService;

        static::assertFalse($this->getProperty($service, 'useTransaction'));

        $result = $service->run();

        static::assertTrue($result);
    }

    /**
     * Test that a subclass can enable locking via property override.
     *
     * @return void
     */
    public function testPropertyOverrideEnablesLocking(): void
    {
        $service = new LockableService;

        static::assertTrue($this->getProperty($service, 'useLock'));
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
     * Test that initialize calls initializeTrait() on services
     * implementing the Initializable contract.
     *
     * @return void
     */
    public function testInitializeCallsTraitInitializerViaContract(): void
    {
        HasTrackableCallbacks::$traitInitialized = false;

        $service = new class extends Service implements Initializable {
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

        $class = $service::class;

        static::assertTrue($class::$traitInitialized);
    }

    /**
     * Test that notifySuccess calls onTraitSuccess() on services
     * implementing the HasSuccessCallback contract.
     *
     * @return void
     */
    public function testNotifySuccessInvokesTraitSuccessCallbackViaContract(): void
    {
        $service = new class extends Service implements HasSuccessCallback {
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
     * Test that implementing a lifecycle contract without providing
     * the required method produces a PHP error.
     *
     * @return void
     */
    public function testContractEnforcesCorrectHookDefinition(): void
    {
        $base = dirname(__DIR__, 3);

        $code = implode(' ', [
            "require_once '{$base}/vendor/autoload.php';",
            'new class extends \SineMacula\ApiToolkit\Services\Service',
            'implements \SineMacula\ApiToolkit\Services\Contracts\Initializable',
            '{ protected function handle(): bool { return true; } };',
        ]);

        exec('php -r ' . escapeshellarg($code) . ' 2>&1', $output, $exitCode);

        static::assertNotSame(0, $exitCode, 'Implementing Initializable without initializeTrait() must produce a PHP error');
        static::assertStringContainsString('initializeTrait', implode("\n", $output));
    }

    /**
     * Test that trait lifecycle methods are not called when the service
     * does not implement the corresponding contract interface.
     *
     * @return void
     */
    public function testTraitMethodsAreNotCalledWithoutContractInterface(): void
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

        // Reset the static property in case a previous test set it
        $class                    = $service::class;
        $class::$traitInitialized = false;

        // Reconstruct to trigger initialize()
        $service = new $class;
        $service->run();

        // Without the contract interfaces, the trait methods should NOT be called
        static::assertFalse($class::$traitInitialized);
        static::assertFalse($service->traitSuccessRan);
    }
}
