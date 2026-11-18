<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services;

use Illuminate\Support\Facades\DB;
use SineMacula\ApiToolkit\Exceptions\FailureReporter;
use SineMacula\ApiToolkit\Exceptions\LockOperationException;
use SineMacula\ApiToolkit\Services\Actors\SystemActor;
use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
use SineMacula\ApiToolkit\Services\Events\ServiceCompleted;
use SineMacula\ApiToolkit\Services\Events\ServiceFailed;
use SineMacula\ApiToolkit\Services\Pipeline\LockStage;
use SineMacula\ApiToolkit\Services\Pipeline\TransactionStage;

/**
 * Runs a service lifecycle in a fixed, transaction-aware order.
 *
 * authorize -> validate -> [lock] -> [tx] -> concerns -> prepare -> handle ->
 * commit -> release -> afterCommit, with onFailure after rollback and release.
 * Release, afterCommit and ServiceCompleted follow the outermost commit.
 * onFinalFailure runs only when the queue gives up on a job.
 *
 * @phpstan-type ServiceHooks array{
 *     authorize: \Closure(): void,
 *     validate: \Closure(): void,
 *     prepare: \Closure(): void,
 *     handle: \Closure(): mixed,
 *     afterCommit: \Closure(mixed): void,
 *     onFailure: \Closure(\Throwable): void,
 *     concerns: array<int,
 * class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>>,
 *     lockId: string,
 *     transactional: bool,
 *     transactionAttempts: int,
 *     lockable: bool,
 *     inputSummary: array<string, mixed>,
 * }
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ServiceRunner
{
    /**
     * Execute the service lifecycle and return a total result.
     *
     * @template TInput of \SineMacula\ApiToolkit\Services\Contracts\ServiceInput
     * @template TOutput
     *
     * @param  \SineMacula\ApiToolkit\Services\Service<covariant TInput, covariant TOutput>  $service
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @return \SineMacula\ApiToolkit\Services\ServiceResult<TOutput>
     *
     * @phpstan-ignore return.type
     */
    public function run(Service $service, ServiceContext $context): ServiceResult
    {
        return $this->bound($service, $context, fn (): ServiceResult => $this->execute($service, $context));
    }

    /**
     * Run authorize() and validate() without the rest of the lifecycle,
     * throwing a refusal to the caller.
     *
     * No run takes place, so no event fires and onFailure() is not called.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service  $service
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @return void
     *
     * @phpstan-ignore missingType.generics
     */
    public function preflight(Service $service, ServiceContext $context): void
    {
        $this->bound($service, $context, function () use ($service, $context): void {
            $this->runPreFlight($service->serviceHooks(), $context);
        });
    }

    /**
     * Relay a queued job's terminal failure to the service's onFinalFailure().
     *
     * The other hooks are not rebuilt, so a lockId() that cannot be resolved
     * after the failed run does not prevent it. Its exceptions are reported.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service  $service
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @param  \Throwable  $exception
     * @return void
     *
     * @phpstan-ignore missingType.generics
     */
    public function runFinalFailure(Service $service, ServiceContext $context, \Throwable $exception): void
    {
        $this->bound($service, $context, static function () use ($service, $exception): void {
            try {
                ($service->finalFailureHook())($exception);
            } catch (\Throwable $e) {
                FailureReporter::report($e, 'Service onFinalFailure threw an exception.');
            }
        });
    }

    /**
     * Call the callback with the context bound to the service, restoring the
     * context it replaces afterwards.
     *
     * @template TReturn
     *
     * @param  \SineMacula\ApiToolkit\Services\Service  $service
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     *
     * @phpstan-ignore missingType.generics
     */
    private function bound(Service $service, ServiceContext $context, \Closure $callback): mixed
    {
        $previous = $service->bindRunContext($context);

        try {
            return $callback();
        } finally {
            $service->bindRunContext($previous);
        }
    }

    /**
     * Sequence the lifecycle with the context already bound to the service.
     *
     * @template TInput of \SineMacula\ApiToolkit\Services\Contracts\ServiceInput
     * @template TOutput
     *
     * @param  \SineMacula\ApiToolkit\Services\Service<covariant TInput, covariant TOutput>  $service
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @return \SineMacula\ApiToolkit\Services\ServiceResult<TOutput>
     *
     * @phpstan-ignore return.type
     */
    private function execute(Service $service, ServiceContext $context): ServiceResult
    {
        $start = microtime(true);
        $hooks = $service->serviceHooks();

        try {
            $this->runPreFlight($hooks, $context);
            $output = $this->buildPipeline($service, $hooks, $context)();
        } catch (\Throwable $exception) {
            $duration = microtime(true) - $start;
            $result   = ServiceResult::failure($exception);

            $this->runOnFailure($hooks, $exception);
            $this->dispatchEvent($service, $context, $result, $duration, $hooks['inputSummary']);

            return $result; // @phpstan-ignore return.type
        }

        return $this->complete($service, $context, $hooks, $output, microtime(true) - $start); // @phpstan-ignore return.type
    }

    /**
     * Return the successful result, running afterCommit and dispatching
     * ServiceCompleted once the outermost transaction commits.
     *
     * With no transaction open the callback runs at once and fills the returned
     * result; inside a caller's transaction it is discarded on rollback.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service  $service
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @param  ServiceHooks  $hooks
     * @param  mixed  $output
     * @param  float  $duration
     * @return \SineMacula\ApiToolkit\Services\ServiceResult<mixed>
     *
     * @phpstan-ignore missingType.generics
     */
    private function complete(Service $service, ServiceContext $context, array $hooks, mixed $output, float $duration): ServiceResult
    {
        $result = ServiceResult::success($output);

        DB::afterCommit(function () use ($service, $context, $hooks, $output, $duration, &$result): void {
            $previous = $service->bindRunContext($context);
            $result   = ServiceResult::success($output, $this->runAfterCommit($hooks, $output));

            $service->bindRunContext($previous);

            $this->dispatchEvent($service, $context, $result, $duration, $hooks['inputSummary']);
        });

        return $result;
    }

    /**
     * Run pre-flight hooks that execute before any lock or transaction.
     *
     * SystemActor short-circuits the authorize step; all actors run validate.
     *
     * @param  array{authorize: \Closure(): void, validate: \Closure(): void}  $hooks
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @return void
     */
    private function runPreFlight(array $hooks, ServiceContext $context): void
    {
        if (!($context->actor instanceof SystemActor)) {
            ($hooks['authorize'])();
        }

        ($hooks['validate'])();
    }

    /**
     * Build the pipeline: lock (outermost) -> transaction -> concerns -> core.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service  $service
     * @param  ServiceHooks  $hooks
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @return \Closure(): mixed
     *
     * @phpstan-ignore missingType.generics
     */
    private function buildPipeline(Service $service, array $hooks, ServiceContext $context): \Closure
    {
        $core = fn (): mixed => $this->executeCore($hooks);
        $core = $this->wrapConcerns($core, $hooks['concerns'], $context);

        if ($hooks['transactional']) {
            $this->guardTransactionAttempts($hooks['transactionAttempts']);
            $core = $this->wrapTransaction($core, $hooks['transactionAttempts']);
        }

        if ($hooks['lockable']) {
            $this->guardLockId($hooks['lockId']);
            $core = $this->wrapLock($service, $core);
        }

        return $core;
    }

    /**
     * Execute the core lifecycle: prepare then handle.
     *
     * @param  array{prepare: \Closure(): void, handle: \Closure(): mixed}  $hooks
     * @return mixed
     */
    private function executeCore(array $hooks): mixed
    {
        ($hooks['prepare'])();

        return ($hooks['handle'])();
    }

    /**
     * Fold concerns around the core in declaration order.
     *
     * @param  \Closure(): mixed  $core
     * @param  array<int, class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>>  $concerns
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @return \Closure(): mixed
     */
    private function wrapConcerns(\Closure $core, array $concerns, ServiceContext $context): \Closure
    {
        $pipeline = $core;

        foreach (array_reverse($concerns) as $concernClass) {
            $next     = $pipeline;
            $pipeline = fn (): mixed => $this->resolveConcern($concernClass)->handle($context, $next);
        }

        return $pipeline;
    }

    /**
     * Wrap the pipeline in a database transaction.
     *
     * @param  \Closure(): mixed  $core
     * @param  int  $attempts
     * @return \Closure(): mixed
     */
    private function wrapTransaction(\Closure $core, int $attempts): \Closure
    {
        $class = TransactionStage::class;

        return fn (): mixed => app($class)->wrap($core, $attempts);
    }

    /**
     * Guard that a transactional service attempts its transaction.
     *
     * @param  int  $attempts
     * @return void
     *
     * @throws \LogicException
     */
    private function guardTransactionAttempts(int $attempts): void
    {
        if ($attempts < 1) {
            throw new \LogicException(sprintf('Service is transactional but $transactionAttempts is %d; it must be at least 1.', $attempts));
        }
    }

    /**
     * Guard that the service supplies a non-empty lock identity.
     *
     * @param  string  $lockId
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\LockOperationException
     */
    private function guardLockId(string $lockId): void
    {
        if ($lockId === '') {
            throw new LockOperationException('Service is lockable but lockId() returned an empty string.');
        }
    }

    /**
     * Wrap the pipeline in the cache lock stage.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service  $service
     * @param  \Closure(): mixed  $core
     * @return \Closure(): mixed
     *
     * @phpstan-ignore missingType.generics
     */
    private function wrapLock(Service $service, \Closure $core): \Closure
    {
        $class = LockStage::class;

        return fn (): mixed => app($class)->wrap($service, $core);
    }

    /**
     * Resolve a concern instance from the container.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>  $class
     * @return \SineMacula\ApiToolkit\Services\Contracts\ServiceConcern
     */
    private function resolveConcern(string $class): ServiceConcern
    {
        return app($class);
    }

    /**
     * Run afterCommit outside the transaction and capture any errors.
     *
     * @param  array{afterCommit: \Closure(mixed): void}  $hooks
     * @param  mixed  $output
     * @return array<int, \Throwable>
     */
    private function runAfterCommit(array $hooks, mixed $output): array
    {
        try {
            ($hooks['afterCommit'])($output);

            return [];
        } catch (\Throwable $exception) {
            FailureReporter::report($exception, 'Service afterCommit threw an exception.');

            return [$exception];
        }
    }

    /**
     * Run onFailure after rollback and lock release.
     *
     * @param  array{onFailure: \Closure(\Throwable): void}  $hooks
     * @param  \Throwable  $exception
     * @return void
     */
    private function runOnFailure(array $hooks, \Throwable $exception): void
    {
        try {
            ($hooks['onFailure'])($exception);
        } catch (\Throwable $e) {
            FailureReporter::report($e, 'Service onFailure threw an exception.');
        }
    }

    /**
     * Dispatch the appropriate observability event, reporting a listener's
     * exception rather than letting it replace the outcome.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service  $service
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @param  \SineMacula\ApiToolkit\Services\ServiceResult<mixed>  $result
     * @param  float  $duration
     * @param  array<string, mixed>  $inputSummary
     * @return void
     *
     * @phpstan-ignore missingType.generics
     */
    private function dispatchEvent(Service $service, ServiceContext $context, ServiceResult $result, float $duration, array $inputSummary): void
    {
        $actor         = $context->actor;
        $serviceClass  = $service::class;
        $correlationId = $context->correlationId;

        $event = $result->succeeded()
            ? new ServiceCompleted($actor, $serviceClass, $result, $duration, $correlationId, $inputSummary)
            : new ServiceFailed($actor, $serviceClass, $result, $duration, $correlationId, $inputSummary);

        try {
            event($event);
        } catch (\Throwable $exception) {
            FailureReporter::report($exception, 'A service event listener threw an exception.');
        }
    }
}
