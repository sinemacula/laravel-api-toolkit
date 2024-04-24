<?php

namespace SineMacula\ApiToolkit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pretty print the JSON responses when requested.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
class JsonPrettyPrint
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
        $response = $next($request);

        if ($request->boolean('pretty')) {
            $response->setContent(json_encode(json_decode($response->getContent()), JSON_PRETTY_PRINT));
        }

        return $response;
    }
}
