<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http;

use Illuminate\Http\Request;

/**
 * Request capabilities value object.
 *
 * Encapsulates the 2 boolean capability checks resolved from the current
 * request. Immutable once created.
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
     */
    private function __construct(

        /** Whether the request includes soft-deleted records. */
        private readonly bool $includeTrashed,

        /** Whether the request returns only soft-deleted records. */
        private readonly bool $onlyTrashed,
    ) {}

    /**
     * Retrieve the capabilities instance from the request.
     *
     * Resolves the capabilities on first call and caches them on the request,
     * returning the cached instance thereafter.
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

        return new self(
            $includeTrashed,
            $onlyTrashed,
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
}
