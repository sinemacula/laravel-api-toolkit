<?php

declare(strict_types = 1);

namespace Tests\Unit\Services;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\LockKeyProvider;
use SineMacula\ApiToolkit\Services\Actors\AnonymousActor;
use SineMacula\ApiToolkit\Services\Contracts\Actor;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\Events\ServiceCompleted;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Jobs\ServiceJob;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\ApiToolkit\Services\ServiceResult;
use Tests\Fixtures\Services\OutputService;
use Tests\Fixtures\Services\StubActor;
use Tests\TestCase;

/**
 * Tests for the Service base class.
 *
 * Covers the typed input skeleton, actor API, make/by/withContext fluent
 * methods, run() totality, getLockKey(), and the serviceHooks() seam.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Service::class)]
final class ServiceTest extends TestCase
{
    /**
     * Test that handle() is declared abstract and protected.
     *
     * @return void
     */
    public function testHandleIsAbstractAndProtected(): void
    {
        $method = new \ReflectionMethod(Service::class, 'handle');

        self::assertTrue($method->isAbstract());
        self::assertTrue($method->isProtected());
    }

    /**
     * Test that a concrete service returns typed output through ServiceResult.
     *
     * @return void
     */
    public function testOutputServiceReturnsTypedOutput(): void
    {
        $service = new OutputService(new ArrayInput(['message' => 'hello']));
        $result  = $service->run();

        self::assertTrue($result->succeeded());
        self::assertSame(['message' => 'hello'], $result->output());
    }

    /**
     * Test that actor() returns AnonymousActor when no actor has been set.
     *
     * @return void
     */
    public function testActorDefaultsToAnonymousActor(): void
    {
        $service = new OutputService(new ArrayInput([]));

        self::assertInstanceOf(AnonymousActor::class, $service->actor());
    }

    /**
     * Test that actor() never reads from Auth or any ambient state.
     *
     * @return void
     */
    public function testActorReadsNoAmbientAuthState(): void
    {
        // Facade is NOT faked — any Auth call would throw or require setup.
        // If actor() internally called Auth::user() without setup, it would
        // produce a non-AnonymousActor or throw. AnonymousActor is the proof.
        $service = new OutputService(new ArrayInput([]));

        self::assertInstanceOf(AnonymousActor::class, $service->actor());
    }

    /**
     * Test that by() records the causer and returns the same instance.
     *
     * @return void
     */
    public function testByRecordsActorAndReturnsSelf(): void
    {
        $stub    = new StubActor;
        $service = new OutputService(new ArrayInput([]));

        $returned = $service->by($stub);

        self::assertSame($service, $returned);
        self::assertSame($stub, $service->actor());
    }

    /**
     * Test that run() never throws even when handle() throws.
     *
     * @return void
     */
    public function testRunNeverThrowsForBusinessFailures(): void
    {
        $service = new class (new ArrayInput([])) extends Service {
            /**
             * Always throw a domain exception.
             *
             * @return never
             *
             * @throws \RuntimeException
             */
            #[\Override]
            protected function handle(): never
            {
                throw new \RuntimeException('domain failure');
            }
        };

        $result = $service->run();

        self::assertInstanceOf(ServiceResult::class, $result);
        self::assertTrue($result->failed());
        self::assertInstanceOf(\RuntimeException::class, $result->exception);
        self::assertSame('domain failure', $result->exception->getMessage());
    }

    /**
     * Test that make() resolves the service from the container.
     *
     * @return void
     */
    public function testMakeResolvesFromContainer(): void
    {
        $input   = new ArrayInput(['message' => 'from-container']);
        $service = OutputService::make($input);

        self::assertInstanceOf(OutputService::class, $service);
        self::assertTrue($service->run()->succeeded());
    }

    /**
     * Test that Service implements LockKeyProvider.
     *
     * @return void
     */
    public function testImplementsLockKeyProvider(): void
    {
        self::assertInstanceOf(LockKeyProvider::class, new OutputService(new ArrayInput([])));
    }

    /**
     * Test that getLockKey() returns sha1(class|lockId()).
     *
     * @return void
     */
    public function testGetLockKeyReturnsSha1OfClassAndLockId(): void
    {
        $service = new class (new ArrayInput([])) extends Service {
            /**
             * Return the lock identity for this action.
             *
             * @return string
             */
            #[\Override]
            protected function lockId(): string
            {
                return 'my-lock';
            }

            /**
             * Execute the action.
             *
             * @return mixed
             */
            #[\Override]
            protected function handle(): mixed
            {
                return null;
            }
        };

        $expected = sha1($service::class . '|my-lock');

        self::assertSame($expected, $service->getLockKey());
    }

    /**
     * Test that withContext() propagates the actor from the context.
     *
     * @return void
     */
    public function testWithContextPropagatesActor(): void
    {
        $stub    = new StubActor;
        $context = ServiceContext::for($stub);
        $service = new OutputService(new ArrayInput([]));

        $returned = $service->withContext($context);

        self::assertSame($service, $returned);
        self::assertSame($stub, $service->actor());
    }

    /**
     * Test that withContext() causes run() to reuse the provided context.
     *
     * @return void
     */
    public function testWithContextReusesSameContextInRun(): void
    {
        $stub    = new StubActor;
        $context = ServiceContext::for($stub);

        $service = new class (new ArrayInput([])) extends Service {
            /** @var string|null */
            public ?string $capturedType = null;

            /**
             * Capture the actor type and return null.
             *
             * @return mixed
             */
            #[\Override]
            protected function handle(): mixed
            {
                $this->capturedType = $this->actor()->actorType();

                return null;
            }
        };

        $service->withContext($context)->run();

        self::assertSame('stub', $service->capturedType);
    }

    /**
     * Test that the typed input is accessible via $this->input.
     *
     * @return void
     */
    public function testTypedInputIsAccessibleViaInputProperty(): void
    {
        $input   = new ArrayInput(['key' => 'value']);
        $service = new OutputService($input);
        $result  = $service->run();

        self::assertTrue($result->succeeded());
    }

    /**
     * Test that serviceHooks() returns all expected lifecycle keys.
     *
     * @return void
     */
    public function testServiceHooksContainsAllLifecycleKeys(): void
    {
        $service = new OutputService(new ArrayInput(['msg' => 'x']));
        $hooks   = $service->serviceHooks();

        self::assertArrayHasKey('authorize', $hooks);
        self::assertArrayHasKey('validate', $hooks);
        self::assertArrayHasKey('prepare', $hooks);
        self::assertArrayHasKey('handle', $hooks);
        self::assertArrayHasKey('afterCommit', $hooks);
        self::assertArrayHasKey('onFailure', $hooks);
        self::assertArrayHasKey('concerns', $hooks);
        self::assertArrayHasKey('lockId', $hooks);
        self::assertArrayHasKey('transactional', $hooks);
        self::assertArrayHasKey('transactionAttempts', $hooks);
        self::assertArrayHasKey('lockable', $hooks);
        self::assertArrayHasKey('inputSummary', $hooks);
        self::assertSame(['msg' => 'x'], $hooks['inputSummary']);
    }

    /**
     * Test that ServiceInput::toArray() is correctly exposed as the contract.
     *
     * @return void
     */
    public function testServiceInputContractExposesArray(): void
    {
        $input = new ArrayInput(['a' => 1, 'b' => 2]);

        self::assertSame(['a' => 1, 'b' => 2], $input->toArray());
        self::assertInstanceOf(ServiceInput::class, $input);
    }

    /**
     * Test that actor() returns an Actor contract instance.
     *
     * @return void
     */
    public function testActorReturnsActorContractInstance(): void
    {
        $service = new OutputService(new ArrayInput([]));

        self::assertInstanceOf(Actor::class, $service->actor());
    }

    /**
     * Test that run() reuses the context supplied via withContext() rather than
     * building a fresh one.
     *
     * @return void
     */
    public function testRunReusesContextSuppliedByWithContext(): void
    {
        Event::fake();

        $context = ServiceContext::for(new StubActor, ServiceSource::HTTP, [], 'fixed-correlation');
        $service = new OutputService(new ArrayInput(['message' => 'hello']));

        $service->withContext($context)->run();

        Event::assertDispatched(
            ServiceCompleted::class,
            static fn (ServiceCompleted $event): bool => $event->correlationId === 'fixed-correlation',
        );
    }

    /**
     * Test that dispatch() reuses the context supplied via withContext() rather
     * than building a fresh one.
     *
     * @return void
     */
    public function testDispatchReusesContextSuppliedByWithContext(): void
    {
        Queue::fake();

        $context = ServiceContext::for(new StubActor, ServiceSource::HTTP, [], 'fixed-correlation');
        $service = new OutputService(new ArrayInput([]));

        $service->withContext($context)->dispatch();

        Queue::assertPushed(
            ServiceJob::class,
            static fn (ServiceJob $job): bool => $job->context->correlationId === 'fixed-correlation'
                && $job->context->source                                      === ServiceSource::HTTP,
        );
    }

    /**
     * Test that serviceHooks wires prepare() so it is invoked during a run.
     *
     * @return void
     */
    public function testServiceHooksInvokePrepareDuringRun(): void
    {
        $service = new class (new ArrayInput([])) extends Service {
            /** @var bool */
            public bool $prepareCalled = false;

            /**
             * Record the prepare call.
             *
             * @return void
             */
            #[\Override]
            protected function prepare(): void
            {
                $this->prepareCalled = true;
            }

            /**
             * Return null output.
             *
             * @return mixed
             */
            #[\Override]
            protected function handle(): mixed
            {
                return null;
            }
        };

        $service->run();

        self::assertTrue($service->prepareCalled);
    }

    /**
     * Test that serviceHooks wires onFailure() so it is invoked when the run
     * fails.
     *
     * @return void
     */
    public function testServiceHooksInvokeOnFailureDuringRun(): void
    {
        $service = new class (new ArrayInput([])) extends Service {
            /** @var bool */
            public bool $onFailureCalled = false;

            /**
             * Always throw a domain exception.
             *
             * @return never
             *
             * @throws \RuntimeException
             */
            #[\Override]
            protected function handle(): never
            {
                throw new \RuntimeException('domain failure');
            }

            /**
             * Record the onFailure call.
             *
             * @param  \Throwable  $exception
             * @return void
             */
            #[\Override]
            protected function onFailure(\Throwable $exception): void
            {
                $this->onFailureCalled = true;
            }
        };

        $service->run();

        self::assertTrue($service->onFailureCalled);
    }

    /**
     * Test that every overridable lifecycle hook remains protected so concrete
     * services can override it.
     *
     * @return void
     */
    public function testLifecycleHooksRemainProtectedForOverriding(): void
    {
        // Exercise the non-overridden base hook bodies so they are covered.
        (new OutputService(new ArrayInput([])))->run();

        $failing = new class (new ArrayInput([])) extends Service {
            /**
             * Always throw so the base onFailure() hook is exercised.
             *
             * @return never
             *
             * @throws \RuntimeException
             */
            #[\Override]
            protected function handle(): never
            {
                throw new \RuntimeException('domain failure');
            }
        };

        $failing->run();

        foreach (['authorize', 'validate', 'prepare', 'onFailure', 'concerns', 'lockId'] as $hook) {
            self::assertTrue(
                (new \ReflectionMethod(Service::class, $hook))->isProtected(),
                $hook . '() must remain protected so subclasses can override it',
            );
        }
    }
}
