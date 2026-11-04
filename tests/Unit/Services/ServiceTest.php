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
use SineMacula\ApiToolkit\Services\Events\ServiceFailed;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Jobs\ServiceJob;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\ApiToolkit\Services\ServiceResult;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Fixtures\Exceptions\ServiceExecutionException;
use Tests\Fixtures\Input\SampleInput;
use Tests\Fixtures\Services\ContextRecordingService;
use Tests\Fixtures\Services\FailingService;
use Tests\Fixtures\Services\OutputService;
use Tests\Fixtures\Services\StubActor;
use Tests\Fixtures\Services\TypedInputService;
use Tests\TestCase;

/**
 * Tests for the Service base class.
 *
 * Covers the typed input skeleton, actor API, make/by/withContext fluent
 * methods, run() totality, runOrFail(), getLockKey(), the run context read
 * through context(), and the serviceHooks() and bindRunContext() seams.
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
     * Test that runOrFail() returns the output of a succeeding service.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testRunOrFailReturnsTheOutput(): void
    {
        $service = new OutputService(new ArrayInput(['message' => 'hello']));

        self::assertSame(['message' => 'hello'], $service->runOrFail());
    }

    /**
     * Test that runOrFail() fires ServiceCompleted and not ServiceFailed when
     * the service succeeds.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testRunOrFailDispatchesServiceCompletedOnSuccess(): void
    {
        Event::fake();

        (new OutputService(new ArrayInput(['message' => 'hello'])))->runOrFail();

        Event::assertDispatched(
            ServiceCompleted::class,
            static fn (ServiceCompleted $event): bool => $event->service === OutputService::class,
        );
        Event::assertNotDispatched(ServiceFailed::class);
    }

    /**
     * Test that runOrFail() rethrows the exception thrown by handle() after
     * running onFailure() and dispatching ServiceFailed.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testRunOrFailRethrowsTheExceptionFromHandle(): void
    {
        Event::fake();

        $service = new FailingService;

        $this->expectException(ServiceExecutionException::class);

        try {
            $service->runOrFail();
        } catch (\Throwable $exception) {
            self::assertTrue($service->onFailureCalled);
            self::assertSame($exception, $service->onFailureException);

            Event::assertDispatched(
                ServiceFailed::class,
                static fn (ServiceFailed $event): bool => $event->result->exception === $exception,
            );
            Event::assertNotDispatched(ServiceCompleted::class);

            throw $exception;
        }
    }

    /**
     * Test that runOrFail() runs with the context supplied via withContext().
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testRunOrFailUsesContextSuppliedByWithContext(): void
    {
        Event::fake();

        $context = ServiceContext::for(new StubActor, ServiceSource::HTTP, [], 'fixed-correlation');

        (new OutputService(new ArrayInput(['message' => 'hello'])))->withContext($context)->runOrFail();

        Event::assertDispatched(
            ServiceCompleted::class,
            static fn (ServiceCompleted $event): bool => $event->correlationId === 'fixed-correlation',
        );
    }

    /**
     * Test that runOrFail() returns the output typed as the service's declared
     * output template.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testRunOrFailReturnsTheDeclaredOutputType(): void
    {
        self::assertSame('London', $this->runTypedInputService('London'));
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
     * Test that handle() reads a typed property straight off $this->input.
     *
     * @return void
     */
    public function testHandleReadsTypedPropertyFromInput(): void
    {
        $service = new TypedInputService(new SampleInput(city: 'London'));
        $result  = $service->run();

        self::assertTrue($result->succeeded());
        self::assertSame('London', $result->output());
    }

    /**
     * Test that the input property is readonly.
     *
     * @return void
     */
    public function testInputPropertyIsReadonly(): void
    {
        $property = new \ReflectionProperty(Service::class, 'input');

        self::assertTrue($property->isReadOnly());
        self::assertTrue($property->isProtected());
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

    /**
     * Test that context() inside handle() returns the context given through
     * withContext().
     *
     * @return void
     */
    public function testContextReturnsTheAttachedContextDuringHandle(): void
    {
        $context = ServiceContext::for(new StubActor, ServiceSource::HTTP, ['ip' => '10.0.0.1'], 'attached-correlation');
        $service = new ContextRecordingService(new ArrayInput([]));

        $service->withContext($context)->run();

        self::assertSame($context, $service->seen['handle']);
    }

    /**
     * Test that with no context attached, context() returns the INTERNAL
     * context run() built, which is the one the completion event reports.
     *
     * @return void
     */
    public function testContextWithoutAnAttachedContextIsTheInternalContextTheEventReports(): void
    {
        Event::fake([ServiceCompleted::class]);

        $actor   = new StubActor;
        $service = new ContextRecordingService(new ArrayInput([]));

        $service->by($actor)->run();

        $context = $service->seen['handle'];

        self::assertSame(ServiceSource::INTERNAL, $context->source);
        self::assertSame($actor, $context->actor);
        self::assertSame([], $context->metadata);

        Event::assertDispatched(
            ServiceCompleted::class,
            static fn (ServiceCompleted $event): bool => $event->correlationId === $context->correlationId
                && $event->actor                                               === $actor,
        );
    }

    /**
     * Test that context() throws before the service has run.
     *
     * @return void
     */
    public function testContextThrowsBeforeARun(): void
    {
        $service = new ContextRecordingService(new ArrayInput([]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The service context is only available while the service is running.');

        $service->currentContext();
    }

    /**
     * Test that context() throws once the run has ended.
     *
     * @return void
     */
    public function testContextThrowsAfterARun(): void
    {
        $service = new ContextRecordingService(new ArrayInput([]));
        $service->withContext(ServiceContext::for(new StubActor))->run();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The service context is only available while the service is running.');

        $service->currentContext();
    }

    /**
     * Test that two runs of one instance without an attached context each get
     * their own correlation id.
     *
     * @return void
     */
    public function testEachRunWithoutAnAttachedContextGetsItsOwnCorrelationId(): void
    {
        $service = new ContextRecordingService(new ArrayInput([]));

        $service->run();
        $first = $service->seen['handle'];

        $service->run();
        $second = $service->seen['handle'];

        self::assertNotSame($first->correlationId, $second->correlationId);
    }

    /**
     * Test that a run nested inside another run of the same instance sees its
     * own context and restores the outer one when it ends.
     *
     * @return void
     */
    public function testNestedRunRestoresTheOuterContext(): void
    {
        $outer = ServiceContext::for(new StubActor, correlationId: 'outer-correlation');

        $service = new class (new ArrayInput([])) extends Service {
            /** @var \SineMacula\ApiToolkit\Services\ServiceContext|null The context the nested run was given */
            public ?ServiceContext $nested = null;

            /** @var \SineMacula\ApiToolkit\Services\ServiceContext|null The context read inside the nested run */
            public ?ServiceContext $insideNested = null;

            /** @var \SineMacula\ApiToolkit\Services\ServiceContext|null The context read after the nested run ended */
            public ?ServiceContext $afterNested = null;

            /**
             * Run once more with an inner context on the first call, recording
             * the context before and after.
             *
             * @return mixed
             */
            #[\Override]
            protected function handle(): mixed
            {
                if ($this->nested !== null) {
                    $this->insideNested = $this->context();

                    return null;
                }

                $this->nested = ServiceContext::for(new AnonymousActor, correlationId: 'inner-correlation');

                (new ServiceRunner)->run($this, $this->nested);

                $this->afterNested = $this->context();

                return null;
            }
        };

        $service->withContext($outer)->run();

        self::assertNotNull($service->nested);
        self::assertSame($service->nested, $service->insideNested);
        self::assertSame($outer, $service->afterNested);
    }

    /**
     * Test that every lifecycle hook, concerns() and lockId() on the success
     * path read the same context instance.
     *
     * @return void
     */
    public function testContextIsTheSameInstanceInEveryHookOnSuccess(): void
    {
        $context = ServiceContext::for(new StubActor);
        $service = new ContextRecordingService(new ArrayInput([]));

        $service->withContext($context)->run();

        $hooks = ['concerns', 'lockId', 'authorize', 'validate', 'prepare', 'handle', 'afterCommit'];

        self::assertSame($hooks, array_keys($service->seen));

        foreach ($service->seen as $seen) {
            self::assertSame($context, $seen);
        }
    }

    /**
     * Test that onFailure() reads the same context instance as the hooks that
     * ran before the failure.
     *
     * @return void
     */
    public function testContextIsTheSameInstanceInOnFailure(): void
    {
        $context        = ServiceContext::for(new StubActor);
        $service        = new ContextRecordingService(new ArrayInput([]));
        $service->fails = true;

        $service->withContext($context)->run();

        self::assertArrayNotHasKey('afterCommit', $service->seen);
        self::assertSame($context, $service->seen['handle']);
        self::assertSame($context, $service->seen['onFailure']);
    }

    /**
     * Test that while a run is in progress actor() returns the run context's
     * actor, and the actor given to by() again once the run ends.
     *
     * @return void
     */
    public function testActorIsTheRunContextActorWhileRunning(): void
    {
        $given   = new StubActor;
        $running = new AnonymousActor;

        $service = new class (new ArrayInput([])) extends Service {
            /** @var \SineMacula\ApiToolkit\Services\Contracts\Actor|null The actor read inside handle() */
            public ?Actor $seenActor = null;

            /**
             * Record the actor read during the run.
             *
             * @return mixed
             */
            #[\Override]
            protected function handle(): mixed
            {
                $this->seenActor = $this->actor();

                return null;
            }
        };

        $service->by($given);

        (new ServiceRunner)->run($service, ServiceContext::for($running));

        self::assertSame($running, $service->seenActor);
        self::assertSame($given, $service->actor());
    }

    /**
     * Test that by() after withContext() changes actor() outside a run but
     * leaves the attached context, and so the run's actor, unchanged.
     *
     * @return void
     */
    public function testByAfterWithContextLeavesTheAttachedContextUnchanged(): void
    {
        Event::fake([ServiceCompleted::class]);

        $attached = new StubActor;
        $later    = new AnonymousActor;
        $service  = new ContextRecordingService(new ArrayInput([]));

        $service->withContext(ServiceContext::for($attached))->by($later)->run();

        self::assertSame($attached, $service->seen['handle']->actor);
        self::assertSame($later, $service->actor());
        Event::assertDispatched(
            ServiceCompleted::class,
            static fn (ServiceCompleted $event): bool => $event->actor === $attached,
        );
    }

    /**
     * Test that bindRunContext() binds the given context and returns the one it
     * replaced.
     *
     * @return void
     */
    public function testBindRunContextReturnsThePreviousContext(): void
    {
        $first   = ServiceContext::for(new StubActor);
        $second  = ServiceContext::for(new StubActor);
        $service = new ContextRecordingService(new ArrayInput([]));

        self::assertNull($service->bindRunContext($first));
        self::assertSame($first, $service->bindRunContext($second));
        self::assertSame($second, $service->currentContext());
        self::assertSame($second, $service->bindRunContext(null));
    }

    /**
     * Run the typed input service and return its output through a native string
     * return type, which holds only if runOrFail() returns the service's
     * declared output type.
     *
     * @param  string  $city
     * @return string
     *
     * @throws \Throwable
     */
    private function runTypedInputService(string $city): string
    {
        return TypedInputService::make(new SampleInput(city: $city))->runOrFail();
    }
}
