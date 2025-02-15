<?php

namespace SineMacula\ApiToolkit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parse the API query request parameters.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
class ParseApiQuery
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        ApiQuery::parse($request);

        return $next($request);
    }
}
