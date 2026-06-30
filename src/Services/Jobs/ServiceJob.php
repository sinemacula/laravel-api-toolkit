<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\ApiToolkit\Services\ServiceRunner;

/**
 * Queue bridge for service actions.
 *
 * Serialises the service class-string, the typed input, and the execution
 * context (including the actor reference) onto the queue. On the worker,
 * re-hydrates and runs the service identically via ServiceRunner with the
 * source forced to QUEUE - no Auth or Request is consulted (NFR-07).
 *
 * Naming deviation: jobs are normally named with a leading verb; this generic
 * bridge runs an arbitrary service so the Job suffix is used instead
 * (#php-nam-063).
 *
 * Limitation: subject-bearing services that require a Model constructor
 * argument beyond the input are out of scope for this bridge; the subject
 * identifier should ride in the input. See UPGRADE.md for the planned
 * subject-aware variant.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Constructor.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Services\Service<\SineMacula\ApiToolkit\Services\Contracts\ServiceInput, mixed>>  $service
     * @param  \SineMacula\ApiToolkit\Services\Contracts\ServiceInput  $input
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     */
    public function __construct(

        /** The fully-qualified class name of the service to run */
        public readonly string $service,

        /** The typed input for the service invocation */
        public readonly ServiceInput $input,

        /** The execution context carrying the actor and correlation id */
        public readonly ServiceContext $context,
    ) {}

    /**
     * Re-hydrate and run the service on the worker.
     *
     * Builds the service from the serialised class-string and input, attaches
     * the dispatched actor, then runs it through ServiceRunner with a fresh
     * context whose source is forced to QUEUE.
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceRunner  $runner
     * @return void
     */
    public function handle(ServiceRunner $runner): void
    {
        $service = ($this->service)::make($this->input)->by($this->context->actor); // @phpstan-ignore staticMethod.dynamicName

        $context = new ServiceContext(
            actor: $this->context->actor,
            correlationId: $this->context->correlationId,
            source: ServiceSource::QUEUE,
            metadata: $this->context->metadata,
        );

        $runner->run($service, $context);
    }
}
