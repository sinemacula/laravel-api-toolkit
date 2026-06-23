<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Middleware\Concerns;

use SineMacula\ApiToolkit\Exceptions\RequestSignatureException;

/**
 * Throttle requests trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait ThrottleRequestsTrait
{
    /**
     * Resolve request signature.
     *
     * phpcs:disable Squiz.Commenting.FunctionComment.ScalarTypeHintMissing,Squiz.Commenting.FunctionComment.TypeHintMissing
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\RequestSignatureException
     */
    protected function resolveRequestSignature(#[\SensitiveParameter] $request): string
    {
        // phpcs:enable
        // Invoke the route resolver directly, as route() is documented as
        // never returning null when called without arguments
        $route = ($request->getRouteResolver())();

        if ($route === null) {
            throw new RequestSignatureException('Unable to generate the request signature. Route unavailable.');
        }

        $server_name = $request->server('SERVER_NAME');

        // Key by the authenticated user when present, otherwise by the client
        // IP, so anonymous callers do not all share a single throttle bucket
        // (which would let one caller rate-limit every other anonymous caller).
        return sha1(
            $request->method()
            . '|' . (is_string($server_name) ? $server_name : '')
            . '|' . $request->path()
            . '|' . ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        );
    }
}
