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
 * Services needing constructor arguments beyond the input are not supported;
 * carry the subject identifier in the input instead.
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
     * @param  class-string<\SineMacula\ApiToolkit\Services\Service<covariant \SineMacula\ApiToolkit\Services\Contracts\ServiceInput, covariant mixed>>  $service
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
        $service = ($this->service)::make($this->input)->by($this->context->actor); // @phpstan-ignore staticMethod.dynamicName

        $context = new ServiceContext(
            actor: $this->context->actor,
            correlationId: $this->context->correlationId,
            source: ServiceSource::QUEUE,
            metadata: $this->context->metadata,
        );

        $runner->run($service, $context)->throw();
    }
}
