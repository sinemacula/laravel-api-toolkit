<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Middleware;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Exceptions\BadRequestException;
use SineMacula\ApiToolkit\Exceptions\UnsupportedMediaTypeException;
use SineMacula\Http\Enums\HttpHeader;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require the body of a request that creates, replaces or modifies a resource
 * to be a JSON object or array, sent under a JSON media type.
 *
 * A request without a body and other methods pass, so it can guard a whole
 * resource route group.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RequireJsonBody
{
    /** The methods whose body must be JSON */
    public const array METHODS = ['POST', 'PUT', 'PATCH'];

    /** The characters JSON treats as insignificant whitespace */
    private const string WHITESPACE = " \t\r\n";

    /** The media types PHP parses into form fields */
    private const array FORM_TYPES = ['application/x-www-form-urlencoded', 'multipart/form-data'];

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\BadRequestException
     * @throws \SineMacula\ApiToolkit\Exceptions\UnsupportedMediaTypeException
     */
    public function handle(Request $request, \Closure $next): Response
    {
        if (in_array($request->getMethod(), self::METHODS, true)) {
            $this->admitJsonBody($request);
        }

        return $next($request);
    }

    /**
     * Refuse a body sent under another media type, or one that does not decode
     * to a JSON object or array, and pass any other on under its lowercased
     * media type, as Laravel reads JSON only under that case.
     *
     * The raw body is decoded afresh, as Laravel keeps a failed decode as empty
     * input, and the parser's message is left out of the response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\BadRequestException
     * @throws \SineMacula\ApiToolkit\Exceptions\UnsupportedMediaTypeException
     */
    private function admitJsonBody(Request $request): void
    {
        $contentType = (string) $request->headers->get(HttpHeader::CONTENT_TYPE->getName());
        $length      = strcspn($contentType, ';');
        $mediaType   = strtolower(trim(substr($contentType, 0, $length)));

        if (!$this->hasBody($request, $mediaType)) {
            return;
        }

        if ($mediaType !== 'application/json' && !str_ends_with($mediaType, '+json')) {
            throw new UnsupportedMediaTypeException;
        }

        try {
            $document = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $document = null;
        }

        if (!is_array($document)) {
            throw new BadRequestException;
        }

        $request->headers->set(HttpHeader::CONTENT_TYPE->getName(), $mediaType . substr($contentType, $length));
    }

    /**
     * Determine whether the request sends a body.
     *
     * PHP parses a multipart body into fields and files, leaving no raw body.
     * Fields under any other type were read from a raw body or added by the
     * application.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $mediaType
     * @return bool
     */
    private function hasBody(Request $request, string $mediaType): bool
    {
        return trim($request->getContent(), self::WHITESPACE) !== ''
            || $request->files->count() > 0
            || ($request->request->count() > 0 && in_array($mediaType, self::FORM_TYPES, true));
    }
}
