<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\ExceptionMap;
use SineMacula\ApiToolkit\Exceptions\GoneException;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorExampleBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ErrorCatalogueMismatchException;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Concerns\ValidatesOpenApiDocuments;
use Tests\Fixtures\Middleware\EnforceQuota;
use Tests\Fixtures\Middleware\RequireSubscription;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\CodelessApiException;
use Tests\Fixtures\OpenApi\ErrorParityController;
use Tests\Fixtures\OpenApi\ParityRequestBodyController;
use Tests\Fixtures\OpenApi\ParityUserController;
use Tests\Fixtures\OpenApi\PathArticleController;
use Tests\Fixtures\OpenApi\PathPlainController;
use Tests\Fixtures\OpenApi\PathResponseHeaderController;
use Tests\Fixtures\OpenApi\PathRouteFactsController;
use Tests\Fixtures\OpenApi\SlowDownException;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Parity test between the documented error responses and the rendered ones.
 *
 * Each route fails through the HTTP kernel, and the response must carry the
 * status, headers and error the document gives for it, in a body the schema
 * documented for that status accepts.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiExceptionHandler::class)]
#[CoversClass(ExceptionMap::class)]
#[CoversClass(ErrorResponseBuilder::class)]
#[CoversClass(ErrorExampleBuilder::class)]
final class OpenApiErrorExampleParityTest extends TestCase
{
    use RegistersApiExceptionHandler, ValidatesOpenApiDocuments;

    /** @var string The prefix of a reference to an example component */
    private const string EXAMPLE_PREFIX = '#/components/examples/';

    /** @var list<string> The headers the handler forwards from a throttled request */
    private const array THROTTLE_HEADERS = ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'];

    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        config()->set('app.debug', false);
        config()->set('api-toolkit.resources.resource_map', [User::class => UserResource::class, Article::class => ArticleResource::class]);

        Lang::addLines([
            'exceptions.20002.title'  => 'Slow Down',
            'exceptions.20002.detail' => 'The request budget for this window is spent',
        ], 'en', 'fixtures');

        $router = $this->router();

        $router->get('private', [PathPlainController::class, 'report'])->middleware('auth');
        $router->get('report', [PathPlainController::class, 'index'])->middleware('can:view-report');
        $router->get('users/{user}', [ErrorParityController::class, 'show'])->middleware(SubstituteBindings::class);
        $router->post('articles', [ParityRequestBodyController::class, 'formRequest']);
        $router->get('articles', [PathArticleController::class, 'index'])->middleware(ParseApiQuery::class);
        $router->get('limited', [PathPlainController::class, 'report'])->middleware('throttle:1,1');
        $router->get('fail', [ErrorParityController::class, 'fail']);
        $router->get('members', [ParityUserController::class, 'index'])->middleware(ParseApiQuery::class);
        $router->get('members/{user}', [ParityUserController::class, 'show'])->middleware([ParseApiQuery::class, SubstituteBindings::class]);
        $router->post('holds', [PathResponseHeaderController::class, 'bare']);
        $router->get('quota', [PathPlainController::class, 'report'])->middleware(EnforceQuota::class);
        $router->get('subscribed', [PathPlainController::class, 'report'])->middleware(RequireSubscription::class);
    }

    /**
     * Provide a failing request per route fact, with the status it answers and
     * the headers the handler forwards with it.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: int, 4?: list<string>}>
     */
    public static function failingRequestProvider(): iterable
    {
        yield 'unauthenticated' => ['GET', '/private', '/private', 401];
        yield 'forbidden' => ['GET', '/report', '/report', 403];
        yield 'not found' => ['GET', '/users/999', '/users/{user}', 404];
        yield 'invalid input' => ['POST', '/articles', '/articles', 422];
        yield 'too many requests' => ['GET', '/limited', '/limited', 429, self::THROTTLE_HEADERS];
        yield 'unhandled' => ['GET', '/fail', '/fail', 500];
        yield 'thrown by the action' => ['POST', '/holds', '/holds', 409];
        yield 'thrown by middleware with its headers' => ['GET', '/quota', '/quota', 429, ['Retry-After', 'X-Quota-Reset']];
        yield 'application code without a detail' => ['GET', '/subscribed', '/subscribed', 402, ['X-Upgrade-Url']];
    }

    /**
     * Test that each failing request answers as its documented error.
     *
     * @param  string  $method
     * @param  string  $uri
     * @param  string  $path
     * @param  int  $status
     * @param  list<string>  $forwarded
     * @return void
     */
    #[DataProvider('failingRequestProvider')]
    public function testFailingRequestAnswersAsDocumented(string $method, string $uri, string $path, int $status, array $forwarded = []): void
    {
        $this->assertAnswersAsDocumented($this->sendFailing($method, $uri), $method, $path, $status, $forwarded);
    }

    /**
     * Provide a refused query on a documented read, with the error code it
     * answers.
     *
     * @return iterable<string, array{0: string, 1: string, 2: int}>
     */
    public static function refusedQueryProvider(): iterable
    {
        yield 'undeclared filter key' => ['/members?filters=' . rawurlencode('{"nope":"x"}'), '/members', 10106];
        yield 'undeclared order key' => ['/members?order=nope:desc', '/members', 10106];
        yield 'refused trashed' => ['/articles?trashed=everything', '/articles', 10106];
        yield 'limit above the ceiling' => ['/members?limit=101', '/members', 10201];
        yield 'filters over the byte budget' => ['/members?filters=' . rawurlencode(self::oversizedFilters()), '/members', 10201];
        yield 'page below one' => ['/members?page=0', '/members', 10106];
        yield 'malformed cursor' => ['/members?cursor=malformed', '/members', 10106];
        yield 'non-string fields member' => ['/members/999?fields[users][]=name', '/members/{user}', 10106];
    }

    /**
     * Test that each refused query answers as its operation documents.
     *
     * @param  string  $uri
     * @param  string  $path
     * @param  int  $code
     * @return void
     */
    #[DataProvider('refusedQueryProvider')]
    public function testRefusedQueryAnswersAsDocumented(string $uri, string $path, int $code): void
    {
        $response = $this->getJson($uri);

        $response->assertJsonPath('error.code', $code);

        $this->assertAnswersAsDocumented($response, 'GET', $path, 422, []);
    }

    /**
     * Provide a failing request whose body is documented whatever the debug
     * setting, with whether it carries debug details.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: int, 4: bool}>
     */
    public static function debuggedRequestProvider(): iterable
    {
        yield 'invalid input' => ['POST', '/articles', '/articles', 422, false];
        yield 'filters over the byte budget' => ['GET', '/members?filters=' . rawurlencode(self::oversizedFilters()), '/members', 422, false];
        yield 'limit above the ceiling' => ['GET', '/members?limit=101', '/members', 422, false];
        yield 'missing model' => ['GET', '/users/999', '/users/{user}', 404, true];
        yield 'unhandled' => ['GET', '/fail', '/fail', 500, true];
    }

    /**
     * Test that a failing request answers as documented with debug details
     * included.
     *
     * @param  string  $method
     * @param  string  $uri
     * @param  string  $path
     * @param  int  $status
     * @param  bool  $debugged
     * @return void
     */
    #[DataProvider('debuggedRequestProvider')]
    public function testFailingRequestWithDebugInfoAnswersAsDocumented(string $method, string $uri, string $path, int $status, bool $debugged): void
    {
        config()->set('api-toolkit.exceptions.include_debug_info', true);

        $response = $this->sendFailing($method, $uri);

        self::assertSame($debugged, $response->json('error.debug') !== null);

        $this->assertAnswersAsDocumented($response, $method, $path, $status, []);
    }

    /**
     * Test that an action throwing an exception without an error code, which
     * the handler cannot render, fails the export.
     *
     * @return void
     */
    public function testActionThrowingACodelessExceptionFailsTheExport(): void
    {
        $this->router()->post('holds/release', [PathRouteFactsController::class, 'release']);

        $this->expectException(ErrorCatalogueMismatchException::class);
        $this->expectExceptionMessage(sprintf(
            '[%s] declares no error code, so the handler cannot render it; give it a CODE constant.',
            CodelessApiException::class,
        ));

        assert($this->app !== null);

        $this->app->make(ExportOpenApiComponents::class)->export();
    }

    /**
     * Test that a configured mapping is followed by both the response and the
     * document.
     *
     * @return void
     */
    public function testConfiguredMappingIsFollowedByResponseAndDocument(): void
    {
        config()->set('api-toolkit.exceptions.map', [ThrottleRequestsException::class => SlowDownException::class]);

        $response = $this->sendFailing('GET', '/limited');

        $response->assertJsonPath('error.code', 20002);

        $this->assertAnswersAsDocumented($response, 'GET', '/limited', 429, self::THROTTLE_HEADERS);
    }

    /**
     * Test that mapping the exception Laravel converts a missing model into is
     * followed by both the response and the document.
     *
     * @return void
     */
    public function testConvertedTypeMappingIsFollowedByResponseAndDocument(): void
    {
        config()->set('api-toolkit.exceptions.map', [NotFoundHttpException::class => GoneException::class]);

        $response = $this->sendFailing('GET', '/users/999');

        $response->assertJsonPath('error.code', 10109);

        $this->assertAnswersAsDocumented($response, 'GET', '/users/{user}', 410, []);
    }

    /**
     * Test that mapping the missing model itself, which Laravel converts before
     * rendering, is rejected rather than silently ignored.
     *
     * @return void
     */
    public function testMappingTheTypeLaravelConvertsIsRejected(): void
    {
        config()->set('api-toolkit.exceptions.map', [ModelNotFoundException::class => GoneException::class]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            'The api-toolkit.exceptions.map key [%s] never matches: Laravel converts it before rendering, so map the exception it becomes.',
            ModelNotFoundException::class,
        ));

        assert($this->app !== null);

        $this->app->make(ExportOpenApiComponents::class)->export();
    }

    /**
     * Assert the response carries the status, headers and error the document
     * gives for the operation, in a body its schema accepts, and the document
     * lists the forwarded headers.
     *
     * @param  \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @param  string  $method
     * @param  string  $path
     * @param  int  $status
     * @param  list<string>  $forwarded
     * @return void
     */
    private function assertAnswersAsDocumented(TestResponse $response, string $method, string $path, int $status, array $forwarded): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents $exporter */
        $exporter = $this->app->make(ExportOpenApiComponents::class);
        $document = $exporter->export()->document;
        $error    = $document['paths'][$path][strtolower($method)]['responses'][$status] ?? null;

        $response->assertStatus($status);

        self::assertIsArray($error, sprintf('The %s %s operation documents no %d response.', $method, $path, $status));

        $headers = $error['headers'] ?? [];

        self::assertIsArray($headers);

        foreach (array_keys($headers) as $header) {
            self::assertTrue($response->headers->has((string) $header), sprintf('The %d response is missing the documented %s header.', $status, $header));
        }

        $documented = array_map(strtolower(...), array_map(strval(...), array_keys($headers)));

        foreach ($forwarded as $header) {
            self::assertTrue($response->headers->has($header), sprintf('The %d response is missing the forwarded %s header.', $status, $header));
            self::assertContains(strtolower($header), $documented, sprintf('The %d response documents no %s header.', $status, $header));
        }

        $examples = $error['content']['application/json']['examples'] ?? [];
        $code     = $response->json('error.code');

        self::assertIsArray($examples);
        self::assertIsInt($code);

        $name = sprintf('Error%d', $code);

        self::assertSame(['$ref' => self::EXAMPLE_PREFIX . $name], $examples[$name] ?? null, sprintf('The %d response documents no %s example.', $status, $name));

        self::assertSame(
            $document['components']['examples'][$name]['value']['error'],
            Arr::only((array) $response->json('error'), ['status', 'code', 'title', 'detail']),
        );

        $pointer = sprintf('/paths/%s/%s/responses/%d/content/application~1json/schema', rawurlencode(str_replace('/', '~1', $path)), strtolower($method), $status);
        $result  = $this->validateAgainstExported($document, $pointer, json_decode((string) $response->baseResponse->getContent(), false, 512, JSON_THROW_ON_ERROR));

        self::assertTrue($result->isValid(), sprintf('The %d body must validate against its documented schema: %s', $status, $this->formatErrors($result)));
    }

    /**
     * Build a filters document longer than the default byte budget allows.
     *
     * @return string
     */
    private static function oversizedFilters(): string
    {
        return (string) json_encode(['name' => ['$eq' => str_repeat('a', 8192)]]);
    }

    /**
     * Send the request as a caller whose quota is spent, first spending the one
     * request the throttled route allows.
     *
     * @param  string  $method
     * @param  string  $uri
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function sendFailing(string $method, string $uri): TestResponse
    {
        if ($uri === '/limited') {
            $this->json($method, $uri)->assertSuccessful();
        }

        return $this->json($method, $uri, [], [EnforceQuota::HEADER => 'true']);
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
