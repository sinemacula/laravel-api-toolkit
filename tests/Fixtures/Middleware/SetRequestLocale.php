<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Sets the application locale from the request, as an application's locale
 * middleware would.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SetRequestLocale
{
    /** @var string The header naming the request's locale */
    public const string HEADER = 'X-Locale';

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): mixed  $next
     * @return mixed
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        App::setLocale($request->headers->get(self::HEADER) ?? 'en');

        return $next($request);
    }
}
