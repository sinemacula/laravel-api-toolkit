<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use Illuminate\Routing\Route;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute;
use SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathFormResolver;
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
        return self::documentedForms($path, $verbs, [$operationIds], $tag, $summary)[0];
    }

    /**
     * Build a documented route for each path form of the URI, shortest first,
     * each form taking its own operationIds.
     *
     * @param  string  $uri
     * @param  non-empty-list<string>  $verbs
     * @param  non-empty-list<array<string, string>>  $operationIds
     * @param  string  $tag
     * @param  string|null  $summary
     * @return non-empty-list<\SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute>
     */
    private static function documentedForms(string $uri, array $verbs = ['get'], array $operationIds = [[]], string $tag = 'Widget', ?string $summary = null): array
    {
        $route = new Route(array_map(strtoupper(...), $verbs), ltrim($uri, '/'), [PathPlainController::class, 'report']);
        $forms = (new RoutePathFormResolver)->resolve($route);
        $built = [];

        foreach ($forms as $index => $form) {
            $built[] = new DocumentedRoute(
                $route,
                PathPlainController::class,
                'report',
                $form,
                $verbs,
                null,
                $tag,
                $operationIds[$index] ?? [],
                $summary,
                false,
                $forms[0]->path,
                $operationIds[0][$verbs[0]] ?? null,
            );
        }

        return $built;
    }
}
