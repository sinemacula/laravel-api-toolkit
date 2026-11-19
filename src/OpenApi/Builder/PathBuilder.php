<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Contracts\Versioned;
use SineMacula\ApiToolkit\Exceptions\PreconditionRequiredException;
use SineMacula\ApiToolkit\Http\Middleware\RequireIfMatch;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute;
use SineMacula\ApiToolkit\OpenApi\Resolution\RequestBodyResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeResolver;
use SineMacula\Http\Enums\HttpHeader;

/**
 * Builds the OpenAPI paths object from an audience's documented routes.
 *
 * A resource action keeps the full resource contract; any other route still
 * documents an operation with an undocumented body. A verb that replaces,
 * modifies or deletes documents its If-Match precondition.
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

    /** The media type every documented response body is served under */
    private const string MEDIA_TYPE = SuccessResponseBuilder::MEDIA_TYPE;

    /** The lowercased HTTP verbs that carry a documented request body */
    private const array BODY_VERBS = ['post', 'put', 'patch'];

    /** @var \SineMacula\ApiToolkit\OpenApi\Builder\DomainServerBuilder */
    private DomainServerBuilder $domainServers;

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder  $envelope
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\RequestBodyResolver  $requestBody
     * @param  \SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeResolver  $security
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder  $queryParameters
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\PathParameterBuilder  $pathParameters
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder  $errors
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\SuccessResponseBuilder  $successResponse
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver  $middleware
     */
    public function __construct(

        /** The builder assembling the shared response envelopes. */
        private EnvelopeBuilder $envelope,

        /** The resolver of a write action's request body. */
        private RequestBodyResolver $requestBody,

        /** The resolver deriving a route's security requirement. */
        private SecuritySchemeResolver $security,

        /** The builder naming the query parameters each action accepts. */
        private QueryParameterBuilder $queryParameters,

        /** The builder typing each path parameter from its route. */
        private PathParameterBuilder $pathParameters,

        /** The builder of each operation's error responses. */
        private ErrorResponseBuilder $errors,

        /** The builder applying what an action declares about its success. */
        private SuccessResponseBuilder $successResponse,

        /** The resolver reading whether a route requires If-Match. */
        private RouteMiddlewareResolver $middleware,
    ) {
        $this->domainServers = new DomainServerBuilder;
    }

    /**
     * Build the paths object from the audience's routes, in route order.
     *
     * A later route claiming a path and verb replaces the earlier operation,
     * unless both answer on a domain through one controller action and build
     * the same operation apart from its servers, which then lists both. A form
     * omitting optional parameters may join an operation, never replace it.
     *
     * @param  list<\SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute>  $routes
     * @param  array<string, string>  $descriptions
     * @param  string  $audience
     * @param  list<array<string, mixed>>  $servers
     * @return array<string, array<string, array<string, mixed>>>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ServerConfigurationException
     */
    public function build(array $routes, array $descriptions, string $audience, array $servers): array
    {
        $paths  = [];
        $claims = [];

        foreach ($routes as $route) {

            $resolved = [];
            $domain   = $this->domainServersOf($route, $servers, $audience);

            foreach ($route->verbs as $verb) {

                $path        = $route->form->path;
                $claim       = $claims[$path][$verb] ?? null;
                $conditional = in_array(strtoupper($verb), RequireIfMatch::METHODS, true);
                $operation   = $resolved[$conditional] ??= $this->resolveOperation($route, $conditional);

                $built = ['tags' => [$route->tag]] + $this->documentation($route, $verb, $descriptions) + $operation;

                if (!$this->takesOver($claim, $route, $built)) {
                    continue;
                }

                $hosts = $this->hostsOf($claim, $route, $built, $domain);

                $claims[$path][$verb] = [$route, $built, $hosts];
                $paths[$path][$verb]  = $hosts === [] ? $built : $built + ['servers' => $hosts];
            }
        }

        return $paths;
    }

    /**
     * Build the servers of a route answering on a domain, or none for any other
     * route.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute  $route
     * @param  list<array<string, mixed>>  $servers
     * @param  string  $audience
     * @return list<array<string, mixed>>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ServerConfigurationException
     */
    private function domainServersOf(DocumentedRoute $route, array $servers, string $audience): array
    {
        if ($this->domainOf($route) === '') {
            return [];
        }

        return $this->domainServers->build($route, $this->pathParameters->hostVariables($route->route), $servers, $audience);
    }

    /**
     * Determine whether the route's operation takes the path and verb from the
     * earlier claim: a form omitting optional parameters only joins it.
     *
     * @param  array{\SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute, array<string, mixed>, list<array<string, mixed>>}|null  $claim
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute  $route
     * @param  array<string, mixed>  $operation
     * @return bool
     */
    private function takesOver(?array $claim, DocumentedRoute $route, array $operation): bool
    {
        return $claim === null || $route->form->full || $this->joins($claim, $route, $operation);
    }

    /**
     * Resolve the servers of the route's operation: the earlier claim's on its
     * path and verb followed by the route's own when the route joins it, else
     * the route's own.
     *
     * @param  array{\SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute, array<string, mixed>, list<array<string, mixed>>}|null  $claim
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute  $route
     * @param  array<string, mixed>  $operation
     * @param  list<array<string, mixed>>  $domain
     * @return list<array<string, mixed>>
     */
    private function hostsOf(?array $claim, DocumentedRoute $route, array $operation, array $domain): array
    {
        if ($claim === null || !$this->joins($claim, $route, $operation)) {
            return $domain;
        }

        return [...$claim[2], ...array_filter($domain, static fn (array $server): bool => !in_array($server, $claim[2], true))];
    }

    /**
     * Determine whether the route joins the earlier claim on its path and verb:
     * it answers on a domain through the claim's controller action and builds
     * the same operation apart from its servers.
     *
     * A claim without a domain lists no servers, so joining it replaces it.
     *
     * @param  array{\SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute, array<string, mixed>, list<array<string, mixed>>}  $claim
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute  $route
     * @param  array<string, mixed>  $operation
     * @return bool
     */
    private function joins(array $claim, DocumentedRoute $route, array $operation): bool
    {
        [$owner, $claimed] = $claim;

        return $claimed === $operation
            && $this->domainOf($route) !== ''
            && $route->controllerClass !== null
            && [$owner->controllerClass, $owner->action] === [$route->controllerClass, $route->action];
    }

    /**
     * Read the domain the route answers on, empty when it answers on any.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute  $route
     * @return string
     */
    private function domainOf(DocumentedRoute $route): string
    {
        return (string) $route->route->getDomain();
    }

    /**
     * Resolve the summary, description and operationId of the route's operation
     * under the verb.
     *
     * A verb without its own description falls back to its route's primary
     * operationId, which every form of the route shares.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute  $route
     * @param  string  $verb
     * @param  array<string, string>  $descriptions
     * @return array<string, string>
     */
    private function documentation(DocumentedRoute $route, string $verb, array $descriptions): array
    {
        $documentation = [];
        $id            = $route->operationIds[$verb] ?? null;
        $key           = $id !== null && array_key_exists($id, $descriptions) ? $id : $route->primaryOperationId;
        $description   = $descriptions[$key ?? ''] ?? '';

        if ($route->summary !== null) {
            $documentation['summary'] = $route->summary;
        }

        if ($description !== '') {
            $documentation['description'] = $description;
        }

        if ($id !== null) {
            $documentation['operationId'] = $id;
        }

        return $documentation;
    }

    /**
     * Resolve the operation object shared by the route's conditional verbs, or
     * by its other verbs.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute  $route
     * @param  bool  $conditional
     * @return array<string, mixed>
     */
    private function resolveOperation(DocumentedRoute $route, bool $conditional): array
    {
        $controllerClass = $route->controllerClass;
        $action          = $route->action;
        $resource        = $route->resource;

        $contract = $resource === null || $this->successResponse->replacesResource($controllerClass, $action, $resource['resource'], $action === 'index')
            ? null
            : $this->responsesForAction($action, self::SCHEMA_REF_PREFIX . $resource['schema'], is_a($resource['model'], Versioned::class, true));

        $responses   = $this->successResponse->declare($contract ?? $this->declaredResponses($controllerClass, $action), $controllerClass, $action);
        $requestBody = array_intersect($route->verbs, self::BODY_VERBS) === []
            ? []
            : $this->requestBody->resolve($controllerClass, $action) ?? [];
        $bodyExceptions = $requestBody !== [] || ($resource !== null && in_array($action, ['store', 'update'], true)) ? [ValidationException::class] : [];

        [$queryParameters, $queryExceptions] = $contract === null
            ? [[], []]
            : [
                $this->queryParameters->referencesFor($action, $resource['model'], $resource['resource']),
                $this->queryParameters->exceptionsFor($action, $resource['model'], $resource['resource']),
            ];

        $inputExceptions = [...$bodyExceptions, ...$queryExceptions, ...$this->preconditionExceptions($route, $conditional)];

        $responses += $this->errors->build($controllerClass, $action, $route->route, $route->form, $inputExceptions);

        return $this->assembleOperation($responses, $route, $requestBody, $queryParameters, $conditional ? $this->ifMatchParameters($responses) : []);
    }

    /**
     * List the exceptions a conditional verb raises before its action runs.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute  $route
     * @param  bool  $conditional
     * @return list<class-string<\Throwable>>
     */
    private function preconditionExceptions(DocumentedRoute $route, bool $conditional): array
    {
        return $conditional && $this->middleware->requiresIfMatch($route->route) ? [PreconditionRequiredException::class] : [];
    }

    /**
     * Build the If-Match header parameter of a conditional write, required
     * where its absence is refused and optional where a mismatch is.
     *
     * @param  array<int|string, mixed>  $responses
     * @return list<array<string, mixed>>
     */
    private function ifMatchParameters(array $responses): array
    {
        $required = array_key_exists(428, $responses);

        if (!$required && !array_key_exists(412, $responses)) {
            return [];
        }

        return [[
            'name'        => HttpHeader::IF_MATCH->getName(),
            'in'          => 'header',
            'description' => 'The entity tag of the resource as last read. The write is refused with a 412 when the resource has changed since.',
            'required'    => $required,
            'schema'      => ['type' => 'string'],
        ]];
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
     * Assemble the operation object shared by every verb of the route.
     *
     * @param  array<int|string, mixed>  $responses
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoute  $route
     * @param  array<string, mixed>  $requestBody
     * @param  array<int, array<string, mixed>>  $queryParameters
     * @param  array<int, array<string, mixed>>  $headerParameters
     * @return array<string, mixed>
     */
    private function assembleOperation(array $responses, DocumentedRoute $route, array $requestBody, array $queryParameters, array $headerParameters): array
    {
        $operation  = [];
        $parameters = array_merge($this->pathParameters->build($route->route, $route->form->parameters), $queryParameters, $headerParameters);

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($requestBody !== []) {
            $operation['requestBody'] = $requestBody;
        }

        $operation['responses'] = $responses;
        $operation['security']  = $this->security->securityFor($route->route);

        return $operation;
    }

    /**
     * Map a REST action name to its resource response set.
     *
     * @param  string  $method
     * @param  string  $ref
     * @param  bool  $versioned
     * @return array<int|string, array<string, mixed>>
     */
    private function responsesForAction(string $method, string $ref, bool $versioned): array
    {
        return match ($method) {
            'index'  => $this->collectionResponse($ref),
            'show'   => $this->singleResponse('200', $ref, 'The requested resource.', $versioned),
            'store'  => $this->singleResponse('201', $ref, 'The newly created resource.', $versioned),
            'update' => $this->singleResponse('200', $ref, 'The updated resource.', $versioned),
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
     * Build a single-resource response under the given status code, tagged when
     * the resource is versioned.
     *
     * @param  string  $status
     * @param  string  $ref
     * @param  string  $description
     * @param  bool  $versioned
     * @return array<int|string, array<string, mixed>>
     */
    private function singleResponse(string $status, string $ref, string $description, bool $versioned): array
    {
        $response = ['description' => $description];

        if ($versioned) {
            $response['headers'] = [EnvelopeBuilder::ETAG_HEADER_NAME => ['$ref' => self::HEADER_REF_PREFIX . EnvelopeBuilder::ETAG_HEADER_NAME]];
        }

        return [
            $status => [
                ...$response,
                'content' => [
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
}
