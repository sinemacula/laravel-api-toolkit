<?php

namespace SineMacula\ApiToolkit\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pretty print the JSON responses when requested.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
class JsonPrettyPrint
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
        $response = $next($request);

        $formatted_content = $this->resolveFormattedContent($request, $response);

        if (is_string($formatted_content)) {
            $response->setContent($formatted_content);
            $response->headers->remove('Content-Length');
        }

        return $response;
    }

    /**
     * Resolve the pretty-printed payload for the given response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return string|null
     */
    private function resolveFormattedContent(Request $request, Response $response): ?string
    {
        $content_type = strtolower((string) $response->headers->get('Content-Type', ''));

        if (!$request->boolean('pretty') || !str_contains($content_type, 'json')) {
            return null;
        }

        $content = $response->getContent();

        if (!is_string($content) || $content === '' || !json_validate($content)) {
            return null;
        }

        $formatted = json_encode(
            json_decode($content, true),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        return is_string($formatted) ? $formatted : null;
    }
}
