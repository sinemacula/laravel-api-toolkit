<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use Illuminate\Routing\Route;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute;
use Tests\Fixtures\OpenApi\PathPlainController;

/**
 * Builds documented routes over a plain controller action, outside any router.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait BuildsDocumentedRoutes
{
    /**
     * Build a documented route for the path and verbs.
     *
     * @param  string  $path
     * @param  non-empty-list<string>  $verbs
     * @param  array<string, string>  $operationIds
     * @param  string  $tag
     * @param  string|null  $summary
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute
     */
    private static function documentedRoute(string $path, array $verbs = ['get'], array $operationIds = [], string $tag = 'Widget', ?string $summary = null): DocumentedRoute
    {
        $route = new Route(array_map(strtoupper(...), $verbs), ltrim($path, '/'), [PathPlainController::class, 'report']);

        return new DocumentedRoute($route, PathPlainController::class, 'report', $path, $verbs, null, $tag, $operationIds, $summary);
    }
}
