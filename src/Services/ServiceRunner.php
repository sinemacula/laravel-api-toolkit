<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services;

use Illuminate\Support\Facades\Log;
use SineMacula\ApiToolkit\Exceptions\LockOperationException;
use SineMacula\ApiToolkit\Services\Actors\SystemActor;
use SineMacula\ApiToolkit\Services\Contracts\ServiceConcern;
use SineMacula\ApiToolkit\Services\Events\ServiceCompleted;
use SineMacula\ApiToolkit\Services\Events\ServiceFailed;
use SineMacula\ApiToolkit\Services\Pipeline\LockStage;
use SineMacula\ApiToolkit\Services\Pipeline\TransactionStage;

/**
 * Fixed lifecycle orchestrator for service actions.
 *
 * Sequences the lifecycle in a fixed, transaction-aware order: authorize ->
 * validate -> [lock] -> [tx] -> concerns -> prepare -> handle
 * -> commit -> release -> afterCommit; onFailure after rollback + release;
 * finally emits ServiceCompleted or ServiceFailed.
 *
 * Never throws for business failures; captures them in the result.
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
     * @param  \SineMacula\ApiToolkit\Services\Service<TInput, TOutput>  $service
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @return \SineMacula\ApiToolkit\Services\ServiceResult<TOutput>
     *
     * @phpstan-ignore return.type
     */
    public function run(Service $service, ServiceContext $context): ServiceResult
    {
        $start  = microtime(true);
        $hooks  = $service->serviceHooks();
        $result = ServiceResult::failure();

        try {
            $this->runPreFlight($hooks, $context);
            $output = $this->buildPipeline($service, $hooks, $context)();
            $result = ServiceResult::success($output, $this->runAfterCommit($hooks, $output));
        } catch (\Throwable $exception) {
            $this->runOnFailure($hooks, $exception);
            $result = ServiceResult::failure($exception);
        } finally {
            $this->dispatchEvent($service, $context, $result, $start);
        }

        return $result; // @phpstan-ignore return.type
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
     * Build the execution pipeline in fixed composition order: lock (outermost)
     * -> transaction -> concerns -> core.
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
            Log::error('Service afterCommit threw an exception.', ['exception' => $exception]);

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
            Log::error('Service onFailure threw an exception.', ['exception' => $e]);
        }
    }

    /**
     * Dispatch the appropriate observability event.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service  $service
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @param  \SineMacula\ApiToolkit\Services\ServiceResult<mixed>  $result
     * @param  float  $startTime
     * @return void
     *
     * @phpstan-ignore missingType.generics
     */
    private function dispatchEvent(Service $service, ServiceContext $context, ServiceResult $result, float $startTime): void
    {
        $duration      = microtime(true) - $startTime;
        $inputSummary  = $service->serviceHooks()['inputSummary'];
        $actor         = $service->actor();
        $serviceClass  = $service::class;
        $correlationId = $context->correlationId;

        if ($result->succeeded()) {
            event(new ServiceCompleted($actor, $serviceClass, $result, $duration, $correlationId, $inputSummary));

            return;
        }

        event(new ServiceFailed($actor, $serviceClass, $result, $duration, $correlationId, $inputSummary));
    }
}
