<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Jobs;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\CountCrashesAsExceptions;
use Illuminate\Queue\Attributes\Delay;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\ReadsQueueAttributes;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Queue\Concerns\ResolvesQueueRoutes;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Exceptions\ServiceFailedException;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\Input\Payload;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\ApiToolkit\Services\ServiceResult;
use SineMacula\ApiToolkit\Services\ServiceRunner;

/**
 * Queue bridge for service actions.
 *
 * Carries the queue settings the service declares with Laravel's attributes and
 * markers. Services needing constructor arguments beyond the input are not
 * supported; carry the subject identifier in the input instead.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ServiceJob implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, ReadsQueueAttributes, ResolvesQueueRoutes, SerializesModels;

    /** @var int|null The number of attempts; null defers to the worker */
    public ?int $tries = null;

    /** @var int|null The seconds an attempt may run; null defers to the worker */
    public ?int $timeout = null;

    /** @var array<int>|int|null The seconds to wait before each retry */
    public array|int|null $backoff = null;

    /** @var int|null The number of exceptions allowed before the job fails */
    public ?int $maxExceptions = null;

    /** @var bool Whether a timed-out attempt fails the job */
    public bool $failOnTimeout = false;

    /** @var bool Whether a worker crash counts as an exception */
    public bool $countCrashesAsExceptions = false; // phpcs:ignore SineMacula.Classes.RequireReadonlyPublicProperty.Mutable

    /** @var bool Whether the queued payload is encrypted */
    public bool $shouldBeEncrypted = false;

    /**
     * Constructor.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Services\Service<covariant \SineMacula\ApiToolkit\Services\Contracts\ServiceInput, covariant mixed>>  $service
     * @param  \SineMacula\ApiToolkit\Services\Contracts\ServiceInput  $input
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     */
    private function __construct(

        /** The fully-qualified class name of the service to run */
        public readonly string $service,

        /** The typed input for the service invocation */
        public readonly ServiceInput $input,

        /** The execution context carrying the actor and correlation id */
        public readonly ServiceContext $context,
    ) {}

    /**
     * Build the job for a service, copying the queue settings it declares.
     *
     * An undeclared connection or queue falls back to the service's
     * Queue::route(), then to the job's.
     *
     * @internal
     *
     * @param  \SineMacula\ApiToolkit\Services\Service<covariant \SineMacula\ApiToolkit\Services\Contracts\ServiceInput, covariant mixed>  $service
     * @param  \SineMacula\ApiToolkit\Services\Contracts\ServiceInput  $input
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @return self
     */
    public static function for(Service $service, ServiceInput $input, ServiceContext $context): self
    {
        $job = new self($service::class, $input, $context);

        $job->onConnection($job->declared($service, Connection::class)->connection ?? $job->resolveConnectionFromQueueRoute($service));
        $job->onQueue($job->declared($service, Queue::class)->queue ?? $job->resolveQueueFromQueueRoute($service));
        $job->delay($job->declared($service, Delay::class)?->delay);

        $job->tries                    = $job->declared($service, Tries::class)?->tries;
        $job->timeout                  = $job->declared($service, Timeout::class)?->timeout;
        $job->backoff                  = $job->declared($service, Backoff::class)?->backoff;
        $job->maxExceptions            = $job->declared($service, MaxExceptions::class)?->maxExceptions;
        $job->failOnTimeout            = $job->declared($service, FailOnTimeout::class)            !== null;
        $job->countCrashesAsExceptions = $job->declared($service, CountCrashesAsExceptions::class) !== null;

        if ($service instanceof ShouldQueueAfterCommit) {
            $job->afterCommit = true;
        }

        $job->shouldBeEncrypted = $service instanceof ShouldBeEncrypted
            || ($input instanceof Payload && $input::declaresSensitiveProperties());

        return $job;
    }

    /**
     * Name the job after the service it runs.
     *
     * @return string
     */
    public function displayName(): string
    {
        return $this->service;
    }

    /**
     * Skip a job whose batch was cancelled, and fail a refusal at once, since
     * the same input for the same actor is refused again.
     *
     * Built per execution, as the refusal middleware holds a closure.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new SkipIfBatchCancelled,
            new FailOnException([AuthorizationException::class, ValidationException::class]),
        ];
    }

    /**
     * Re-hydrate and run the service on the worker with a QUEUE source.
     *
     * A failed run throws so the worker fails the job.
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceRunner  $runner
     * @return void
     *
     * @throws \Throwable
     */
    public function handle(ServiceRunner $runner): void
    {
        $runner->run($this->rebuildService(), $this->workerContext())->throw();
    }

    /**
     * Relay the queue's terminal failure to the service.
     *
     * @param  \Throwable|null  $exception
     * @return void
     */
    public function failed(?\Throwable $exception): void
    {
        $runner = App::make(ServiceRunner::class);

        assert($runner instanceof ServiceRunner);

        $runner->runFinalFailure($this->rebuildService(), $this->workerContext(), $exception ?? new ServiceFailedException(ServiceResult::failure()));
    }

    /**
     * Return the given attribute as the service, a parent or a trait declares
     * it.
     *
     * @template TAttribute of object
     *
     * @param  \SineMacula\ApiToolkit\Services\Service<covariant \SineMacula\ApiToolkit\Services\Contracts\ServiceInput, covariant mixed>  $service
     * @param  class-string<TAttribute>  $attribute
     * @return TAttribute|null
     */
    private function declared(Service $service, string $attribute): ?object
    {
        return $this->getAttributeInstance($service, $attribute); // @phpstan-ignore return.type
    }

    /**
     * Resolve the service with the dispatch-time input and actor.
     *
     * @return \SineMacula\ApiToolkit\Services\Service<covariant \SineMacula\ApiToolkit\Services\Contracts\ServiceInput, covariant mixed>
     */
    private function rebuildService(): Service
    {
        return ($this->service)::make($this->input)->by($this->context->actor); // @phpstan-ignore staticMethod.dynamicName
    }

    /**
     * Rebuild the dispatch-time context with a QUEUE source and the batch.
     *
     * @return \SineMacula\ApiToolkit\Services\ServiceContext
     */
    private function workerContext(): ServiceContext
    {
        return new ServiceContext(
            actor: $this->context->actor,
            correlationId: $this->context->correlationId,
            source: ServiceSource::QUEUE,
            metadata: $this->context->metadata,
            batchId: $this->batchId,
        );
    }
}
