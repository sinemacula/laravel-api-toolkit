<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\Exceptions\ExceptionMap;
use SineMacula\ApiToolkit\Http\Routing\Attributes\CustomAuthorization;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseHeaderResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathForm;
use SineMacula\ApiToolkit\OpenApi\Resolution\ThrowsErrorResolver;
use SineMacula\Http\Enums\HttpStatus;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds an operation's error responses, keyed by HTTP status.
 *
 * The route's facts and the operation's inputs name the exceptions the handler
 * receives, each documented as the ApiException it renders as, beside the
 * ApiExceptions the @throws tags of the action and its route middleware name.
 * Each response body is the envelope of its error code, or a oneOf of those of
 * every code its status carries.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ErrorResponseBuilder
{
    /** The reference format of each per-code error-envelope schema */
    private const string ENVELOPE_SCHEMA_REF = '#/components/schemas/' . ErrorExampleBuilder::NAME_FORMAT;

    /** The description of every envelope's detail */
    private const string DETAIL_DESCRIPTION = 'Explains this occurrence: the code\'s catalogue text, or the detail the error was raised with, '
        . 'such as a policy denial\'s message.';

    /** The media type every error response body is served under */
    private const string MEDIA_TYPE = SuccessResponseBuilder::MEDIA_TYPE;

    /** The standard reason phrase emitted for each baseline error status */
    private const array BASELINE_PHRASES = [
        401 => 'Unauthenticated.',
        403 => 'Forbidden.',
        404 => 'The requested resource does not exist.',
        422 => 'The request failed validation.',
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

        /** The resolver reading a route's middleware, auth, authorization, and limits. */
        private RouteMiddlewareResolver $middleware,

        /** The resolver of the ApiExceptions a method's throws tags name. */
        private ThrowsErrorResolver $throws,

        /** The resolver of the headers each exception declares. */
        private ResponseHeaderResolver $headers,

        /** The builder of the example each error code references. */
        private ErrorExampleBuilder $examples,

        /** The map naming the ApiException each exception renders as. */
        private ExceptionMap $exceptions,
    ) {}

    /**
     * Build the error responses for the action on the route's path form, keyed
     * by HTTP status.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  \Illuminate\Routing\Route  $route
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathForm  $form
     * @param  list<class-string<\Throwable>>  $inputExceptions
     * @return array<int, array<string, mixed>>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ErrorCatalogueMismatchException
     */
    public function build(?string $controllerClass, string $action, Route $route, RoutePathForm $form, array $inputExceptions): array
    {
        $errors = [];

        foreach ($this->sources($controllerClass, $action, $route, $form, $inputExceptions) as [$exception, $headers]) {

            $status = $exception::getHttpStatusCode();

            $errors[$status] ??= ['phrase' => self::BASELINE_PHRASES[$status] ?? $this->phraseFor($status, $exception), 'codes' => [], 'examples' => [], 'headers' => []];

            $errors[$status]['examples'] += $this->examples->reference($exception);
            $errors[$status]['codes'][] = $exception::getInternalErrorCode();
            $errors[$status]['headers'] = $this->headers->merge($errors[$status]['headers'], $headers);
        }

        $responses = array_map(fn (array $error): array => $this->errorResponse($error['phrase'], $error['codes'], $error['examples'], $error['headers']), $errors);

        ksort($responses);

        return $responses;
    }

    /**
     * Build the error-envelope schema of each code the paths reference, keyed
     * by its component name and ordered by code.
     *
     * @param  array<string, array<string, mixed>>  $paths
     * @return array<string, array<string, mixed>>
     */
    public function buildEnvelopeSchemas(array $paths): array
    {
        return array_map(fn (ErrorDescriptor $descriptor): array => $this->envelopeSchema($descriptor), $this->examples->referenced($paths));
    }

    /**
     * List each documented ApiException with its headers, those the throws tags
     * of the action and its route middleware name first.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  \Illuminate\Routing\Route  $route
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathForm  $form
     * @param  list<class-string<\Throwable>>  $inputExceptions
     * @return list<array{0: class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>, 1: array<string, array<string, mixed>>}>
     */
    private function sources(?string $controllerClass, string $action, Route $route, RoutePathForm $form, array $inputExceptions): array
    {
        $sources = [];

        foreach ($this->thrownExceptions($controllerClass, $action, $route) as $exception) {
            $sources[] = [$exception, $this->headers->forException($exception)];
        }

        $defaults = ExceptionMap::defaults();

        foreach ($this->receivedExceptions($controllerClass, $action, $route, $form, $inputExceptions) as $received) {

            if (is_a($received, ApiException::class, true)) {
                $sources[] = [$received, $this->headers->forException($received)];

                continue;
            }

            $exception = $this->exceptions->targetFor($received);

            // The handler forwards these headers whatever the target
            $sources[] = [$exception, $this->headers->merge($this->headers->forException($exception), $this->headers->forException($defaults->targetFor($received)))];
        }

        return $sources;
    }

    /**
     * List the ApiExceptions the throws tags name, the action's first and then
     * each route middleware's in dispatch order, but for middleware documented
     * from its route fact.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  \Illuminate\Routing\Route  $route
     * @return list<class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>>
     */
    private function thrownExceptions(?string $controllerClass, string $action, Route $route): array
    {
        $exceptions = $controllerClass === null ? [] : $this->throws->resolve($controllerClass, $action);

        foreach (array_diff($this->middleware->classes($route), RouteMiddlewareResolver::FACT_MIDDLEWARE) as $middleware) {
            array_push($exceptions, ...$this->throws->resolve($middleware, method_exists($middleware, 'handle') ? 'handle' : '__invoke'));
        }

        return $exceptions;
    }

    /**
     * List the exceptions the handler receives for the route's facts and the
     * operation's inputs.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  \Illuminate\Routing\Route  $route
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathForm  $form
     * @param  list<class-string<\Throwable>>  $inputExceptions
     * @return list<class-string<\Throwable>>
     */
    private function receivedExceptions(?string $controllerClass, string $action, Route $route, RoutePathForm $form, array $inputExceptions): array
    {
        $facts = [
            AuthenticationException::class   => $this->middleware->authenticates($route),
            AccessDeniedHttpException::class => $this->middleware->authorizes($route) || $this->declaresCustomAuthorization($controllerClass, $action),
            NotFoundHttpException::class     => $this->bindsParameters($route, $form),
            ...array_fill_keys($inputExceptions, true),
            ThrottleRequestsException::class => $this->middleware->throttles($route),
            \Throwable::class                => true,
        ];

        return array_keys(array_filter($facts));
    }

    /**
     * Determine whether a request to the path form binds a route parameter,
     * which an implicit binding can answer with a 404.
     *
     * A form without parameters omits every optional one, which the router
     * still binds when the route gives it a default.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathForm  $form
     * @return bool
     */
    private function bindsParameters(Route $route, RoutePathForm $form): bool
    {
        $defaults = array_intersect_key($route->defaults, $route->getOptionalParameterNames());

        return $form->parameters !== []
            || str_contains((string) $route->getDomain(), '{')
            || array_filter($defaults, static fn (mixed $default): bool => $default !== null) !== [];
    }

    /**
     * Determine whether the action is marked as authorizing itself.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return bool
     */
    private function declaresCustomAuthorization(?string $controllerClass, string $action): bool
    {
        return $controllerClass !== null
            && method_exists($controllerClass, $action)
            && (new \ReflectionMethod($controllerClass, $action))->getAttributes(CustomAuthorization::class) !== [];
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
     * Build the envelope a code's error renders as, its code pinned and its
     * meta typed as declared, else left open.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor  $descriptor
     * @return array<string, mixed>
     */
    private function envelopeSchema(ErrorDescriptor $descriptor): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'error' => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => ['type' => 'integer'],
                        'code'   => ['type' => 'integer', 'const' => $descriptor->code],
                        'title'  => ['type' => 'string'],
                        'detail' => ['type' => 'string', 'description' => self::DETAIL_DESCRIPTION],
                        'meta'   => $descriptor->meta ?? ['type' => 'object', 'additionalProperties' => true],
                    ],
                    'required' => ['status', 'code', 'detail'],
                ],
            ],
            'required' => ['error'],
        ];
    }

    /**
     * Build a single error response whose body is the envelope of its code, or
     * any one of its codes' envelopes.
     *
     * @param  string  $description
     * @param  list<int>  $codes
     * @param  array<string, array<string, mixed>>  $examples
     * @param  array<string, array<string, mixed>>  $headers
     * @return array<string, mixed>
     */
    private function errorResponse(string $description, array $codes, array $examples, array $headers): array
    {
        $codes = array_unique($codes);

        sort($codes);

        $references = array_map(static fn (int $code): array => ['$ref' => sprintf(self::ENVELOPE_SCHEMA_REF, $code)], $codes);
        $media      = ['schema' => count($references) === 1 ? $references[0] : ['oneOf' => $references]];
        $response   = ['description' => $description];

        if ($examples !== []) {
            $media['examples'] = $examples;
        }

        if ($headers !== []) {
            $response['headers'] = $headers;
        }

        return [...$response, 'content' => [self::MEDIA_TYPE => $media]];
    }
}
