<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\BadRequestException;
use SineMacula\ApiToolkit\Exceptions\ExceptionMap;
use SineMacula\ApiToolkit\Exceptions\ServiceUnavailableException;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorExampleBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Resolution\ClassNameResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseHeaderResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ThrowsErrorResolver;
use Tests\Fixtures\OpenApi\PathErrorController;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\OpenApi\PathPlainController;
use Tests\Fixtures\OpenApi\PathReflectionGapController;
use Tests\Fixtures\OpenApi\PathResponseHeaderController;
use Tests\Fixtures\OpenApi\PathRouteFactsController;
use Tests\Fixtures\OpenApi\QuotaExceededException;
use Tests\Fixtures\OpenApi\SlowDownException;
use Tests\TestCase;

/**
 * Tests for the ErrorResponseBuilder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ErrorResponseBuilder::class)]
final class ErrorResponseBuilderTest extends TestCase
{
    /** @var array<string, array<string, mixed>> The headers the toolkit's TooManyRequestsException declares */
    private const array RATE_LIMIT_HEADERS = [
        'Retry-After'           => ['description' => 'The number of seconds to wait before retrying.', 'schema' => ['type' => 'integer']],
        'X-RateLimit-Limit'     => ['description' => 'The number of requests allowed in the current window.', 'schema' => ['type' => 'integer']],
        'X-RateLimit-Remaining' => ['description' => 'The number of requests left in the current window.', 'schema' => ['type' => 'integer']],
        'X-RateLimit-Reset'     => ['description' => 'The Unix timestamp at which the current window resets.', 'schema' => ['type' => 'integer']],
    ];

    /**
     * Test that each route fact documents its status with the example and
     * headers of the exception the handler answers with.
     *
     * @return void
     */
    public function testEachRouteFactDocumentsTheExceptionTheHandlerAnswersWith(): void
    {
        $route = $this->router()->post('users/{user}', [PathPlainController::class, 'report'])->middleware(['auth', 'can:update-user', 'throttle:60,1']);

        $responses = $this->build($route, acceptsBody: true);

        self::assertSame([401, 403, 404, 422, 429, 500], array_keys($responses));
        self::assertSame($this->response('Unauthenticated.', ['Error10101']), $responses[401]);
        self::assertSame($this->response('Forbidden.', ['Error10102']), $responses[403]);
        self::assertSame($this->response('The requested resource does not exist.', ['Error10103']), $responses[404]);
        self::assertSame($this->response('The request payload failed validation.', ['Error10106']), $responses[422]);
        self::assertSame($this->response('Too many requests.', ['Error10107'], self::RATE_LIMIT_HEADERS), $responses[429]);
        self::assertSame($this->response('An unexpected server error occurred.', ['Error10001']), $responses[500]);
    }

    /**
     * Test that a route with no fact documents only the server error.
     *
     * @return void
     */
    public function testRouteWithoutFactsDocumentsOnlyTheServerError(): void
    {
        self::assertSame([500], array_keys($this->build($this->router()->get('public', [PathPlainController::class, 'index']))));
        self::assertSame([500], array_keys($this->build($this->router()->post('reports', [PathPlainController::class, 'report']))));
    }

    /**
     * Test that a throttled route without @throws tags still documents the
     * rate-limit headers and example.
     *
     * @return void
     */
    public function testThrottledRouteDocumentsTheRateLimitHeadersAndExample(): void
    {
        $responses = $this->build($this->router()->get('limited', [PathPlainController::class, 'report'])->middleware('throttle:60,1'));

        self::assertSame([429, 500], array_keys($responses));
        self::assertSame($this->response('Too many requests.', ['Error10107'], self::RATE_LIMIT_HEADERS), $responses[429]);
    }

    /**
     * Test that unauthenticated is documented only behind auth middleware.
     *
     * @return void
     */
    public function testUnauthenticatedIsDocumentedOnlyBehindAuth(): void
    {
        self::assertSame([401, 500], array_keys($this->build($this->router()->get('private', [PathPlainController::class, 'report'])->middleware('auth'))));
        self::assertSame([403, 500], array_keys($this->build($this->router()->get('users', [PathFixtureController::class, 'index']))));
    }

    /**
     * Test that an authorization attribute documents the forbidden status.
     *
     * @return void
     */
    public function testAuthorizeAttributeDocumentsForbidden(): void
    {
        self::assertSame([403, 500], array_keys($this->build($this->router()->get('report', [PathRouteFactsController::class, 'report']))));
    }

    /**
     * Test that middleware group facts are resolved minus excluded members.
     *
     * @return void
     */
    public function testMiddlewareGroupFactsAreResolved(): void
    {
        $this->router()->middlewareGroup('facts', ['auth', 'throttle:60,1']);

        $grouped     = $this->router()->get('grouped', [PathPlainController::class, 'report'])->middleware('facts');
        $unthrottled = $this->router()->get('unthrottled', [PathPlainController::class, 'index'])->middleware('facts')->withoutMiddleware('throttle');

        self::assertSame([401, 429, 500], array_keys($this->build($grouped)));
        self::assertSame([401, 500], array_keys($this->build($unthrottled)));
    }

    /**
     * Test that a closure route documents only its route facts.
     *
     * @return void
     */
    public function testClosureRouteDocumentsItsRouteFacts(): void
    {
        $route = $this->router()->get('items/{item}', static fn (): null => null)->middleware('auth');

        self::assertSame([401, 404, 500], array_keys($this->build($route)));
    }

    /**
     * Test that an action absent from its controller, or without a doc comment,
     * documents its route facts alone.
     *
     * @return void
     */
    public function testActionWithoutThrowsDocumentsItsRouteFactsAlone(): void
    {
        $absent  = $this->build($this->router()->delete('gaps/{gap}', [PathReflectionGapController::class, 'destroy'])->middleware('auth'));
        $comment = $this->build($this->router()->get('gaps', [PathReflectionGapController::class, 'index'])->middleware('auth'));

        self::assertSame([401, 403, 404, 500], array_keys($absent));
        self::assertSame($this->response('The requested resource does not exist.', ['Error10103']), $absent[404]);
        self::assertSame([401, 403, 500], array_keys($comment));
    }

    /**
     * Test that the action's thrown ApiExceptions are documented under their
     * statuses, in status order.
     *
     * @return void
     */
    public function testThrownApiExceptionsAreDocumentedInStatusOrder(): void
    {
        $responses = $this->build($this->router()->post('sign-in', [PathRouteFactsController::class, 'signIn']));

        self::assertSame([401, 403, 419, 429, 500], array_keys($responses));
        self::assertSame($this->response('Unauthenticated.', ['Error10101']), $responses[401]);
        self::assertSame($this->response('Forbidden.', ['Error10102']), $responses[403]);
        self::assertSame($this->response('Token mismatch.', ['Error10105']), $responses[419]);
        self::assertSame($this->response('Too many requests.', ['Error10107'], self::RATE_LIMIT_HEADERS), $responses[429]);
    }

    /**
     * Test that a thrown status outside the baseline takes its status phrase.
     *
     * @return void
     */
    public function testThrownStatusOutsideTheBaselineTakesItsStatusPhrase(): void
    {
        $responses = $this->build($this->router()->post('users', [PathFixtureController::class, 'store']), acceptsBody: true);

        self::assertSame([403, 409, 422, 500], array_keys($responses));
        self::assertSame($this->response('Conflict.', ['Error10108']), $responses[409]);
    }

    /**
     * Test that thrown exceptions sharing a status list each example.
     *
     * @return void
     */
    public function testThrownExceptionsSharingAStatusListEachExample(): void
    {
        $responses = $this->build($this->router()->get('errors/{error}', [PathErrorController::class, 'show']));

        self::assertSame($this->response('Service unavailable.', ['Error10112', 'Error10200'], [
            'Retry-After' => ['description' => 'The number of seconds to wait before retrying.', 'schema' => ['type' => 'integer']],
            'Refresh'     => ['description' => 'The number of seconds after which the client may reload.', 'schema' => ['type' => 'integer']],
        ]), $responses[503]);
        self::assertSame($this->response('The request payload failed validation.', ['Error10106']), $responses[422]);
    }

    /**
     * Test that a throws tag outside the API exception base is ignored.
     *
     * @return void
     */
    public function testNonApiThrowsAreIgnored(): void
    {
        self::assertSame([403, 404, 500], array_keys($this->build($this->router()->put('errors/{error}', [PathErrorController::class, 'update']))));
    }

    /**
     * Test that a thrown exception and a route fact on one status list both
     * examples.
     *
     * @return void
     */
    public function testThrownAndBaselineExceptionsOnOneStatusListBothExamples(): void
    {
        $responses = $this->build($this->router()->post('spend', [PathRouteFactsController::class, 'spend'])->middleware('throttle:60,1'));

        self::assertSame($this->response('Too many requests.', ['Error20002', 'Error10107'], self::RATE_LIMIT_HEADERS), $responses[429]);
    }

    /**
     * Test that exceptions sharing a code list one example, and the thrown
     * declaration of a header wins over the baseline one.
     *
     * @return void
     */
    public function testExceptionsSharingACodeListOneExample(): void
    {
        $response = $this->build($this->router()->post('quota', [PathResponseHeaderController::class, 'quota'])->middleware('throttle:60,1'))[429];

        self::assertIsArray($response['headers']);
        self::assertIsArray($response['content']['application/json']['examples']);
        self::assertSame(['Error10107'], array_keys($response['content']['application/json']['examples']));
        self::assertSame(
            ['retry-after', 'X-Quota-Reset', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
            array_keys($response['headers']),
        );
        self::assertSame('The number of seconds until the quota resets.', $response['headers']['retry-after']['description']);
    }

    /**
     * Test that thrown exception headers, inherited ones too, are documented
     * and an exception declaring none adds none.
     *
     * @return void
     */
    public function testThrownExceptionHeadersAreDocumented(): void
    {
        $responses = $this->build($this->router()->post('sign-in', [PathResponseHeaderController::class, 'signIn']));

        self::assertSame($this->response('Too many requests.', ['Error10107'], [
            'Retry-After' => ['description' => 'The number of seconds to wait before retrying.', 'schema' => ['type' => 'integer']],
        ]), $responses[429]);
        self::assertSame($this->response('Conflict.', ['Error10108']), $responses[409]);
        self::assertStringNotContainsString('"headers"', (string) json_encode($this->build($this->router()->get('bare', [PathResponseHeaderController::class, 'bare']))));
    }

    /**
     * Test that a thrown exception without an error code keeps its status and
     * headers but lists no example.
     *
     * @return void
     */
    public function testThrownExceptionWithoutACodeListsNoExample(): void
    {
        $responses = $this->build($this->router()->post('release', [PathRouteFactsController::class, 'release']));

        self::assertSame([409, 500], array_keys($responses));
        self::assertSame($this->response('Conflict.', [], [
            'X-Hold-Id' => ['description' => 'The identifier of the hold that blocked the release.', 'schema' => ['type' => 'string']],
        ]), $responses[409]);
    }

    /**
     * Test that a configured mapping moves a route fact to the status and
     * example of its target.
     *
     * @return void
     */
    public function testConfiguredMappingMovesARouteFact(): void
    {
        config()->set('api-toolkit.exceptions.map', [ValidationException::class => BadRequestException::class]);

        $responses = $this->build($this->router()->post('reports', [PathPlainController::class, 'report']), acceptsBody: true);

        self::assertSame([400, 500], array_keys($responses));
        self::assertSame($this->response('Bad request.', ['Error10100']), $responses[400]);
    }

    /**
     * Test that remapping the throttle keeps the rate-limit headers the handler
     * forwards.
     *
     * @return void
     */
    public function testRemappedThrottleKeepsTheForwardedHeaders(): void
    {
        config()->set('api-toolkit.exceptions.map', [ThrottleRequestsException::class => SlowDownException::class]);

        $responses = $this->build($this->router()->get('limited', [PathPlainController::class, 'report'])->middleware('throttle:60,1'));

        self::assertSame($this->response('Too many requests.', ['Error20002'], self::RATE_LIMIT_HEADERS), $responses[429]);
    }

    /**
     * Test that a remapped throttle keeps the forwarded headers when the
     * action's throws tags name its target too.
     *
     * @return void
     */
    public function testRemappedThrottleThrownByTheActionKeepsTheForwardedHeaders(): void
    {
        config()->set('api-toolkit.exceptions.map', [ThrottleRequestsException::class => SlowDownException::class]);

        $responses = $this->build($this->router()->post('spend', [PathRouteFactsController::class, 'spend'])->middleware('throttle:60,1'));

        self::assertSame($this->response('Too many requests.', ['Error20002'], self::RATE_LIMIT_HEADERS), $responses[429]);
    }

    /**
     * Test that a remapped throttle target declaring a forwarded header wins
     * it.
     *
     * @return void
     */
    public function testRemappedThrottleTargetDeclaringAForwardedHeaderWinsIt(): void
    {
        config()->set('api-toolkit.exceptions.map', [ThrottleRequestsException::class => QuotaExceededException::class]);

        $response = $this->build($this->router()->get('limited', [PathPlainController::class, 'report'])->middleware('throttle:60,1'))[429];

        self::assertIsArray($response['headers']);
        self::assertSame(
            ['retry-after', 'X-Quota-Reset', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
            array_keys($response['headers']),
        );
        self::assertSame('The number of seconds until the quota resets.', $response['headers']['retry-after']['description']);
    }

    /**
     * Test that a Throwable mapping replaces the server error.
     *
     * @return void
     */
    public function testThrowableMappingReplacesTheServerError(): void
    {
        config()->set('api-toolkit.exceptions.map', [\Throwable::class => ServiceUnavailableException::class, AuthenticationException::class => BadRequestException::class]);

        $responses = $this->build($this->router()->get('private', [PathPlainController::class, 'report'])->middleware('auth'));

        self::assertSame([400, 503], array_keys($responses));
        self::assertSame($this->response('Service unavailable.', ['Error10112']), $responses[503]);
    }

    /**
     * Test that the envelope schema describes the error payload shape.
     *
     * @return void
     */
    public function testEnvelopeSchemaDescribesTheErrorPayload(): void
    {
        self::assertSame([
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
        ], $this->builder()->buildEnvelopeSchema());
    }

    /**
     * Test that the envelope schema component name is the conventional one.
     *
     * @return void
     */
    public function testEnvelopeSchemaNameIsErrorEnvelope(): void
    {
        self::assertSame('ErrorEnvelope', ErrorResponseBuilder::ENVELOPE_SCHEMA_NAME);
    }

    /**
     * Build the error responses of the given route.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  bool  $acceptsBody
     * @return array<int, array<string, mixed>>
     */
    private function build(Route $route, bool $acceptsBody = false): array
    {
        /** @var class-string|null $controllerClass */
        $controllerClass = $route->getControllerClass();

        return $this->builder()->build($controllerClass, $route->getActionMethod(), $route, $acceptsBody);
    }

    /**
     * Build the expected error response.
     *
     * @param  string  $description
     * @param  list<string>  $examples
     * @param  array<string, array<string, mixed>>  $headers
     * @return array<string, mixed>
     */
    private function response(string $description, array $examples, array $headers = []): array
    {
        $media    = ['schema' => ['$ref' => '#/components/schemas/ErrorEnvelope']];
        $response = ['description' => $description];

        foreach ($examples as $name) {
            $media['examples'][$name] = ['$ref' => '#/components/examples/' . $name];
        }

        if ($headers !== []) {
            $response['headers'] = $headers;
        }

        return [...$response, 'content' => ['application/json' => $media]];
    }

    /**
     * Build an error response builder over the application's catalogue and
     * exception map.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder
     */
    private function builder(): ErrorResponseBuilder
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue $catalogue */
        $catalogue = $this->app->make(MetadataCatalogue::class);

        return new ErrorResponseBuilder(
            new RouteMiddlewareResolver($this->router()),
            new ThrowsErrorResolver(new ClassNameResolver),
            new ResponseHeaderResolver,
            new ErrorExampleBuilder($catalogue),
            ExceptionMap::fromConfig(),
        );
    }

    /**
     * Resolve the application router singleton.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        assert($this->app !== null);

        return $this->app->make(Router::class);
    }
}
