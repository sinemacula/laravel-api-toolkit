<?php

namespace SineMacula\ApiToolkit\Http\Middleware\Traits;

use RuntimeException;

/**
 * Throttle requests trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
trait ThrottleRequestsTrait
{
    /**
     * Resolve request signature.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     *
     * @throws \RuntimeException
     */
    protected function resolveRequestSignature($request): string
    {
        if (!$request->route()) {
            throw new RuntimeException('Unable to generate the request signature. Route unavailable.');
        }

        return sha1(
            $request->method() .
            '|' . $request->server('SERVER_NAME') .
            '|' . $request->path() .
            '|' . $request->user()?->getAuthIdentifier() ?? $request->ip()
        );
    }
}
