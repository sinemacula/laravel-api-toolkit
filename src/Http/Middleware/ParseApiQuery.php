<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Middleware;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parse the API query request parameters.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ParseApiQuery
{
    /**
     * Handle an incoming request.
     *
     * A request whose query is still the current one is not parsed again, so a
     * route repeating the global middleware does not replace the query that
     * criteria were already composed under.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, \Closure $next): Response
    {
        if (!$this->isCurrent($request)) {
            ApiQuery::parse($request);

            $request->attributes->set(self::class, [\WeakReference::create($request), ApiQuery::token()]);
        }

        return $next($request);
    }

    /**
     * Determine whether the current query was parsed from this request.
     *
     * A copied request carries the mark but not the identity, so it is parsed.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    private function isCurrent(Request $request): bool
    {
        /** @var array{\WeakReference<\Illuminate\Http\Request>, object|null}|null $parsed */
        $parsed = $request->attributes->get(self::class);

        return $parsed !== null && $parsed[0]->get() === $request && $parsed[1] === ApiQuery::token();
    }
}
