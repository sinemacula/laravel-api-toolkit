<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Exceptions\ExceptionMap;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseHeaderResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ThrowsErrorResolver;
use SineMacula\Http\Enums\HttpStatus;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds an operation's error responses, keyed by HTTP status.
 *
 * The route's facts name the exceptions the handler receives, each documented
 * as the ApiException the exception map renders it as, beside the ApiExceptions
 * the action's @throws tags name.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ErrorResponseBuilder
{
    /** The component name of the shared error-envelope schema */
    public const string ENVELOPE_SCHEMA_NAME = 'ErrorEnvelope';

    /** The reference to the shared error-envelope schema */
    private const string ENVELOPE_SCHEMA_REF = '#/components/schemas/' . self::ENVELOPE_SCHEMA_NAME;

    /** The media type every error response body is served under */
    private const string MEDIA_TYPE = SuccessResponseBuilder::MEDIA_TYPE;

    /** The standard reason phrase emitted for each baseline error status */
    private const array BASELINE_PHRASES = [
        401 => 'Unauthenticated.',
        403 => 'Forbidden.',
        404 => 'The requested resource does not exist.',
        422 => 'The request payload failed validation.',
        429 => 'Too many requests.',
        500 => 'An unexpected server error occurred.',
    ];

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver  $middleware
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\ThrowsErrorResolver  $throws
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\ResponseHeaderResolver  $headers
     * @param  \SineMacula\ApiToolkit\OpenApi\Builder\ErrorExampleBuilder  $examples
     * @param  \SineMacula\ApiToolkit\Exceptions\ExceptionMap  $exceptions
     */
    public function __construct(

        /** The resolver reading a route's auth, authorization, and limits. */
        private RouteMiddlewareResolver $middleware,

        /** The resolver of the ApiExceptions an action's throws tags name. */
        private ThrowsErrorResolver $throws,

        /** The resolver of the headers each exception declares. */
        private ResponseHeaderResolver $headers,

        /** The builder of the example each error code references. */
        private ErrorExampleBuilder $examples,

        /** The map naming the ApiException each exception renders as. */
        private ExceptionMap $exceptions,
    ) {}

    /**
     * Build the error responses for the action, keyed by HTTP status.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  \Illuminate\Routing\Route  $route
     * @param  bool  $acceptsBody
     * @return array<int, array<string, mixed>>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ErrorCatalogueMismatchException
     */
    public function build(?string $controllerClass, string $action, Route $route, bool $acceptsBody): array
    {
        $errors = [];

        foreach ($this->sources($controllerClass, $action, $route, $acceptsBody) as [$exception, $headers]) {

            $status = $exception::getHttpStatusCode();

            $errors[$status] ??= ['phrase' => self::BASELINE_PHRASES[$status] ?? $this->phraseFor($status, $exception), 'examples' => [], 'headers' => []];

            $errors[$status]['examples'] += $this->examples->reference($exception);
            $errors[$status]['headers'] = $this->headers->merge($errors[$status]['headers'], $headers);
        }

        $responses = array_map(fn (array $error): array => $this->errorResponse($error['phrase'], $error['examples'], $error['headers']), $errors);

        ksort($responses);

        return $responses;
    }

    /**
     * Build the shared error-envelope schema referenced by every response.
     *
     * Mirrors the runtime exception payload.
     *
     * @return array<string, mixed>
     */
    public function buildEnvelopeSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'error' => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => ['type' => 'integer'],
                        'code'   => ['type' => 'integer'],
                        'title'  => ['type' => 'string'],
                        'detail' => ['type' => 'string'],
                        'meta'   => ['type' => 'object', 'additionalProperties' => true],
                    ],
                    'required' => ['status', 'code', 'detail'],
                ],
            ],
            'required' => ['error'],
        ];
    }

    /**
     * List each documented ApiException with its headers, those the action's
     * throws tags name first.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  \Illuminate\Routing\Route  $route
     * @param  bool  $acceptsBody
     * @return list<array{0: class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>, 1: array<string, array<string, mixed>>}>
     */
    private function sources(?string $controllerClass, string $action, Route $route, bool $acceptsBody): array
    {
        $sources = [];

        foreach ($controllerClass === null ? [] : $this->throws->resolve($controllerClass, $action) as $exception) {
            $sources[] = [$exception, $this->headers->forException($exception)];
        }

        $defaults = ExceptionMap::defaults();

        foreach ($this->baselineExceptions($route, $acceptsBody) as $received) {

            $exception = $this->exceptions->targetFor($received);

            // The handler forwards these headers whatever the target
            $sources[] = [$exception, $this->headers->merge($this->headers->forException($exception), $this->headers->forException($defaults->targetFor($received)))];
        }

        return $sources;
    }

    /**
     * List the exceptions the handler receives for the route's facts.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  bool  $acceptsBody
     * @return list<class-string<\Throwable>>
     */
    private function baselineExceptions(Route $route, bool $acceptsBody): array
    {
        $facts = [
            AuthenticationException::class   => $this->middleware->authenticates($route),
            AccessDeniedHttpException::class => $this->middleware->authorizes($route),
            NotFoundHttpException::class     => $route->parameterNames() !== [],
            ValidationException::class       => $acceptsBody,
            ThrottleRequestsException::class => $this->middleware->throttles($route),
            \Throwable::class                => true,
        ];

        return array_keys(array_filter($facts));
    }

    /**
     * Derive the sentence-case phrase for a status, else from the exception's
     * name.
     *
     * @param  int  $status
     * @param  class-string  $exceptionClass
     * @return string
     */
    private function phraseFor(int $status, string $exceptionClass): string
    {
        $words = HttpStatus::tryFrom($status)->name ?? Str::snake(Str::beforeLast(class_basename($exceptionClass), \Exception::class));

        return ucfirst(strtolower(str_replace('_', ' ', $words))) . '.';
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
        $media    = ['schema' => ['$ref' => self::ENVELOPE_SCHEMA_REF]];
        $response = ['description' => $description];

        if ($examples !== []) {
            $media['examples'] = $examples;
        }

        if ($headers !== []) {
            $response['headers'] = $headers;
        }

        return [...$response, 'content' => [self::MEDIA_TYPE => $media]];
    }
}
