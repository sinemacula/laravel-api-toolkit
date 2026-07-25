<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;

/**
 * Builds the OpenAPI paths object for a single audience.
 *
 * Walks the application's route table and emits one path item per documented
 * route, grouping every verb of the same URI under a shared template. Only
 * routes handled by an AuthorizedController subclass whose model maps to a
 * registered resource are considered, and each must pass the audience
 * membership check for the requested audience and posture. The REST action name
 * drives the response shape through the shared envelope builder: index emits
 * the length-aware collection envelope with the total-count header, show and
 * update emit a single envelope, store emits a single envelope under 201, and
 * destroy emits an empty 204. Any other action is skipped, as are closure and
 * non-authorized routes. Each operation is tagged with the resource schema name
 * and carries a string path parameter per route segment. Alongside its success
 * responses, every operation carries the error statuses that honestly apply to
 * it - a baseline set keyed by action shape, merged with any ApiException
 * reflected from the controller action's documented throws.
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
        500 => 'An unexpected server error occurred.',
    ];

    /** The media type every documented response body is served under */
    private const string MEDIA_TYPE = 'application/json';

    /** The HTTP verbs excluded from the emitted path item */
    private const array EXCLUDED_VERBS = ['HEAD', 'OPTIONS'];

    /**
     * Constructor.
     *
     * @param  \Illuminate\Routing\Router  $router
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue  $catalogue
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver  $audience
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder  $envelope
     */
    public function __construct(

        /** The router whose route table describes the documentable surface. */
        private Router $router,

        /** The metadata catalogue mapping models to their resources. */
        private MetadataCatalogue $catalogue,

        /** The resolver deciding audience membership per route. */
        private AudienceResolver $audience,

        /** The builder assembling the shared response envelopes. */
        private EnvelopeBuilder $envelope,
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
     * Build the path template and verb-keyed operations for a single route, or
     * null when the route is not documentable for the given audience.
     *
     * @param  string  $audience
     * @param  string  $posture
     * @param  \Illuminate\Routing\Route  $route
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function buildPathItem(string $audience, string $posture, Route $route): ?array
    {
        $controllerClass = $this->documentableController($audience, $posture, $route);

        if ($controllerClass === null) {
            return null;
        }

        $operation = $this->resolveOperation($controllerClass, $route);

        if ($operation === null) {
            return null;
        }

        return $this->groupOperationByVerb($operation, $route);
    }

    /**
     * Resolve the authorized controller class handling the route when it is a
     * member of the given audience, or null when the route is not documentable.
     *
     * @param  string  $audience
     * @param  string  $posture
     * @param  \Illuminate\Routing\Route  $route
     * @return class-string<\SineMacula\ApiToolkit\Http\Routing\AuthorizedController>|null
     */
    private function documentableController(string $audience, string $posture, Route $route): ?string
    {
        $controllerClass = $this->resolveControllerClass($route);

        if ($controllerClass === null) {
            return null;
        }

        if (!$this->audience->isInAudience($audience, $posture, $controllerClass, $route->getActionMethod(), $route)) {
            return null;
        }

        return $controllerClass;
    }

    /**
     * Resolve the operation object for the route, or null when the model has no
     * registered resource or the action is not a supported REST action.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Http\Routing\AuthorizedController>  $controllerClass
     * @param  \Illuminate\Routing\Route  $route
     * @return array<string, mixed>|null
     */
    private function resolveOperation(string $controllerClass, Route $route): ?array
    {
        $schemaName = $this->resolveSchemaName($controllerClass);

        if ($schemaName === null) {
            return null;
        }

        $action    = $route->getActionMethod();
        $responses = $this->responsesForAction($action, self::SCHEMA_REF_PREFIX . $schemaName);

        if ($responses === null) {
            return null;
        }

        $responses += $this->errorResponses($action, $route, $controllerClass);

        return $this->buildOperation($schemaName, $responses, $route);
    }

    /**
     * Group the operation under each documentable verb of the route, returning
     * the path template and verb-keyed operations, or null when no verb
     * remains.
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
     * Resolve the authorized controller class handling the route, or null when
     * the route has no controller or is not an authorized controller.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return class-string<\SineMacula\ApiToolkit\Http\Routing\AuthorizedController>|null
     */
    private function resolveControllerClass(Route $route): ?string
    {
        $controllerClass = $route->getControllerClass();

        if ($controllerClass === null) {
            return null;
        }

        $controllerClass = ltrim($controllerClass, '\\');

        if (!is_subclass_of($controllerClass, AuthorizedController::class)) {
            return null;
        }

        return $controllerClass;
    }

    /**
     * Resolve the resource schema name for the controller's model, or null when
     * the model has no registered resource.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Http\Routing\AuthorizedController>  $controllerClass
     * @return string|null
     */
    private function resolveSchemaName(string $controllerClass): ?string
    {
        try {
            $modelClass = $controllerClass::getResourceModel();
        } catch (\LogicException) {
            return null;
        }

        $resourceClass = $this->catalogue->getResourceMap()[$modelClass] ?? null;

        if ($resourceClass === null) {
            return null;
        }

        return SchemaComponentName::fromResource($resourceClass);
    }

    /**
     * Assemble the operation object shared by every verb of the route.
     *
     * @param  string  $schemaName
     * @param  array<int|string, mixed>  $responses
     * @param  \Illuminate\Routing\Route  $route
     * @return array<string, mixed>
     */
    private function buildOperation(string $schemaName, array $responses, Route $route): array
    {
        $operation  = ['tags' => [$schemaName]];
        $parameters = $this->pathParameters($route);

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        $operation['responses'] = $responses;

        return $operation;
    }

    /**
     * Map a REST action name to its response set, or null for any action name
     * outside the supported REST verbs.
     *
     * @param  string  $method
     * @param  string  $ref
     * @return array<int|string, mixed>|null
     */
    private function responsesForAction(string $method, string $ref): ?array
    {
        return match ($method) {
            'index'   => $this->collectionResponse($ref),
            'show'    => $this->singleResponse('200', $ref, 'The requested resource.'),
            'store'   => $this->singleResponse('201', $ref, 'The newly created resource.'),
            'update'  => $this->singleResponse('200', $ref, 'The updated resource.'),
            'destroy' => $this->noContentResponse(),
            default   => null,
        };
    }

    /**
     * Build the length-aware collection response, carrying the shared
     * total-count header alongside the collection envelope.
     *
     * @param  string  $ref
     * @return array<int|string, mixed>
     */
    private function collectionResponse(string $ref): array
    {
        return [
            '200' => [
                'description' => 'A paginated collection of resources.',
                'headers'     => [
                    EnvelopeBuilder::TOTAL_COUNT_HEADER_NAME => [
                        '$ref' => self::HEADER_REF_PREFIX . EnvelopeBuilder::TOTAL_COUNT_HEADER_NAME,
                    ],
                ],
                'content' => [
                    self::MEDIA_TYPE => ['schema' => $this->envelope->collectionEnvelope($ref)],
                ],
            ],
        ];
    }

    /**
     * Build a single-resource response wrapping the reference in the single
     * envelope under the given status code.
     *
     * @param  string  $status
     * @param  string  $ref
     * @param  string  $description
     * @return array<int|string, mixed>
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
     * @return array<int|string, mixed>
     */
    private function noContentResponse(): array
    {
        return [
            '204' => ['description' => 'The resource was deleted.'],
        ];
    }

    /**
     * Build the error responses for the action, merging the honest baseline set
     * with any ApiException reflected from the action's documented throws.
     *
     * The result is keyed by numeric HTTP status. When a reflected exception's
     * status coincides with a baseline status, the two collapse into a single
     * response carrying the shared envelope schema plus a per-code example.
     *
     * @param  string  $action
     * @param  \Illuminate\Routing\Route  $route
     * @param  class-string<\SineMacula\ApiToolkit\Http\Routing\AuthorizedController>  $controllerClass
     * @return array<int, array<string, mixed>>
     */
    private function errorResponses(string $action, Route $route, string $controllerClass): array
    {
        $throws    = $this->throwsErrorsFor($controllerClass, $action);
        $responses = [];

        foreach ($this->baselineErrorsFor($action, $route->parameterNames() !== []) as $status) {
            $responses[$status] = $this->errorResponse(self::BASELINE_PHRASES[$status], $throws[$status]['examples'] ?? []);
            unset($throws[$status]);
        }

        foreach ($throws as $status => $descriptor) {
            $responses[$status] = $this->errorResponse($descriptor['phrase'], $descriptor['examples']);
        }

        ksort($responses);

        return $responses;
    }

    /**
     * List the baseline error statuses that honestly apply to the action.
     *
     * Every documented operation carries 401, 403, and 500; an operation for an
     * existing resource (any route with path parameters) also carries 404, and
     * an operation with a request body (store, update) also carries 422.
     *
     * @param  string  $action
     * @param  bool  $hasPathParameters
     * @return array<int, int>
     */
    private function baselineErrorsFor(string $action, bool $hasPathParameters): array
    {
        $statuses = [401, 403, 500];

        if ($hasPathParameters) {
            $statuses[] = 404;
        }

        if (in_array($action, ['store', 'update'], true)) {
            $statuses[] = 422;
        }

        return $statuses;
    }

    /**
     * Reflect the action's documented throws into a status-keyed map of
     * concrete ApiException error descriptors.
     *
     * Each @throws tag is normalised to a class name, kept only when it
     * resolves to an ApiException subclass, then grouped by its resolved HTTP
     * status. The example name is the exception's short class name, and a
     * non-baseline status derives its phrase from the status enum case name.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Http\Routing\AuthorizedController>  $controllerClass
     * @param  string  $action
     * @return array<int, array{phrase: string, examples: array<string, array<string, mixed>>}>
     */
    private function throwsErrorsFor(string $controllerClass, string $action): array
    {
        try {
            $docComment = (new \ReflectionMethod($controllerClass, $action))->getDocComment();
        } catch (\ReflectionException) {
            return [];
        }

        if ($docComment === false) {
            return [];
        }

        preg_match_all('/@throws\s+([\\\\\w]+)/', $docComment, $matches);

        $errors = [];

        foreach ($matches[1] as $class) {

            $exceptionClass = ltrim($class, '\\');

            if (!is_subclass_of($exceptionClass, ApiException::class) || !defined($exceptionClass . '::HTTP_STATUS')) {
                continue;
            }

            $status = $exceptionClass::getHttpStatusCode();

            /** @var \SineMacula\Http\Enums\HttpStatus $enum */
            $enum = constant($exceptionClass . '::HTTP_STATUS');

            $errors[$status] ??= [
                'phrase'   => self::BASELINE_PHRASES[$status] ?? ucfirst(strtolower(str_replace('_', ' ', $enum->name))) . '.',
                'examples' => [],
            ];

            $short = substr((string) strrchr('\\' . $exceptionClass, '\\'), 1);
            $error = ['status' => $status];

            if (defined($exceptionClass . '::CODE')) {
                /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface $code */
                $code          = constant($exceptionClass . '::CODE');
                $error['code'] = $code->getCode();
            }

            $errors[$status]['examples'][$short] = ['value' => ['error' => $error]];
        }

        return $errors;
    }

    /**
     * Build a single error response referencing the shared error-envelope
     * schema, attaching a per-code examples map when concrete examples exist.
     *
     * @param  string  $description
     * @param  array<string, array<string, mixed>>  $examples
     * @return array<string, mixed>
     */
    private function errorResponse(string $description, array $examples): array
    {
        $media = ['schema' => ['$ref' => self::ERROR_ENVELOPE_REF]];

        if ($examples !== []) {
            $media['examples'] = $examples;
        }

        return [
            'description' => $description,
            'content'     => [self::MEDIA_TYPE => $media],
        ];
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
     * The template is anchored with a leading slash and an optional parameter's
     * trailing marker is dropped, so both `{param}` and `{param?}` render as
     * `{param}`.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return string
     */
    private function pathTemplate(Route $route): string
    {
        return str_replace('?}', '}', '/' . ltrim($route->uri(), '/'));
    }

    /**
     * List the documentable, lowercased HTTP verbs of the route, excluding the
     * automatically added HEAD and OPTIONS verbs.
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
