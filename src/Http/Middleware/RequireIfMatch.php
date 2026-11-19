<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Middleware;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Exceptions\PreconditionRequiredException;
use SineMacula\ApiToolkit\Http\Concurrency\IfMatch;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require an If-Match field on the requests that replace, modify or delete a
 * resource.
 *
 * Other methods pass, so it can guard a whole resource route group.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RequireIfMatch
{
    /** The methods that must send an If-Match field */
    public const array METHODS = ['PUT', 'PATCH', 'DELETE'];

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\PreconditionRequiredException
     */
    public function handle(Request $request, \Closure $next): Response
    {
        if (in_array($request->getMethod(), self::METHODS, true) && trim(IfMatch::headerValue($request) ?? '', " \t,") === '') {
            throw new PreconditionRequiredException;
        }

        return $next($request);
    }
}
