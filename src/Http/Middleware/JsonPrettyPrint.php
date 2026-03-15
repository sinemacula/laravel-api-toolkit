<?php

namespace SineMacula\ApiToolkit\Http\Middleware;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SineMacula\Http\Enums\HttpHeader;
use SineMacula\Http\Enums\MediaType;
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
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, \Closure $next): Response
    {
        $response = $next($request);

        if ($request->boolean('pretty') && !$response instanceof StreamedResponse) {
            $this->applyPrettyPrint($response);
        }

        return $response;
    }

    /**
     * Apply pretty-print formatting to the response.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return void
     */
    private function applyPrettyPrint(Response $response): void
    {
        if ($response instanceof JsonResponse) {
            $this->prettyPrintJsonResponse($response);

            return;
        }

        $contentType = (string) $response->headers->get(HttpHeader::CONTENT_TYPE->getName());

        if (str_contains($contentType, MediaType::APPLICATION_JSON->getMimeType())) {
            $this->prettyPrintPlainResponse($response);
        }
    }

    /**
     * Pretty-print a JsonResponse by updating its encoding options.
     *
     * @param  \Illuminate\Http\JsonResponse  $response
     * @return void
     */
    private function prettyPrintJsonResponse(JsonResponse $response): void
    {
        $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRETTY_PRINT);
        $response->setData($response->getData());
    }

    /**
     * Pretty-print a plain Response containing JSON content.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return void
     */
    private function prettyPrintPlainResponse(Response $response): void
    {
        $content = $response->getContent();

        if (!is_string($content)) {
            return;
        }

        $decoded = json_decode($content);

        // Distinguish between a JSON decode failure and the valid JSON literal 'null'
        if ($decoded === null && $content !== 'null') {
            return;
        }

        $response->setContent(json_encode($decoded, JSON_PRETTY_PRINT));
    }
}
