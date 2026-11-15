<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

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
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorExampleBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\OpenApi\ErrorParityController;
use Tests\Fixtures\OpenApi\ParityRequestBodyController;
use Tests\Fixtures\OpenApi\PathPlainController;
use Tests\Fixtures\OpenApi\SlowDownException;
use Tests\TestCase;

/**
 * Parity test between the documented error responses and the rendered ones.
 *
 * Each route fails through the HTTP kernel, and the response must carry the
 * status, headers and error the document gives for it.
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
    use RegistersApiExceptionHandler;

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

        Lang::addLines([
            'exceptions.20002.title'  => 'Slow Down',
            'exceptions.20002.detail' => 'The request budget for this window is spent',
        ], 'en', 'fixtures');

        $router = $this->router();

        $router->get('private', [PathPlainController::class, 'report'])->middleware('auth');
        $router->get('report', [PathPlainController::class, 'index'])->middleware('can:view-report');
        $router->get('users/{user}', [ErrorParityController::class, 'show'])->middleware(SubstituteBindings::class);
        $router->post('articles', [ParityRequestBodyController::class, 'formRequest']);
        $router->get('limited', [PathPlainController::class, 'report'])->middleware('throttle:1,1');
        $router->get('fail', [ErrorParityController::class, 'fail']);
    }

    /**
     * Provide a failing request per route fact, with the status it answers.
     *
     * @return iterable<string, array{string, string, string, int}>
     */
    public static function failingRequestProvider(): iterable
    {
        yield 'unauthenticated' => ['GET', '/private', '/private', 401];
        yield 'forbidden' => ['GET', '/report', '/report', 403];
        yield 'not found' => ['GET', '/users/999', '/users/{user}', 404];
        yield 'invalid input' => ['POST', '/articles', '/articles', 422];
        yield 'too many requests' => ['GET', '/limited', '/limited', 429];
        yield 'unhandled' => ['GET', '/fail', '/fail', 500];
    }

    /**
     * Test that each failing request answers as its documented error.
     *
     * @param  string  $method
     * @param  string  $uri
     * @param  string  $path
     * @param  int  $status
     * @return void
     */
    #[DataProvider('failingRequestProvider')]
    public function testFailingRequestAnswersAsDocumented(string $method, string $uri, string $path, int $status): void
    {
        $this->assertAnswersAsDocumented($this->sendUntilItFails($method, $uri), $method, $path, $status);
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

        $response = $this->sendUntilItFails('GET', '/limited');

        $response->assertJsonPath('error.code', 20002);

        $this->assertAnswersAsDocumented($response, 'GET', '/limited', 429);
    }

    /**
     * Assert the response carries the status, headers and error the document
     * gives for the operation.
     *
     * @param  \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @param  string  $method
     * @param  string  $path
     * @param  int  $status
     * @return void
     */
    private function assertAnswersAsDocumented(TestResponse $response, string $method, string $path, int $status): void
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

        $examples = $error['content']['application/json']['examples'] ?? [];

        self::assertIsArray($examples);
        self::assertCount(1, $examples);

        $name = (string) array_key_first($examples);

        self::assertSame(
            $document['components']['examples'][$name]['value']['error'],
            Arr::only((array) $response->json('error'), ['status', 'code', 'title', 'detail']),
        );
    }

    /**
     * Send the request until it fails.
     *
     * @param  string  $method
     * @param  string  $uri
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function sendUntilItFails(string $method, string $uri): TestResponse
    {
        do {
            $response = $this->json($method, $uri);
        } while ($response->baseResponse->getStatusCode() < 400);

        return $response;
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
