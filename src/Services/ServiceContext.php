<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Services\Contracts\Actor;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;

/**
 * Immutable, queue-serialisable execution-context envelope.
 *
 * Carries the actor that initiated the service, a correlation id (generated
 * when absent), the execution source, and captured ambient metadata such as IP
 * address, user-agent, or request id. Metadata is supplied explicitly at the
 * capture site, such as fromRequest() at an HTTP edge, and a service reads it
 * through Service::context() rather than from ambient state.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ServiceContext
{
    /** @var string The metadata key fromRequest() stores the client IP address under */
    public const string METADATA_IP = 'ip';

    /** @var string The metadata key fromRequest() stores the client user agent under */
    public const string METADATA_USER_AGENT = 'user_agent';

    /** @var string The config key naming the request header the correlation id is read from */
    public const string CORRELATION_HEADER_KEY = 'api-toolkit.services.correlation_header';

    /** @var string The correlation header read when the config key is absent */
    private const string DEFAULT_CORRELATION_HEADER = 'X-Request-Id';

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

    /**
     * Create an HTTP ServiceContext for the given request and actor.
     *
     * Captures the client IP address and user agent under METADATA_IP and
     * METADATA_USER_AGENT, with the given metadata merged over them. The
     * correlation id is the configured header's value when it is 1 to 128
     * printable ASCII characters with no whitespace once trimmed, and a
     * generated one otherwise or when the header is not configured.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\ApiToolkit\Services\Contracts\Actor  $actor
     * @param  array<string, mixed>  $metadata
     * @return self
     */
    public static function fromRequest(Request $request, Actor $actor, array $metadata = []): self
    {
        return self::for(
            actor: $actor,
            source: ServiceSource::HTTP,
            metadata: [
                self::METADATA_IP         => $request->ip(),
                self::METADATA_USER_AGENT => $request->userAgent(),
                ...$metadata,
            ],
            correlationId: self::correlationIdFrom($request),
        );
    }

    /**
     * Return the request's correlation id when its configured header carries a
     * usable one.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    private static function correlationIdFrom(Request $request): ?string
    {
        $header = Config::get(self::CORRELATION_HEADER_KEY, self::DEFAULT_CORRELATION_HEADER);
        $value  = is_string($header) ? trim((string) $request->headers->get($header)) : '';

        return preg_match('/^[\x21-\x7E]{1,128}$/', $value) === 1 ? $value : null;
    }
}
