<?php

declare(strict_types = 1);

namespace Tests\Unit\Services;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\LockOperationException;
use SineMacula\ApiToolkit\Services\Actors\AnonymousActor;
use SineMacula\ApiToolkit\Services\Actors\SystemActor;
use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
use SineMacula\ApiToolkit\Services\Events\ServiceCompleted;
use SineMacula\ApiToolkit\Services\Events\ServiceFailed;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Fixtures\Services\OutputService;
use Tests\TestCase;

/**
 * Tests for the ServiceRunner lifecycle orchestrator.
 *
 * Covers execution order, failure totality, afterCommit/onFailure edge cases,
 * SystemActor authorization short-circuit, empty-lockId guard, and event
 * dispatch.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceRunner::class)]
final class ServiceRunnerTest extends TestCase
{
    /**
     * Test that the lifecycle hooks execute in the fixed order: authorize ->
     * validate -> prepare -> handle -> afterCommit.
     *
     * @return void
     */
    public function testLifecycleRunsInFixedOrder(): void
    {
        $service = new class (new ArrayInput([])) extends Service {
            /** @var array<int, string> */
            public array $log = [];

            /**
             * Record the authorize call.
             *
             * @return void
             */
            #[\Override]
            protected function authorize(): void
            {
                $this->log[] = 'authorize';
            }

            /**
             * Record the validate call.
             *
             * @return void
             */
            #[\Override]
            protected function validate(): void
            {
                $this->log[] = 'validate';
            }

            /**
             * Record the prepare call.
             *
             * @return void
             */
            #[\Override]
            protected function prepare(): void
            {
                $this->log[] = 'prepare';
            }

            /**
             * Record the handle call and return typed output.
             *
             * @return string
             */
            #[\Override]
            protected function handle(): mixed
            {
                $this->log[] = 'handle';

                return 'output';
            }

            /**
             * Record the afterCommit call.
             *
             * @param  mixed  $output
             * @return void
             */
            #[\Override]
            protected function afterCommit(mixed $output): void
            {
                $this->log[] = 'afterCommit';
            }
        };

        (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        self::assertSame(['authorize', 'validate', 'prepare', 'handle', 'afterCommit'], $service->log);
    }

    /**
     * Test that a domain failure thrown from handle() is captured in the result
     * and never propagated to the caller.
     *
     * @return void
     */
    public function testBusinessFailureIsCapturedNotThrown(): void
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
                throw new \RuntimeException('domain error');
            }
        };

        $result = (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        self::assertTrue($result->failed());
        self::assertInstanceOf(\RuntimeException::class, $result->exception);
        self::assertSame('domain error', $result->exception->getMessage());
    }

    /**
     * Test that afterCommit receives the output from handle() and that a throw
     * from afterCommit is captured as a side-effect error while leaving the
     * committed result as succeeded.
     *
     * @return void
     */
    public function testAfterCommitReceivesOutputAndItsThrowBecomesASideEffect(): void
    {
        $service = new class (new ArrayInput([])) extends Service {
            /** @var mixed */
            public mixed $receivedOutput = null;

            /**
             * Return a concrete output value.
             *
             * @return string
             */
            #[\Override]
            protected function handle(): mixed
            {
                return 'core-output';
            }

            /**
             * Capture the output then throw a side-effect exception.
             *
             * @param  mixed  $output
             * @return void
             *
             * @throws \RuntimeException
             */
            #[\Override]
            protected function afterCommit(mixed $output): void
            {
                $this->receivedOutput = $output;
                throw new \RuntimeException('after-commit-error');
            }
        };

        $result = (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        self::assertTrue($result->succeeded());
        self::assertSame('core-output', $result->output());
        self::assertSame('core-output', $service->receivedOutput);
        self::assertCount(1, $result->sideEffectErrors());
        self::assertSame('after-commit-error', $result->sideEffectErrors()[0]->getMessage());
    }

    /**
     * Test that onFailure runs after a domain failure and that a throw from
     * onFailure is caught without masking the original failure.
     *
     * @return void
     */
    public function testOnFailureRunsAfterRollbackAndItsThrowIsCaught(): void
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
                throw new \RuntimeException('domain error');
            }

            /**
             * Record the call and throw a secondary exception.
             *
             * @param  \Throwable  $exception
             * @return void
             *
             * @throws \RuntimeException
             */
            #[\Override]
            protected function onFailure(\Throwable $exception): void
            {
                $this->onFailureCalled = true;
                throw new \RuntimeException('on-failure-error');
            }
        };

        $result = (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        self::assertTrue($result->failed());
        self::assertTrue($service->onFailureCalled);
        self::assertSame('domain error', $result->exception?->getMessage());
    }

    /**
     * Test that a SystemActor bypasses the authorize() step entirely.
     *
     * @return void
     */
    public function testSystemActorShortCircuitsAuthorisationStep(): void
    {
        $service = new class (new ArrayInput([])) extends Service {
            /** @var bool */
            public bool $authorizeCalled = false;

            /**
             * Record an authorize call (should not happen for SystemActor).
             *
             * @return void
             */
            #[\Override]
            protected function authorize(): void
            {
                $this->authorizeCalled = true;
            }

            /**
             * Return output.
             *
             * @return string
             */
            #[\Override]
            protected function handle(): mixed
            {
                return 'output';
            }
        };

        $result = (new ServiceRunner)->run($service, ServiceContext::for(new SystemActor));

        self::assertTrue($result->succeeded());
        self::assertFalse($service->authorizeCalled);
    }

    /**
     * Test that a non-SystemActor triggers the authorize() step.
     *
     * @return void
     */
    public function testNonSystemActorTriggersAuthorisationStep(): void
    {
        $service = new class (new ArrayInput([])) extends Service {
            /** @var bool */
            public bool $authorizeCalled = false;

            /**
             * Record the authorize call.
             *
             * @return void
             */
            #[\Override]
            protected function authorize(): void
            {
                $this->authorizeCalled = true;
            }

            /**
             * Return output.
             *
             * @return string
             */
            #[\Override]
            protected function handle(): mixed
            {
                return 'output';
            }
        };

        (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        self::assertTrue($service->authorizeCalled);
    }

    /**
     * Test that a lockable service with an empty lockId() yields a
     * LockOperationException captured in the failure result.
     *
     * @return void
     */
    public function testEmptyLockIdYieldsLockOperationExceptionInResult(): void
    {
        $service = new class (new ArrayInput([])) extends Service {
            /** @var bool */
            protected bool $lockable = true;

            /**
             * Return output.
             *
             * @return mixed
             */
            #[\Override]
            protected function handle(): mixed
            {
                return null;
            }
        };

        $result = (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        self::assertTrue($result->failed());
        self::assertInstanceOf(LockOperationException::class, $result->exception);
    }

    /**
     * Test that a ServiceCompleted event is dispatched on a successful run.
     *
     * @return void
     */
    public function testServiceCompletedEventDispatchedOnSuccess(): void
    {
        Event::fake();

        $context = ServiceContext::for(new AnonymousActor);
        $service = new OutputService(new ArrayInput(['message' => 'hello']));
        $result  = (new ServiceRunner)->run($service, $context);

        Event::assertDispatched(
            ServiceCompleted::class,
            fn (ServiceCompleted $event): bool => $event->result === $result
                    && $event->service                           === OutputService::class
                    && $event->correlationId                     === $context->correlationId
                    && $event->inputSummary                      === ['message' => 'hello'],
        );

        Event::assertNotDispatched(ServiceFailed::class);
    }

    /**
     * Test that a ServiceFailed event is dispatched on a failed run.
     *
     * @return void
     */
    public function testServiceFailedEventDispatchedOnFailure(): void
    {
        Event::fake();

        $context = ServiceContext::for(new AnonymousActor);
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
                throw new \RuntimeException('fail');
            }
        };

        $result = (new ServiceRunner)->run($service, $context);

        Event::assertDispatched(
            ServiceFailed::class,
            fn (ServiceFailed $event): bool => $event->result === $result
                    && $event->result->failed()
                    && $event->correlationId === $context->correlationId,
        );

        Event::assertNotDispatched(ServiceCompleted::class);
    }

    /**
     * Test that concerns execute in declaration order with the first concern
     * acting as the outermost wrapper.
     *
     * @return void
     *
     * @phpstan-ignore staticMethod.impossibleType
     */
    public function testConcernsExecuteInDeclarationOrder(): void
    {
        $callOrder = new \ArrayObject;

        $concernA = new class ($callOrder) implements ServiceConcern {
            /**
             * Create a new logging concern for slot A.
             *
             * @param  \ArrayObject<int, string>  $callOrder
             */
            public function __construct(

                /** The shared call-order log. */
                private readonly \ArrayObject $callOrder,
            ) {}

            /**
             * Record A:before, call next, record A:after.
             *
             * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
             * @param  \Closure(): mixed  $next
             * @return mixed
             */
            #[\Override]
            public function handle(ServiceContext $context, \Closure $next): mixed
            {
                $this->callOrder->append('A:before');
                $result = $next();
                $this->callOrder->append('A:after');

                return $result;
            }
        };

        $concernB = new class ($callOrder) implements ServiceConcern {
            /**
             * Create a new logging concern for slot B.
             *
             * @param  \ArrayObject<int, string>  $callOrder
             */
            public function __construct(

                /** The shared call-order log. */
                private readonly \ArrayObject $callOrder,
            ) {}

            /**
             * Record B:before, call next, record B:after.
             *
             * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
             * @param  \Closure(): mixed  $next
             * @return mixed
             */
            #[\Override]
            public function handle(ServiceContext $context, \Closure $next): mixed
            {
                $this->callOrder->append('B:before');
                $result = $next();
                $this->callOrder->append('B:after');

                return $result;
            }
        };

        app()->instance($concernA::class, $concernA);
        app()->instance($concernB::class, $concernB);

        $service = new class (new ArrayInput([]), $concernA, $concernB) extends Service {
            /** @var class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern> */
            private string $classA;

            /** @var class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern> */
            private string $classB;

            /**
             * Create a new concern-pipeline spy service.
             *
             * @param  \SineMacula\ApiToolkit\Services\Input\ArrayInput  $input
             * @param  \SineMacula\ApiToolkit\Services\Contracts\ServiceConcern  $concernA
             * @param  \SineMacula\ApiToolkit\Services\Contracts\ServiceConcern  $concernB
             */
            public function __construct(ArrayInput $input, ServiceConcern $concernA, ServiceConcern $concernB)
            {
                parent::__construct($input);

                $this->classA = $concernA::class;
                $this->classB = $concernB::class;
            }

            /**
             * Return the concerns in declaration order.
             *
             * @return array<int, class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>>
             */
            #[\Override]
            protected function concerns(): array
            {
                return [$this->classA, $this->classB];
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

        (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        self::assertSame(['A:before', 'B:before', 'B:after', 'A:after'], iterator_to_array($callOrder));
    }
}
