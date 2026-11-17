<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse to run an action whose route declares no authorization check.
 *
 * A missing gate is a server defect, so the refusal renders as a 500 rather
 * than a policy denial.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class EnsureActionIsAuthorized
{
    /** The refusal naming the action that declares no authorization */
    private const string REFUSAL = 'The [%s] action declares no authorization: gate it with #[Authorize], or mark it #[CustomAuthorization] or #[SkipAuthorization]';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver  $middleware
     */
    public function __construct(

        /** The resolver reading the route's middleware as the router resolves it. */
        private RouteMiddlewareResolver $middleware,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \LogicException
     */
    public function handle(Request $request, \Closure $next): Response
    {
        $route = $request->route();

        if (!$route instanceof Route || !$this->middleware->authorizes($route)) {
            throw new \LogicException(sprintf(self::REFUSAL, $route instanceof Route ? $route->getActionName() : 'unrouted'));
        }

        return $next($request);
    }
}
