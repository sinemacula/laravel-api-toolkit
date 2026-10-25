<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

/**
 * Exception reported when the cache store fails or rejects a metadata read or
 * write, so the metadata is served without being cached.
 *
 * It is reported rather than thrown: an unavailable store costs performance,
 * not the request. The store failure, when there is one, is the previous
 * exception, and the report context names the operation, the store, and the
 * metadata key it failed on.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MetadataCacheUnavailableException extends \RuntimeException
{
    /**
     * Create a new metadata cache unavailable exception instance.
     *
     * @param  string  $message
     * @param  array<string, string|null>  $context
     * @param  \Throwable|null  $previous
     * @return void
     */
    public function __construct(

        // What went wrong and what the toolkit did about it
        string $message,

        /** The operation, store, and metadata key the failure occurred on. */
        private readonly array $context = [],

        // The store failure, when there is one
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Return the context the exception handler adds to the report.
     *
     * @return array<string, string|null>
     */
    public function context(): array
    {
        return $this->context;
    }
}
