<?php

namespace SineMacula\ApiToolkit\Providers\Registrars;

use Illuminate\Support\Facades\Request;
use SineMacula\ApiToolkit\Http\RequestCapabilities;

/**
 * Registers the deprecated request macros.
 *
 * Binds the trashed, export, and stream macros to the Request facade. Each
 * macro emits a deprecation notice and delegates to the RequestCapabilities
 * value object that replaces it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RequestMacroRegistrar
{
    /**
     * Register the deprecated request macros.
     *
     * @return void
     */
    public function register(): void
    {
        $this->registerTrashedMacros();
        $this->registerExportMacros();
        $this->registerStreamMacros();
    }

    /**
     * Register the deprecated trashed macros to the Request facade.
     *
     * @return void
     */
    private function registerTrashedMacros(): void
    {
        Request::macro('includeTrashed', \Closure::bind(function (): bool {

            @trigger_error(
                'Request::includeTrashed() is deprecated, use RequestCapabilities::fromRequest($request)->includeTrashed() instead.',
                E_USER_DEPRECATED,
            );

            return RequestCapabilities::fromRequest($this)->includeTrashed();
        }, new \Illuminate\Http\Request, \Illuminate\Http\Request::class));

        Request::macro('onlyTrashed', \Closure::bind(function (): bool {

            @trigger_error(
                'Request::onlyTrashed() is deprecated, use RequestCapabilities::fromRequest($request)->onlyTrashed() instead.',
                E_USER_DEPRECATED,
            );

            return RequestCapabilities::fromRequest($this)->onlyTrashed();
        }, new \Illuminate\Http\Request, \Illuminate\Http\Request::class));
    }

    /**
     * Register the deprecated export macros to the Request facade.
     *
     * @return void
     */
    private function registerExportMacros(): void
    {
        Request::macro('expectsExport', \Closure::bind(function (): bool {

            @trigger_error(
                'Request::expectsExport() is deprecated, use RequestCapabilities::fromRequest($request)->expectsExport() instead.',
                E_USER_DEPRECATED,
            );

            return RequestCapabilities::fromRequest($this)->expectsExport();
        }, new \Illuminate\Http\Request, \Illuminate\Http\Request::class));

        Request::macro('expectsCsv', \Closure::bind(function (): bool {

            @trigger_error(
                'Request::expectsCsv() is deprecated, use RequestCapabilities::fromRequest($request)->expectsCsv() instead.',
                E_USER_DEPRECATED,
            );

            return RequestCapabilities::fromRequest($this)->expectsCsv();
        }, new \Illuminate\Http\Request, \Illuminate\Http\Request::class));

        Request::macro('expectsXml', \Closure::bind(function (): bool {

            @trigger_error(
                'Request::expectsXml() is deprecated, use RequestCapabilities::fromRequest($request)->expectsXml() instead.',
                E_USER_DEPRECATED,
            );

            return RequestCapabilities::fromRequest($this)->expectsXml();
        }, new \Illuminate\Http\Request, \Illuminate\Http\Request::class));

        Request::macro('expectsPdf', \Closure::bind(function (): bool {

            @trigger_error(
                'Request::expectsPdf() is deprecated, use RequestCapabilities::fromRequest($request)->expectsPdf() instead.',
                E_USER_DEPRECATED,
            );

            return RequestCapabilities::fromRequest($this)->expectsPdf();
        }, new \Illuminate\Http\Request, \Illuminate\Http\Request::class));
    }

    /**
     * Register the deprecated stream macros to the Request facade.
     *
     * @return void
     */
    private function registerStreamMacros(): void
    {
        Request::macro('expectsStream', \Closure::bind(function (): bool {

            @trigger_error(
                'Request::expectsStream() is deprecated, use RequestCapabilities::fromRequest($request)->expectsStream() instead.',
                E_USER_DEPRECATED,
            );

            return RequestCapabilities::fromRequest($this)->expectsStream();
        }, new \Illuminate\Http\Request, \Illuminate\Http\Request::class));
    }
}
