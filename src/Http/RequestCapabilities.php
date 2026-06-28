<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http;

use Illuminate\Http\Request;
use SineMacula\Http\Enums\HttpHeader;
use SineMacula\Http\Enums\MediaType;

/**
 * Request capabilities value object.
 *
 * Encapsulates the 4 boolean capability checks resolved from the
 * current request. Immutable once created.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RequestCapabilities
{
    /** @var string The request attribute key used to store the instance. */
    private const string REQUEST_ATTRIBUTE_KEY = self::class;

    /**
     * Create a new RequestCapabilities instance.
     *
     * @param  bool  $includeTrashed
     * @param  bool  $onlyTrashed
     * @param  bool  $expectsPdf
     * @param  bool  $expectsStream
     */
    private function __construct(

        /** Whether the request includes soft-deleted records. */
        private readonly bool $includeTrashed,

        /** Whether the request returns only soft-deleted records. */
        private readonly bool $onlyTrashed,

        /** Whether the request expects a PDF response. */
        private readonly bool $expectsPdf,

        /** Whether the request expects a streamed response. */
        private readonly bool $expectsStream,
    ) {}

    /**
     * Retrieve the capabilities instance from the request.
     *
     * Returns the instance stored by the DetectsCapabilities middleware
     * when available, otherwise resolves the capabilities on demand and
     * caches them on the request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return self
     */
    public static function fromRequest(Request $request): self
    {
        $capabilities = $request->attributes->get(self::REQUEST_ATTRIBUTE_KEY);

        if ($capabilities instanceof self) {
            return $capabilities;
        }

        $capabilities = self::resolve($request);

        self::storeOnRequest($request, $capabilities);

        return $capabilities;
    }

    /**
     * Resolve all capability values from the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return self
     */
    public static function resolve(Request $request): self
    {
        $includeTrashed = $request->input('include_trashed', false) === 'true';
        $onlyTrashed    = $request->input('only_trashed', false)    === 'true';

        /** @var string $acceptRaw */
        $acceptRaw    = $request->header(HttpHeader::ACCEPT->getName(), '');
        $acceptHeader = strtolower($acceptRaw);

        $expectsPdf    = $acceptHeader === MediaType::APPLICATION_PDF->getMimeType();
        $expectsStream = $acceptHeader === MediaType::TEXT_EVENT_STREAM->getMimeType();

        return new self(
            $includeTrashed,
            $onlyTrashed,
            $expectsPdf,
            $expectsStream,
        );
    }

    /**
     * Store the capabilities instance on the request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  self  $capabilities
     * @return void
     */
    public static function storeOnRequest(Request $request, self $capabilities): void
    {
        $request->attributes->set(self::REQUEST_ATTRIBUTE_KEY, $capabilities);
    }

    /**
     * Whether the request includes soft-deleted records.
     *
     * @return bool
     *
     * @imperative
     */
    public function includeTrashed(): bool
    {
        return $this->includeTrashed;
    }

    /**
     * Whether the request returns only soft-deleted records.
     *
     * @return bool
     *
     * @imperative
     */
    public function onlyTrashed(): bool
    {
        return $this->onlyTrashed;
    }

    /**
     * Whether the request expects a PDF response.
     *
     * @return bool
     */
    public function expectsPdf(): bool
    {
        return $this->expectsPdf;
    }

    /**
     * Whether the request expects a streamed response.
     *
     * @return bool
     */
    public function expectsStream(): bool
    {
        return $this->expectsStream;
    }
}
