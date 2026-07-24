<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Providers\Registrars;

use Illuminate\Routing\Route;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;

/**
 * Registers the OpenAPI audience route macros.
 *
 * Adds the undocumented, documentedIn, and notDocumentedIn macros to the router
 * so the same audience directives available as controller attributes can also
 * be declared fluently in route files. Each macro merges its directive into a
 * reserved key on the route action, where the audience resolver reads it as the
 * most-specific directive level.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RouteMacroRegistrar
{
    /**
     * Register the audience route macros.
     *
     * @return void
     */
    public function register(): void
    {
        if (Route::hasMacro('undocumented')) {
            return;
        }

        Route::macro('undocumented', function (): Route {
            /** @var \Illuminate\Routing\Route $this */
            return RouteMacroRegistrar::apply($this, true, [], []);
        });

        Route::macro('documentedIn', function (string ...$audiences): Route {
            /** @var \Illuminate\Routing\Route $this */
            return RouteMacroRegistrar::apply($this, false, array_values($audiences), []);
        });

        Route::macro('notDocumentedIn', function (string ...$audiences): Route {
            /** @var \Illuminate\Routing\Route $this */
            return RouteMacroRegistrar::apply($this, false, [], array_values($audiences));
        });
    }

    /**
     * Merge an audience directive into the route action.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  bool  $undocumented
     * @param  list<string>  $documentedIn
     * @param  list<string>  $notDocumentedIn
     * @return \Illuminate\Routing\Route
     */
    public static function apply(Route $route, bool $undocumented, array $documentedIn, array $notDocumentedIn): Route
    {
        $current = $route->getAction(AudienceResolver::ROUTE_ACTION_KEY);
        $current = is_array($current) ? $current : [];

        $merged = [
            'undocumented'    => (bool) ($current['undocumented'] ?? false) || $undocumented,
            'documentedIn'    => array_values(array_merge((array) ($current['documentedIn'] ?? []), $documentedIn)),
            'notDocumentedIn' => array_values(array_merge((array) ($current['notDocumentedIn'] ?? []), $notDocumentedIn)),
        ];

        $action                                     = $route->getAction();
        $action[AudienceResolver::ROUTE_ACTION_KEY] = $merged;

        $route->setAction($action);

        return $route;
    }
}
