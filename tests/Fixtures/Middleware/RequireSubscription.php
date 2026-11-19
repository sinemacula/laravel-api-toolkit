<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Middleware;

use Illuminate\Http\Request;
use Tests\Fixtures\OpenApi\PaymentRequiredException;

/**
 * Refuses a request carrying no subscription, as an application's billing
 * middleware would.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RequireSubscription
{
    /** @var string The header carrying the caller's subscription */
    public const string HEADER = 'X-Subscription';

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): mixed  $next
     * @return mixed
     *
     * @throws \Tests\Fixtures\OpenApi\PaymentRequiredException
     */
    public function handle(Request $request, \Closure $next): mixed
    {
        if (!$request->headers->has(self::HEADER)) {
            throw new PaymentRequiredException(null, ['X-Upgrade-Url' => 'https://example.com/billing']);
        }

        return $next($request);
    }
}
