<?php

namespace SineMacula\ApiToolkit\Http\Middleware;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pretty print the JSON responses when requested.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
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
    public function handle(Request $request, \Closure $next): Response
    {
        $response = $next($request);

        if (!$request->boolean('pretty')) {
            return $response;
        }

        if ($response instanceof StreamedResponse) {
            return $response;
        }

        if ($response instanceof JsonResponse) {
            $encodingOptions = $response->getEncodingOptions();
            $response->setEncodingOptions($encodingOptions | JSON_PRETTY_PRINT);
            $response->setData($response->getData());

            return $response;
        }

        if (str_contains((string) $response->headers->get('Content-Type'), 'application/json')) {

            $content = $response->getContent();

            if (!is_string($content)) {
                return $response;
            }

            $decoded = json_decode($content);

            if ($decoded === null && $content !== 'null') {
                return $response;
            }

            $response->setContent(json_encode($decoded, JSON_PRETTY_PRINT));

            return $response;
        }

        return $response;
    }
}
