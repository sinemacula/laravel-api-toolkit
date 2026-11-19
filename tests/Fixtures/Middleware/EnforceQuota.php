<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Middleware;

use Illuminate\Http\Request;
use Tests\Fixtures\OpenApi\QuotaExceededException;

/**
 * Invokable middleware refusing a request once the caller's quota is spent.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class EnforceQuota
{
    /** @var string The header flagging the caller's quota as spent */
    public const string HEADER = 'X-Quota-Spent';

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): mixed  $next
     * @return mixed
     *
     * @throws \Tests\Fixtures\OpenApi\QuotaExceededException
     */
    public function __invoke(Request $request, \Closure $next): mixed
    {
        if ($request->headers->has(self::HEADER)) {
            throw new QuotaExceededException(null, ['Retry-After' => 3600, 'X-Quota-Reset' => 1767225600]);
        }

        return $next($request);
    }
}
