<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Middleware;

use Illuminate\Http\Request;
use Tests\Fixtures\Support\CurrentTenant;

/**
 * Binds the tenant the request names, as an application's tenancy middleware
 * would.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class BindCurrentTenant
{
    /** @var string The header naming the request's tenant organization */
    public const string HEADER = 'X-Tenant';

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): mixed  $next
     * @return mixed
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        app()->instance(CurrentTenant::class, new CurrentTenant((int) $request->headers->get(self::HEADER)));

        return $next($request);
    }
}
