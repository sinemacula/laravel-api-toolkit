<?php

namespace SineMacula\ApiToolkit\Http;

use Illuminate\Http\Request;
use SineMacula\Http\Enums\HttpHeader;
use SineMacula\Http\Enums\MediaType;

/**
 * Request capabilities value object.
 *
 * Encapsulates the 7 boolean capability checks resolved from the
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
     * @param  bool  $expectsExport
     * @param  bool  $expectsCsv
     * @param  bool  $expectsXml
     * @param  bool  $expectsPdf
     * @param  bool  $expectsStream
     */
    private function __construct(

        /** Whether the request includes soft-deleted records. */
        private readonly bool $includeTrashed,

        /** Whether the request returns only soft-deleted records. */
        private readonly bool $onlyTrashed,

        /** Whether the request expects an export response. */
        private readonly bool $expectsExport,

        /** Whether the request expects a CSV response. */
        private readonly bool $expectsCsv,

        /** Whether the request expects an XML response. */
        private readonly bool $expectsXml,

        /** Whether the request expects a PDF response. */
        private readonly bool $expectsPdf,

        /** Whether the request expects a streamed response. */
        private readonly bool $expectsStream,

    ) {}

    /**
     * Retrieve the capabilities instance from the request.
     *
     * Returns a default instance with all capabilities set to false
     * if the middleware has not yet run.
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

        return new self(false, false, false, false, false, false, false);
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

        $supportedFormats = config('api-toolkit.exports.supported_formats', []);
        $exportsEnabled   = (bool) config('api-toolkit.exports.enabled');

        $expectsCsv = $acceptHeader === MediaType::TEXT_CSV->getMimeType()
            && is_array($supportedFormats)
            && in_array('csv', $supportedFormats, true);

        $expectsXml = $acceptHeader === MediaType::APPLICATION_XML->getMimeType()
            && is_array($supportedFormats)
            && in_array('xml', $supportedFormats, true);

        $expectsExport = $exportsEnabled && ($expectsCsv || $expectsXml);
        $expectsPdf    = $acceptHeader === MediaType::APPLICATION_PDF->getMimeType();
        $expectsStream = $acceptHeader === MediaType::TEXT_EVENT_STREAM->getMimeType();

        return new self(
            $includeTrashed,
            $onlyTrashed,
            $expectsExport,
            $expectsCsv,
            $expectsXml,
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
     */
    public function includeTrashed(): bool
    {
        return $this->includeTrashed;
    }

    /**
     * Whether the request returns only soft-deleted records.
     *
     * @return bool
     */
    public function onlyTrashed(): bool
    {
        return $this->onlyTrashed;
    }

    /**
     * Whether the request expects an export response.
     *
     * @return bool
     */
    public function expectsExport(): bool
    {
        return $this->expectsExport;
    }

    /**
     * Whether the request expects a CSV response.
     *
     * @return bool
     */
    public function expectsCsv(): bool
    {
        return $this->expectsCsv;
    }

    /**
     * Whether the request expects an XML response.
     *
     * @return bool
     */
    public function expectsXml(): bool
    {
        return $this->expectsXml;
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
