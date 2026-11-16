<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\OpenApi\Attributes\Operation;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Naming\OperationIdCollisionGuard;
use SineMacula\ApiToolkit\OpenApi\Naming\OperationIds;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;

/**
 * Collects the documentable routes of every exported audience in one walk of
 * the route table.
 *
 * Only attributes and the resource map are read, so the walk stays cheap enough
 * to run for every audience on each export.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class DocumentedRouteCollector
{
    /** The HTTP verbs excluded from the emitted path item */
    private const array EXCLUDED_VERBS = ['HEAD', 'OPTIONS'];

    /** The REST actions documented from the resource contract */
    private const array RESOURCE_ACTIONS = ['index', 'show', 'store', 'update', 'destroy'];

    /**
     * Constructor.
     *
     * @param  \Illuminate\Routing\Router  $router
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver  $audience
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration  $audiences
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentableRouteFilter  $routeFilter
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\TagResolver  $tags
     */
    public function __construct(

        /** The router whose route table describes the documentable surface. */
        private Router $router,

        /** The metadata catalogue mapping models to their resources. */
        private MetadataCatalogue $catalogue,

        /** The resolver deciding audience membership per route. */
        private AudienceResolver $audience,

        /** The registry supplying each audience's posture. */
        private AudienceConfiguration $audiences,

        /** The filter dropping framework and tooling routes from the surface. */
        private DocumentableRouteFilter $routeFilter,

        /** The resolver naming the tag each operation is grouped under. */
        private TagResolver $tags,
    ) {}

    /**
     * Collect the documentable routes of each of the given audiences.
     *
     * @param  list<string>  $audiences
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\OperationIdCollisionException
     */
    public function collect(array $audiences): DocumentedRoutes
    {
        $idsEnabled = (bool) Config::get('api-toolkit.openapi.operation_ids', false);
        $postures   = [];
        $byAudience = [];

        foreach ($audiences as $audience) {
            $postures[$audience]   = $this->audiences->postureFor($audience);
            $byAudience[$audience] = [];
        }

        foreach ($this->router->getRoutes()->getRoutes() as $route) {

            $collected = $this->collectRoute($route, $postures, $idsEnabled);

            if ($collected === null) {
                continue;
            }

            foreach ($collected['audiences'] as $audience) {
                $byAudience[$audience][] = $collected['route'];
            }
        }

        $routes = new DocumentedRoutes($byAudience, $idsEnabled);

        if ($idsEnabled) {
            $this->assertUniqueIds($routes);
        }

        return $routes;
    }

    /**
     * Assert that no two exported operations share an id across the audiences.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes  $routes
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\OperationIdCollisionException
     */
    private function assertUniqueIds(DocumentedRoutes $routes): void
    {
        $operations = [];

        foreach ($routes->audiences() as $audience) {
            $operations[$audience] = $routes->operations($audience);
        }

        (new OperationIdCollisionGuard)->assertUnique($operations);
    }

    /**
     * Resolve the route and the audiences it joins, or null when it is not
     * documentable or joins none.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  array<string, string>  $postures
     * @param  bool  $idsEnabled
     * @return array{route: \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute, audiences: non-empty-list<string>}|null
     */
    private function collectRoute(Route $route, array $postures, bool $idsEnabled): ?array
    {
        $controllerClass = $this->resolveControllerClass($route);
        $action          = $route->getActionMethod();
        $verbs           = $this->verbs($route);

        if ($verbs === [] || $this->routeFilter->isExcluded($controllerClass, $route)) {
            return null;
        }

        $members = [];

        foreach (array_map('strval', array_keys($postures)) as $audience) {

            if (!$this->audience->isInAudience($audience, $postures[$audience], $controllerClass, $action, $route)) {
                continue;
            }

            $members[] = $audience;
        }

        return $members === [] ? null : ['route' => $this->documentRoute($route, $controllerClass, $action, $verbs, $idsEnabled), 'audiences' => $members];
    }

    /**
     * Resolve the resource, tag, summary and operationIds of a documentable
     * route.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  non-empty-list<string>  $verbs
     * @param  bool  $idsEnabled
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute
     */
    private function documentRoute(Route $route, ?string $controllerClass, string $action, array $verbs, bool $idsEnabled): DocumentedRoute
    {
        $resource  = $this->resourceFor($controllerClass, $action);
        $operation = $this->operationOf($controllerClass, $action);
        $path      = $this->pathTemplate($route);

        return new DocumentedRoute(
            $route,
            $controllerClass,
            $action,
            $path,
            $verbs,
            $resource,
            $this->tags->resolve($controllerClass, $action, $route, $resource['schema'] ?? null),
            $idsEnabled ? OperationIds::derive($route, $controllerClass, $action, $verbs, $path, $operation?->id) : [],
            $operation?->summary,
            $operation?->id !== null,
        );
    }

    /**
     * Read the #[Operation] declared on the action, if any.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return \SineMacula\ApiToolkit\OpenApi\Attributes\Operation|null
     */
    private function operationOf(?string $controllerClass, string $action): ?Operation
    {
        if ($controllerClass === null || !method_exists($controllerClass, $action)) {
            return null;
        }

        $attributes = (new \ReflectionMethod($controllerClass, $action))->getAttributes(Operation::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * Resolve the model and component schema name of the resource the action
     * serves.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return array{model: class-string<\Illuminate\Database\Eloquent\Model>, resource: class-string, schema: string}|null
     */
    private function resourceFor(?string $controllerClass, string $action): ?array
    {
        if ($controllerClass === null || !is_subclass_of($controllerClass, AuthorizedController::class) || !in_array($action, self::RESOURCE_ACTIONS, true)) {
            return null;
        }

        $modelClass    = $this->resolveModelClass($controllerClass);
        $resourceClass = $modelClass === null ? null : ($this->catalogue->getResourceMap()[$modelClass] ?? null);

        return $modelClass === null || $resourceClass === null
            ? null
            : ['model' => $modelClass, 'resource' => $resourceClass, 'schema' => SchemaComponentName::fromResource($resourceClass)];
    }

    /**
     * Resolve the normalised controller class handling the route, if any.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return class-string|null
     */
    private function resolveControllerClass(Route $route): ?string
    {
        $controllerClass = $route->getControllerClass();

        if ($controllerClass === null || $controllerClass === '') {
            return null;
        }

        $controllerClass = ltrim($controllerClass, '\\');

        return class_exists($controllerClass) ? $controllerClass : null;
    }

    /**
     * Resolve the model class the controller authorizes.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Http\Routing\AuthorizedController>  $controllerClass
     * @return class-string<\Illuminate\Database\Eloquent\Model>|null
     */
    private function resolveModelClass(string $controllerClass): ?string
    {
        try {
            return $controllerClass::getResourceModel();
        } catch (\LogicException) {
            return null;
        }
    }

    /**
     * Convert the route URI to an OpenAPI path template.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return string
     */
    private function pathTemplate(Route $route): string
    {
        return str_replace('?}', '}', '/' . ltrim($route->uri(), '/'));
    }

    /**
     * List the documentable, lowercased HTTP verbs of the route.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return list<string>
     */
    private function verbs(Route $route): array
    {
        $verbs = [];

        foreach ($route->methods() as $verb) {

            if (in_array($verb, self::EXCLUDED_VERBS, true)) {
                continue;
            }

            $verbs[] = strtolower($verb);
        }

        return $verbs;
    }
}
