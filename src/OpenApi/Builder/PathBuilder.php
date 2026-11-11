<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentableRouteFilter;
use SineMacula\ApiToolkit\OpenApi\Resolution\RequestBodyResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\TagResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ThrowsErrorResolver;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeResolver;

/**
 * Builds the OpenAPI paths object for a single audience.
 *
 * A resource action keeps the full resource contract; any other route still
 * documents an operation with an undocumented body.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class PathBuilder
{
    /** The path prefix under which schema components are referenced */
    private const string SCHEMA_REF_PREFIX = '#/components/schemas/';

    /** The path prefix under which header components are referenced */
    private const string HEADER_REF_PREFIX = '#/components/headers/';

    /** The reference to the shared error-envelope schema */
    private const string ERROR_ENVELOPE_REF = self::SCHEMA_REF_PREFIX . ErrorResponseBuilder::ENVELOPE_SCHEMA_NAME;

    /** The standard reason phrase emitted for each baseline error status */
    private const array BASELINE_PHRASES = [
        401 => 'Unauthenticated.',
        403 => 'Forbidden.',
        404 => 'The requested resource does not exist.',
        422 => 'The request payload failed validation.',
        429 => 'Too many requests.',
        500 => 'An unexpected server error occurred.',
    ];

    /** The media type every documented response body is served under */
    private const string MEDIA_TYPE = SuccessResponseBuilder::MEDIA_TYPE;

    /** The HTTP verbs excluded from the emitted path item */
    private const array EXCLUDED_VERBS = ['HEAD', 'OPTIONS'];

    /** The lowercased HTTP verbs that carry a documented request body */
    private const array BODY_VERBS = ['post', 'put', 'patch'];

    /** The REST actions documented from the resource contract */
    private const array RESOURCE_ACTIONS = ['index', 'show', 'store', 'update', 'destroy'];

    /**
     * Constructor.
     *
     * @param  \Illuminate\Routing\Router  $router
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver  $audience
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentableRouteFilter  $routeFilter
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder  $envelope
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\TagResolver  $tags
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\RequestBodyResolver  $requestBody
     * @param  \SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeResolver  $security
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder  $queryParameters
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver  $middleware
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\ThrowsErrorResolver  $throws
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\SuccessResponseBuilder  $successResponse
     */
    public function __construct(

        /** The router whose route table describes the documentable surface. */
        private Router $router,

        /** The metadata catalogue mapping models to their resources. */
        private MetadataCatalogue $catalogue,

        /** The resolver deciding audience membership per route. */
        private AudienceResolver $audience,

        /** The filter dropping framework and tooling routes from the surface. */
        private DocumentableRouteFilter $routeFilter,

        /** The builder assembling the shared response envelopes. */
        private EnvelopeBuilder $envelope,

        /** The resolver naming the tag each operation is grouped under. */
        private TagResolver $tags,

        /** The resolver of a write action's request body. */
        private RequestBodyResolver $requestBody,

        /** The resolver deriving a route's security requirement. */
        private SecuritySchemeResolver $security,

        /** The builder naming the query parameters each action accepts. */
        private QueryParameterBuilder $queryParameters,

        /** The resolver reading a route's auth, authorization, and limits. */
        private RouteMiddlewareResolver $middleware,

        /** The resolver of the ApiExceptions an action's throws tags name. */
        private ThrowsErrorResolver $throws,

        /** The builder applying what an action declares about its success. */
        private SuccessResponseBuilder $successResponse,
    ) {}

    /**
     * Build the paths object for the given audience and posture.
     *
     * @param  string  $audience
     * @param  string  $posture
     * @return array<string, array<string, mixed>>
     */
    public function build(string $audience, string $posture): array
    {
        $paths = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {

            $item = $this->buildPathItem($audience, $posture, $route);

            if ($item === null) {
                continue;
            }

            [$path, $operations] = $item;

            $paths[$path] = array_merge($paths[$path] ?? [], $operations);
        }

        return $paths;
    }

    /**
     * Build the path template and verb-keyed operations for a single route.
     *
     * @param  string  $audience
     * @param  string  $posture
     * @param  \Illuminate\Routing\Route  $route
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function buildPathItem(string $audience, string $posture, Route $route): ?array
    {
        $controllerClass = $this->resolveControllerClass($route);
        $action          = $route->getActionMethod();

        if ($this->routeFilter->isExcluded($controllerClass, $route)) {
            return null;
        }

        if (!$this->audience->isInAudience($audience, $posture, $controllerClass, $action, $route)) {
            return null;
        }

        return $this->groupOperationByVerb($this->resolveOperation($controllerClass, $action, $route), $route);
    }

    /**
     * Resolve the operation object for the route.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  \Illuminate\Routing\Route  $route
     * @return array<string, mixed>
     */
    private function resolveOperation(?string $controllerClass, string $action, Route $route): array
    {
        $resource = $this->resourceFor($controllerClass, $action);
        $contract = $resource === null || $this->successResponse->replacesResource($controllerClass, $action, $resource['resource'], $action === 'index')
            ? null
            : $this->responsesForAction($action, self::SCHEMA_REF_PREFIX . $resource['schema']);

        $responses   = $this->successResponse->declare($contract ?? $this->declaredResponses($controllerClass, $action), $controllerClass, $action);
        $requestBody = array_intersect($this->verbs($route), self::BODY_VERBS) === []
            ? []
            : $this->requestBody->resolve($controllerClass, $action) ?? [];
        $responses += $this->errorResponses($controllerClass, $action, $route, $requestBody !== [] || ($resource !== null && in_array($action, ['store', 'update'], true)));

        return $this->assembleOperation(
            $this->tags->resolve($controllerClass, $action, $route, $resource['schema'] ?? null),
            $responses,
            $route,
            $requestBody,
            $contract === null ? [] : $this->queryParameters->referencesFor($action, $resource['model']),
        );
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
     * Build the default success response of an action outside the resource
     * contract.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return array<int|string, array<string, mixed>>
     */
    private function declaredResponses(?string $controllerClass, string $action): array
    {
        return match ($action) {
            'store'   => ['201' => ['description' => 'The request succeeded and a resource was created.', ...$this->successResponse->body($controllerClass, $action)]],
            'destroy' => ['204' => ['description' => 'The request succeeded with no content.']],
            default   => ['200' => ['description' => 'The request succeeded.', ...$this->successResponse->body($controllerClass, $action)]],
        };
    }

    /**
     * Group the operation under each documentable verb of the route.
     *
     * @param  array<string, mixed>  $operation
     * @param  \Illuminate\Routing\Route  $route
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function groupOperationByVerb(array $operation, Route $route): ?array
    {
        $operations = [];

        foreach ($this->verbs($route) as $verb) {
            $operations[$verb] = $operation;
        }

        return $operations === [] ? null : [$this->pathTemplate($route), $operations];
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
     * Assemble the operation object shared by every verb of the route.
     *
     * @param  string  $tag
     * @param  array<int|string, mixed>  $responses
     * @param  \Illuminate\Routing\Route  $route
     * @param  array<string, mixed>  $requestBody
     * @param  array<int, array<string, string>>  $queryParameters
     * @return array<string, mixed>
     */
    private function assembleOperation(string $tag, array $responses, Route $route, array $requestBody = [], array $queryParameters = []): array
    {
        $operation  = ['tags' => [$tag]];
        $parameters = array_merge($this->pathParameters($route), $queryParameters);

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($requestBody !== []) {
            $operation['requestBody'] = $requestBody;
        }

        $operation['responses'] = $responses;
        $operation['security']  = $this->security->securityFor($route);

        return $operation;
    }

    /**
     * Map a REST action name to its resource response set.
     *
     * @param  string  $method
     * @param  string  $ref
     * @return array<int|string, array<string, mixed>>
     */
    private function responsesForAction(string $method, string $ref): array
    {
        return match ($method) {
            'index'  => $this->collectionResponse($ref),
            'show'   => $this->singleResponse('200', $ref, 'The requested resource.'),
            'store'  => $this->singleResponse('201', $ref, 'The newly created resource.'),
            'update' => $this->singleResponse('200', $ref, 'The updated resource.'),
            default  => $this->noContentResponse(),
        };
    }

    /**
     * Build the collection response, offering both pagination shapes.
     *
     * A length-aware page also satisfies the open cursor envelope, hence anyOf.
     *
     * @param  string  $ref
     * @return array<int|string, array<string, mixed>>
     */
    private function collectionResponse(string $ref): array
    {
        return [
            '200' => [
                'description' => 'A paginated collection of resources. Length-aware by default; sending ?cursor or ?pagination=cursor returns the cursor shape.',
                'headers'     => [
                    EnvelopeBuilder::TOTAL_COUNT_HEADER_NAME => [
                        '$ref' => self::HEADER_REF_PREFIX . EnvelopeBuilder::TOTAL_COUNT_HEADER_NAME,
                    ],
                ],
                'content' => [
                    self::MEDIA_TYPE => [
                        'schema' => [
                            'anyOf' => [
                                $this->envelope->collectionEnvelope($ref),
                                $this->envelope->cursorCollectionEnvelope($ref),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Build a single-resource response under the given status code.
     *
     * @param  string  $status
     * @param  string  $ref
     * @param  string  $description
     * @return array<int|string, array<string, mixed>>
     */
    private function singleResponse(string $status, string $ref, string $description): array
    {
        return [
            $status => [
                'description' => $description,
                'content'     => [
                    self::MEDIA_TYPE => ['schema' => $this->envelope->singleEnvelope($ref)],
                ],
            ],
        ];
    }

    /**
     * Build the empty no-content response emitted by a destroy action.
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function noContentResponse(): array
    {
        return [
            '204' => ['description' => 'The resource was deleted.'],
        ];
    }

    /**
     * Build the error responses for the action, keyed by HTTP status.
     *
     * Merges the baseline set with the ApiExceptions the action's @throws tags
     * name.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  \Illuminate\Routing\Route  $route
     * @param  bool  $acceptsBody
     * @return array<int, array<string, mixed>>
     */
    private function errorResponses(?string $controllerClass, string $action, Route $route, bool $acceptsBody): array
    {
        $throws    = $controllerClass === null ? [] : $this->throws->resolve($controllerClass, $action);
        $responses = [];

        foreach ($this->baselineErrorsFor($route, $acceptsBody) as $status) {
            $responses[$status] = $this->errorResponse(self::BASELINE_PHRASES[$status], $throws[$status]['examples'] ?? [], $throws[$status]['headers'] ?? []);
            unset($throws[$status]);
        }

        foreach ($throws as $status => $descriptor) {
            $responses[$status] = $this->errorResponse(self::BASELINE_PHRASES[$status] ?? $descriptor['phrase'], $descriptor['examples'], $descriptor['headers']);
        }

        ksort($responses);

        return $responses;
    }

    /**
     * List the baseline error statuses the route's facts imply.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  bool  $acceptsBody
     * @return array<int, int>
     */
    private function baselineErrorsFor(Route $route, bool $acceptsBody): array
    {
        $facts = [
            401 => $this->middleware->authenticates($route),
            403 => $this->middleware->authorizes($route),
            404 => $route->parameterNames() !== [],
            422 => $acceptsBody,
            429 => $this->middleware->throttles($route),
            500 => true,
        ];

        return array_keys(array_filter($facts));
    }

    /**
     * Build a single error response referencing the shared error envelope.
     *
     * @param  string  $description
     * @param  array<string, array<string, mixed>>  $examples
     * @param  array<string, array<string, mixed>>  $headers
     * @return array<string, mixed>
     */
    private function errorResponse(string $description, array $examples, array $headers): array
    {
        $media    = ['schema' => ['$ref' => self::ERROR_ENVELOPE_REF]];
        $response = ['description' => $description];

        if ($examples !== []) {
            $media['examples'] = $examples;
        }

        if ($headers !== []) {
            $response['headers'] = $headers;
        }

        return [...$response, 'content' => [self::MEDIA_TYPE => $media]];
    }

    /**
     * Build the path parameter list from the route's parameter names.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return array<int, array<string, mixed>>
     */
    private function pathParameters(Route $route): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $parameters[] = [
                'name'     => $name,
                'in'       => 'path',
                'required' => true,
                'schema'   => ['type' => 'string'],
            ];
        }

        return $parameters;
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
     * @return array<int, string>
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
