<?php

declare(strict_types = 1);

namespace Tests\Unit\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\LockOperationException;
use SineMacula\ApiToolkit\Services\Actors\AnonymousActor;
use SineMacula\ApiToolkit\Services\Actors\SystemActor;
use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
use SineMacula\ApiToolkit\Services\Events\ServiceCompleted;
use SineMacula\ApiToolkit\Services\Events\ServiceFailed;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\ApiToolkit\Services\ServiceResult;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Concerns\SwapsExceptionHandler;
use Tests\Fixtures\Exceptions\ServiceExecutionException;
use Tests\Fixtures\Services\AuthorizingService;
use Tests\Fixtures\Services\ConcernedService;
use Tests\Fixtures\Services\Concerns\ShortCircuitConcern;
use Tests\Fixtures\Services\Concerns\WrappingConcern;
use Tests\Fixtures\Services\ContextRecordingService;
use Tests\Fixtures\Services\Input\ScrubbedInput;
use Tests\Fixtures\Services\Input\SensitiveInput;
use Tests\Fixtures\Services\OutputService;
use Tests\Fixtures\Services\StubActor;
use Tests\Fixtures\Services\TransactionAttemptsService;
use Tests\Fixtures\Services\TransactionRecordingService;
use Tests\Fixtures\Services\ValidatingUserService;
use Tests\TestCase;

/**
 * Tests for the ServiceRunner lifecycle orchestrator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceRunner::class)]
final class ServiceRunnerTest extends TestCase
{
    use SwapsExceptionHandler;

    /**
     * Test that the lifecycle hooks execute in their fixed order.
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
     * Test that a domain failure from handle() is captured, never thrown.
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
     * Test that an afterCommit throw leaves the committed result succeeded.
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
     * Test that onFailure runs and its throw does not mask the original
     * failure.
     *
     * @return void
     */
    public function testOnFailureRunsAndItsThrowDoesNotMaskTheFailure(): void
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
     * Test that an empty lockId() is captured as a LockOperationException.
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
     * Provide transaction attempt counts that would never run the transaction.
     *
     * @return iterable<string, array{int}>
     */
    public static function unusableTransactionAttemptsProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    /**
     * Test that a transactional service with fewer than one transaction attempt
     * fails after validation and before the lock, without running handle().
     *
     * @param  int  $attempts
     * @return void
     */
    #[DataProvider('unusableTransactionAttemptsProvider')]
    public function testTransactionAttemptsBelowOneFailTheRunBeforeTheLock(int $attempts): void
    {
        $service = new TransactionAttemptsService($attempts);
        $held    = Cache::lock($service->getLockKey(), 10);

        self::assertTrue($held->get());

        $result = (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        self::assertTrue($result->failed());
        self::assertInstanceOf(\LogicException::class, $result->exception);
        self::assertSame(sprintf('Service is transactional but $transactionAttempts is %d; it must be at least 1.', $attempts), $result->exception->getMessage());
        self::assertTrue($service->validated);
        self::assertFalse($service->handled);

        $held->release();
    }

    /**
     * Test that a non-transactional service ignores its transaction attempts.
     *
     * @return void
     */
    public function testTransactionAttemptsAreIgnoredWithoutATransaction(): void
    {
        $service = new TransactionAttemptsService(0, false);

        $result = (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        self::assertTrue($result->succeeded());
        self::assertTrue($service->handled);
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
     * Test that ServiceCompleted carries the scrubbed input summary.
     *
     * @return void
     */
    public function testCompletedEventCarriesScrubbedInputSummary(): void
    {
        Event::fake();

        $service = new class (new ScrubbedInput(username: 'alice', password: 'super-secret')) extends Service {
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

        Event::assertDispatched(
            ServiceCompleted::class,
            static fn (ServiceCompleted $event): bool => $event->inputSummary === ['username' => 'alice', 'password' => '[redacted]'],
        );
    }

    /**
     * Test that ServiceFailed carries the scrubbed input summary.
     *
     * @return void
     */
    public function testFailedEventCarriesScrubbedInputSummary(): void
    {
        Event::fake();

        $service = new class (new ScrubbedInput(username: 'alice', password: 'super-secret')) extends Service {
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
                throw new \RuntimeException('boom');
            }
        };

        (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        Event::assertDispatched(
            ServiceFailed::class,
            static fn (ServiceFailed $event): bool => $event->inputSummary === ['username' => 'alice', 'password' => '[redacted]'],
        );
    }

    /**
     * Test that a denied service gives listeners only the scrubbed summary.
     *
     * @return void
     */
    public function testFailureListenerReadsScrubbedInputSummary(): void
    {
        $captured = null;

        Event::listen(ServiceFailed::class, static function (ServiceFailed $event) use (&$captured): void {
            $captured = $event->inputSummary;
        });

        $service = new AuthorizingService(new ScrubbedInput(username: 'alice', password: 'super-secret'));

        $result = (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        self::assertTrue($result->failed());
        self::assertSame(['username' => 'alice', 'password' => '[redacted]'], $captured);
    }

    /**
     * Test that the ServiceCompleted summary omits a sensitive parameter.
     *
     * @return void
     */
    public function testCompletedEventOmitsSensitiveParameterFromInputSummary(): void
    {
        Event::fake();

        $service = new class (new SensitiveInput(username: 'alice', password: 'super-secret')) extends Service {
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

        Event::assertDispatched(
            ServiceCompleted::class,
            static fn (ServiceCompleted $event): bool => $event->inputSummary === ['username' => 'alice'],
        );
    }

    /**
     * Test that the ServiceFailed summary omits a sensitive parameter.
     *
     * @return void
     */
    public function testFailedEventOmitsSensitiveParameterFromInputSummary(): void
    {
        Event::fake();

        $service = new class (new SensitiveInput(username: 'alice', password: 'super-secret')) extends Service {
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
                throw new \RuntimeException('boom');
            }
        };

        (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        Event::assertDispatched(
            ServiceFailed::class,
            static fn (ServiceFailed $event): bool => $event->inputSummary === ['username' => 'alice'],
        );
    }

    /**
     * Test that concerns run in declaration order, the first outermost.
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

    /**
     * Test that a concern returning without calling the rest of the pipeline
     * skips prepare() and handle() and supplies the output.
     *
     * @return void
     */
    public function testShortCircuitingConcernSkipsPrepareAndHandleAndSuppliesTheOutput(): void
    {
        $service = new ConcernedService([ShortCircuitConcern::class, WrappingConcern::class]);

        $result = (new ServiceRunner)->run($service, ServiceContext::for(new StubActor));

        self::assertSame('stored', $result->output());
        self::assertSame(['afterCommit'], $service->calls);
        self::assertSame('stored', $service->committedOutput);
        self::assertSame(0, DB::table('users')->count());
    }

    /**
     * Test that a concern's return value becomes the output afterCommit() and
     * the completed event receive.
     *
     * @return void
     */
    public function testConcernReturnValueBecomesTheOutput(): void
    {
        Event::fake([ServiceCompleted::class]);

        $service = new ConcernedService([WrappingConcern::class]);

        $result = (new ServiceRunner)->run($service, ServiceContext::for(new StubActor));

        self::assertSame(['wrapped' => 'handled'], $result->output());
        self::assertSame(['prepare', 'handle', 'afterCommit'], $service->calls);
        self::assertSame(['wrapped' => 'handled'], $service->committedOutput);

        Event::assertDispatched(ServiceCompleted::class, static fn (ServiceCompleted $event): bool => $event->result->output() === ['wrapped' => 'handled']);
    }

    /**
     * Test that a concern class that does not implement the contract fails the
     * run with a TypeError before prepare(), without run() throwing.
     *
     * @return void
     */
    public function testNonConcernClassFailsTheRun(): void
    {
        $service = new ConcernedService([\stdClass::class]); // @phpstan-ignore argument.type

        $result = (new ServiceRunner)->run($service, ServiceContext::for(new StubActor));

        self::assertTrue($result->failed());
        self::assertInstanceOf(\TypeError::class, $result->exception);
        self::assertSame(['onFailure'], $service->calls);
        self::assertSame(0, DB::table('users')->count());
    }

    /**
     * Test that an afterCommit throw is reported as the captured instance.
     *
     * @return void
     */
    public function testAfterCommitThrowIsReported(): void
    {
        Exceptions::fake();

        $service = new class (new ArrayInput([])) extends Service {
            /**
             * Return a concrete output value.
             *
             * @return string
             */
            #[\Override]
            protected function handle(): mixed
            {
                return 'output';
            }

            /**
             * Throw a side-effect exception.
             *
             * @param  mixed  $output
             * @return void
             *
             * @throws \RuntimeException
             */
            #[\Override]
            protected function afterCommit(mixed $output): void
            {
                throw new \RuntimeException('after-commit-error');
            }
        };

        $result = (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        self::assertTrue($result->succeeded());
        self::assertCount(1, $result->sideEffectErrors());

        $sideEffect = $result->sideEffectErrors()[0];

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (\RuntimeException $e): bool => $e === $sideEffect);
    }

    /**
     * Test that a committed outcome survives a failed afterCommit report.
     *
     * @return void
     */
    public function testCommittedOutcomeSurvivesWhenAfterCommitReportingFails(): void
    {
        Event::fake();
        $handler = $this->useThrowingExceptionHandler();

        Log::shouldReceive('error')
            ->once()
            ->with('Service afterCommit threw an exception.', \Mockery::on(
                static fn (array $context): bool => $context['exception'] instanceof \RuntimeException
                    && $context['exception']->getMessage() === 'after-commit-error',
            ));

        $service = new class (new ArrayInput([])) extends Service {
            /** @var bool */
            public bool $onFailureCalled = false;

            /**
             * Return a concrete output value.
             *
             * @return string
             */
            #[\Override]
            protected function handle(): mixed
            {
                return 'output';
            }

            /**
             * Throw a side-effect exception.
             *
             * @param  mixed  $output
             * @return void
             *
             * @throws \RuntimeException
             */
            #[\Override]
            protected function afterCommit(mixed $output): void
            {
                throw new \RuntimeException('after-commit-error');
            }

            /**
             * Record that the failure hook ran.
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

        $result = (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        self::assertTrue($result->succeeded());
        self::assertSame('output', $result->output());
        self::assertFalse($service->onFailureCalled);
        Event::assertDispatched(ServiceCompleted::class);
        Event::assertNotDispatched(ServiceFailed::class);
        self::assertCount(1, $handler->reported);
        self::assertSame($result->sideEffectErrors()[0], $handler->reported[0]);
    }

    /**
     * Test that a reported onFailure throw does not mask the original failure.
     *
     * @return void
     */
    public function testOnFailureThrowIsReported(): void
    {
        Exceptions::fake();

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

            /**
             * Throw a secondary exception from the failure hook.
             *
             * @param  \Throwable  $exception
             * @return void
             *
             * @throws \RuntimeException
             */
            #[\Override]
            protected function onFailure(\Throwable $exception): void
            {
                throw new \RuntimeException('on-failure-error');
            }
        };

        $result = (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        self::assertTrue($result->failed());
        self::assertSame('domain error', $result->exception?->getMessage());

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (\RuntimeException $e): bool => $e->getMessage() === 'on-failure-error');
    }

    /**
     * Test that the completed event's duration covers the pipeline but not
     * afterCommit().
     *
     * @return void
     */
    public function testCompletedEventDurationCoversThePipelineButNotAfterCommit(): void
    {
        Event::fake();

        $service = new TransactionRecordingService(static function (): string {
            usleep(20000);

            return 'done';
        });

        $committedAt = 0.0;

        $service->onCommit = static function () use (&$committedAt): void {
            $committedAt = microtime(true);

            usleep(50000);
        };

        $start = microtime(true);

        (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        Event::assertDispatched(
            ServiceCompleted::class,
            static fn (ServiceCompleted $event): bool => $event->duration >= 0.02 && $event->duration <= $committedAt - $start,
        );
    }

    /**
     * Test that the failed event carries the elapsed duration in seconds.
     *
     * @return void
     */
    public function testFailedEventCarriesTheElapsedDurationInSeconds(): void
    {
        Event::fake();

        $service = new TransactionRecordingService(static function (): never {
            usleep(20000);

            throw new ServiceExecutionException('failed');
        });

        $start = microtime(true);

        (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        $elapsed = microtime(true) - $start;

        Event::assertDispatched(
            ServiceFailed::class,
            static fn (ServiceFailed $event): bool => $event->duration >= 0.02 && $event->duration <= $elapsed,
        );
    }

    /**
     * Test that a failed onFailure report falls back to the error log.
     *
     * @return void
     */
    public function testEventDispatchedEvenWhenFailureHandlingThrows(): void
    {
        Event::fake();
        $handler = $this->useThrowingExceptionHandler();

        Log::shouldReceive('error')
            ->once()
            ->with('Service onFailure threw an exception.', \Mockery::on(
                static fn (array $context): bool => $context['exception'] instanceof \RuntimeException
                    && $context['exception']->getMessage() === 'on-failure-error',
            ));

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

            /**
             * Throw a secondary exception from the failure hook.
             *
             * @param  \Throwable  $exception
             * @return void
             *
             * @throws \RuntimeException
             */
            #[\Override]
            protected function onFailure(\Throwable $exception): void
            {
                throw new \RuntimeException('on-failure-error');
            }
        };

        $result = (new ServiceRunner)->run($service, ServiceContext::for(new AnonymousActor));

        self::assertTrue($result->failed());
        self::assertSame('domain error', $result->exception?->getMessage());
        Event::assertDispatched(ServiceFailed::class);
        self::assertCount(1, $handler->reported);
        self::assertSame('on-failure-error', $handler->reported[0]->getMessage());
    }

    /**
     * Test that the run context is bound before the hooks are resolved.
     *
     * @return void
     */
    public function testRunBindsTheContextBeforeResolvingHooks(): void
    {
        $context = ServiceContext::for(new StubActor);
        $service = new ContextRecordingService(new ArrayInput([]));

        $result = (new ServiceRunner)->run($service, $context);

        self::assertTrue($result->succeeded());
        self::assertSame($context, $service->seen['concerns']);
        self::assertSame($context, $service->seen['lockId']);
    }

    /**
     * Test that the run context is bound on the failure path.
     *
     * @return void
     */
    public function testRunBindsTheContextOnTheFailurePath(): void
    {
        $context        = ServiceContext::for(new StubActor);
        $service        = new ContextRecordingService(new ArrayInput([]));
        $service->fails = true;

        $result = (new ServiceRunner)->run($service, $context);

        self::assertTrue($result->failed());
        self::assertInstanceOf(ServiceExecutionException::class, $result->exception);
        self::assertSame($context, $service->seen['onFailure']);
    }

    /**
     * Test that the run context is unbound once the run ends.
     *
     * @return void
     */
    public function testRunUnbindsTheContextWhenItEnds(): void
    {
        $service = new ContextRecordingService(new ArrayInput([]));

        (new ServiceRunner)->run($service, ServiceContext::for(new StubActor));

        self::assertNull($service->bindRunContext(null));
    }

    /**
     * Test that a throwing ServiceCompleted listener is reported, and the run
     * succeeds with its context unbound.
     *
     * @return void
     */
    public function testCompletedListenerThrowIsReportedAndTheRunSucceeds(): void
    {
        Exceptions::fake();

        $error = new \RuntimeException('listener-error');

        Event::listen(ServiceCompleted::class, static function () use ($error): never {
            throw $error;
        });

        $service = new ContextRecordingService(new ArrayInput([]));

        self::assertTrue((new ServiceRunner)->run($service, ServiceContext::for(new StubActor))->succeeded());
        self::assertNull($service->bindRunContext(null));

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (\RuntimeException $e): bool => $e === $error);
    }

    /**
     * Test that a throwing ServiceFailed listener is reported, and the run
     * keeps its original failure.
     *
     * @return void
     */
    public function testFailedListenerThrowIsReportedAndTheOriginalFailureStands(): void
    {
        Exceptions::fake();

        $error = new \RuntimeException('listener-error');

        Event::listen(ServiceFailed::class, static function () use ($error): never {
            throw $error;
        });

        $result = (new ServiceRunner)->run(new ValidatingUserService(new ArrayInput(['age' => 30])), ServiceContext::for(new StubActor));

        self::assertInstanceOf(ValidationException::class, $result->exception);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (\RuntimeException $e): bool => $e === $error);
    }

    /**
     * Test that a run inside a caller's transaction defers afterCommit() and
     * ServiceCompleted until the caller commits, rebinding the run context for
     * the hook and carrying its side-effect errors on the event.
     *
     * @return void
     */
    public function testNestedRunDefersItsSideEffectsUntilTheCallerCommits(): void
    {
        Exceptions::fake();

        $completed = [];

        Event::listen(ServiceCompleted::class, static function (ServiceCompleted $event) use (&$completed): void {
            $completed[] = $event;
        });

        $context = ServiceContext::for(new StubActor);
        $error   = new \RuntimeException('side-effect-error');
        $service = new TransactionRecordingService(static fn (): string => 'done');
        $result  = null;

        $service->onCommit = static fn (): never => throw $error;

        DB::transaction(static function () use ($service, $context, &$result, &$completed): void {
            $result = (new ServiceRunner)->run($service, $context);

            self::assertSame([], $service->commitLevels);
            self::assertSame([], $completed);
        });

        self::assertInstanceOf(ServiceResult::class, $result);
        self::assertTrue($result->succeeded());
        self::assertSame([], $result->sideEffectErrors());
        self::assertCount(1, $service->commitLevels);
        self::assertSame($context, $service->commitContext);
        self::assertNull($service->bindRunContext(null));
        self::assertCount(1, $completed);
        self::assertSame('done', $completed[0]->result->output());
        self::assertSame([$error], $completed[0]->result->sideEffectErrors());
    }

    /**
     * Test that a run inside a caller's transaction that rolls back runs
     * neither afterCommit() nor ServiceCompleted.
     *
     * @return void
     */
    public function testNestedRunSkipsItsSideEffectsWhenTheCallerRollsBack(): void
    {
        Event::fake([ServiceCompleted::class]);

        $service = new TransactionRecordingService(static fn (): string => 'done');

        DB::beginTransaction();

        (new ServiceRunner)->run($service, ServiceContext::for(new StubActor));

        DB::rollBack();

        self::assertSame([], $service->commitLevels);

        Event::assertNotDispatched(ServiceCompleted::class);
    }

    /**
     * Test that a run restores the context that was bound before it started.
     *
     * @return void
     */
    public function testRunRestoresThePreviouslyBoundContext(): void
    {
        $outer   = ServiceContext::for(new StubActor);
        $service = new ContextRecordingService(new ArrayInput([]));
        $service->bindRunContext($outer);

        (new ServiceRunner)->run($service, ServiceContext::for(new StubActor));

        self::assertSame($outer, $service->currentContext());
    }

    /**
     * Test that the pre-flight runs authorize() and validate() with the context
     * bound, then restores the one it replaced.
     *
     * @return void
     */
    public function testPreflightBindsTheContextAndRestoresThePrevious(): void
    {
        $outer   = ServiceContext::for(new StubActor);
        $context = ServiceContext::for(new StubActor);
        $service = new ContextRecordingService(new ArrayInput([]));
        $service->bindRunContext($outer);

        (new ServiceRunner)->preflight($service, $context);

        self::assertSame($context, $service->seen['authorize']);
        self::assertSame($context, $service->seen['validate']);
        self::assertArrayNotHasKey('handle', $service->seen);
        self::assertSame($outer, $service->currentContext());
    }

    /**
     * Test that the pre-flight skips authorize() for a SystemActor.
     *
     * @return void
     */
    public function testPreflightSkipsAuthorizationForASystemActor(): void
    {
        $service = new ContextRecordingService(new ArrayInput([]));

        (new ServiceRunner)->preflight($service, ServiceContext::for(new SystemActor));

        self::assertArrayNotHasKey('authorize', $service->seen);
        self::assertArrayHasKey('validate', $service->seen);
    }

    /**
     * Test that a refused pre-flight throws, unbinds the context, and runs no
     * failure hook or event.
     *
     * @return void
     */
    public function testRefusedPreflightThrowsWithoutAFailureHookOrEvent(): void
    {
        Event::fake([ServiceFailed::class]);

        $service = new AuthorizingService;

        $this->assertThrows(
            static fn () => (new ServiceRunner)->preflight($service, ServiceContext::for(new StubActor)),
            AuthorizationException::class,
        );

        self::assertNull($service->bindRunContext(null));
        Event::assertNotDispatched(ServiceFailed::class);
    }

    /**
     * Test that the final failure reaches onFinalFailure() with the context
     * bound, without resolving the other hooks.
     *
     * @return void
     */
    public function testFinalFailureRunsTheHookWithTheContextBound(): void
    {
        $context = ServiceContext::for(new StubActor);
        $service = new ContextRecordingService(new ArrayInput([]));

        (new ServiceRunner)->runFinalFailure($service, $context, new \RuntimeException('Gave up.'));

        self::assertSame(['onFinalFailure' => $context], $service->seen);
        self::assertNull($service->bindRunContext(null));
    }

    /**
     * Test that an onFinalFailure() throw is reported rather than thrown.
     *
     * @return void
     */
    public function testFinalFailureThrowIsReported(): void
    {
        Exceptions::fake();

        $service = new class (new ArrayInput([])) extends Service {
            /**
             * Complete the run.
             *
             * @return null
             */
            #[\Override]
            protected function handle(): null
            {
                return null;
            }

            /**
             * Throw from the final-failure hook.
             *
             * @param  \Throwable  $exception
             * @return void
             *
             * @throws \RuntimeException
             */
            #[\Override]
            protected function onFinalFailure(\Throwable $exception): void
            {
                throw new \RuntimeException('final-failure-error');
            }
        };

        (new ServiceRunner)->runFinalFailure($service, ServiceContext::for(new StubActor), new \RuntimeException('Gave up.'));

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (\RuntimeException $e): bool => $e->getMessage() === 'final-failure-error');
    }

    /**
     * Test that the completed event reports the run context's actor.
     *
     * @return void
     */
    public function testCompletedEventReportsTheContextActor(): void
    {
        Event::fake([ServiceCompleted::class]);

        $context = ServiceContext::for(new StubActor);
        $service = (new OutputService(new ArrayInput([])))->by(new AnonymousActor);

        (new ServiceRunner)->run($service, $context);

        Event::assertDispatched(
            ServiceCompleted::class,
            static fn (ServiceCompleted $event): bool => $event->actor === $context->actor,
        );
    }

    /**
     * Test that the failed event reports the run context's actor.
     *
     * @return void
     */
    public function testFailedEventReportsTheContextActor(): void
    {
        Event::fake([ServiceFailed::class]);

        $context        = ServiceContext::for(new StubActor);
        $service        = (new ContextRecordingService(new ArrayInput([])))->by(new AnonymousActor);
        $service->fails = true;

        (new ServiceRunner)->run($service, $context);

        Event::assertDispatched(
            ServiceFailed::class,
            static fn (ServiceFailed $event): bool => $event->actor === $context->actor,
        );
    }
}
