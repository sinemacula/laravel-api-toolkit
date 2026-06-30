<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services;

use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Services\Contracts\Actor;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;

/**
 * Immutable, queue-serialisable execution-context envelope.
 *
 * Carries the actor that initiated the service, a correlation id (generated
 * when absent), the execution source, and captured ambient metadata such as IP
 * address, user-agent, or request id. Metadata is supplied explicitly at the
 * capture site and never read ambiently inside services.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ServiceContext
{
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Services\Contracts\Actor  $actor
     * @param  string  $correlationId
     * @param  \SineMacula\ApiToolkit\Services\Enums\ServiceSource  $source
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(

        /** The actor that initiated the service action */
        public Actor $actor,

        /** Unique correlation id for this execution */
        public string $correlationId,

        /** The execution source (HTTP, QUEUE, CONSOLE, INTERNAL) */
        public ServiceSource $source,

        /** Captured ambient metadata supplied at the capture site */
        public array $metadata = [],
    ) {}

    /**
     * Create a ServiceContext for the given actor.
     *
     * When no correlation id is supplied, a UUID is generated. When no source
     * is supplied, it defaults to INTERNAL.
     *
     * @param  \SineMacula\ApiToolkit\Services\Contracts\Actor  $actor
     * @param  \SineMacula\ApiToolkit\Services\Enums\ServiceSource|null  $source
     * @param  array<string, mixed>  $metadata
     * @param  string|null  $correlationId
     * @return self
     */
    public static function for(Actor $actor, ?ServiceSource $source = null, array $metadata = [], ?string $correlationId = null): self
    {
        return new self(
            actor: $actor,
            correlationId: $correlationId ?? (string) Str::uuid(),
            source: $source               ?? ServiceSource::INTERNAL,
            metadata: $metadata,
        );
    }
}
