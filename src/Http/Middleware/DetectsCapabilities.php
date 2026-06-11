<?php

namespace SineMacula\ApiToolkit\Http\Middleware;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Http\RequestCapabilities;
use Symfony\Component\HttpFoundation\Response;

/**
 * Detects request capabilities middleware.
 *
 * Resolves capability flags from the current request and stores them
 * as a typed request attribute for downstream consumption.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DetectsCapabilities
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, \Closure $next): Response
    {
        $capabilities = RequestCapabilities::resolve($request);

        RequestCapabilities::storeOnRequest($request, $capabilities);

        return $next($request);
    }
}
