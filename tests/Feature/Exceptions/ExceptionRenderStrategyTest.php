<?php

declare(strict_types = 1);

namespace Tests\Feature\Exceptions;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\ConflictException;
use SineMacula\ApiToolkit\Exceptions\NotFoundException;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\TestCase;

/**
 * Feature tests for the exception render-strategy matrix through the kernel.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiExceptionHandler::class)]
#[CoversClass(ApiException::class)]
final class ExceptionRenderStrategyTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /** @var array<string, array{string, bool}> Each strategy that defers a non-JSON request, with its debug mode */
    private const array DEFERRING_STRATEGIES = [
        'json_when_expected' => ['json_when_expected', false],
        'auto in debug'      => ['auto', true],
    ];

    /**
     * Set up each test with the toolkit handler wired and failing routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::get('/abort-conflict', static function (): never {
            abort(409);
        });

        Route::get('/unhandled', static function (): never {
            throw new \RuntimeException('Something broke internally');
        });

        Route::get('/conflict', static function (): never {
            throw new ConflictException;
        });

        Route::get('/not-found', static function (): never {
            throw new NotFoundException;
        });

        Route::get('/throttled', static function (): never {
            throw new TooManyRequestsException(null, ['Retry-After' => '60']);
        });
    }

    /**
     * Test that the always_json strategy forces the JSON envelope for an HTML
     * request.
     *
     * @return void
     */
    public function testAlwaysJsonForcesJsonEnvelopeForHtmlRequest(): void
    {
        Config::set('api-toolkit.exceptions.render_strategy', 'always_json');

        $response = $this->get('/abort-conflict', ['Accept' => 'text/html']);

        $response->assertStatus(409);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonPath('error.status', 409);
        $response->assertJsonPath('error.code', 10113);
    }

    /**
     * Test that include_debug_info surfaces the throwable internals even with
     * debug off.
     *
     * @return void
     */
    public function testIncludeDebugInfoSurfacesThrowableInternals(): void
    {
        Config::set('app.debug', false);
        Config::set('api-toolkit.exceptions.include_debug_info', true);

        $response = $this->getJson('/unhandled');

        $response->assertStatus(500);
        $response->assertJsonPath('error.debug.message', 'Something broke internally');
        $response->assertJsonPath('error.debug.exception', \RuntimeException::class);

        $debug = $response->json('error.debug');

        self::assertIsArray($debug);
        self::assertArrayHasKey('file', $debug);
        self::assertArrayHasKey('line', $debug);
        self::assertArrayHasKey('trace', $debug);
        self::assertIsArray($debug['trace']);
    }

    /**
     * Test that the auto strategy defers to the framework HTML page for a
     * non-JSON request in debug.
     *
     * @return void
     */
    public function testAutoDefersToHtmlForNonJsonRequestInDebug(): void
    {
        Config::set('api-toolkit.exceptions.render_strategy', 'auto');
        Config::set('app.debug', true);

        $response = $this->get('/abort-conflict', ['Accept' => 'text/html']);

        $response->assertStatus(409);

        $contentType = $response->headers->get('Content-Type');

        self::assertIsString($contentType);
        self::assertStringContainsString('text/html', $contentType);

        $content = $response->baseResponse->getContent();

        self::assertIsString($content);
        self::assertStringNotContainsString('"error":', $content);
    }

    /**
     * Test that the pretty flag indents the rendered error body.
     *
     * @return void
     */
    public function testPrettyFlagIndentsErrorBody(): void
    {
        $pretty = $this->getJson('/abort-conflict?pretty=1');

        $pretty->assertStatus(409);

        $prettyContent = $pretty->baseResponse->getContent();

        self::assertIsString($prettyContent);
        self::assertStringContainsString("\n", $prettyContent);
        self::assertStringContainsString('    ', $prettyContent);

        $compact = $this->getJson('/abort-conflict');

        $compact->assertStatus(409);

        $compactContent = $compact->baseResponse->getContent();

        self::assertIsString($compactContent);
        self::assertStringNotContainsString("\n", $compactContent);
    }

    /**
     * Provide non-JSON requests crossed with the API exceptions they defer,
     * under each strategy that defers them to Laravel's renderer.
     *
     * @return iterable<string, array{string, bool, array<string, string|null>, string, int, array<string, string>}>
     */
    public static function deferredApiExceptionProvider(): iterable
    {
        $accepts = [
            'html'      => ['Accept' => 'text/html'],
            'any type'  => ['Accept' => '*/*'],
            'no accept' => ['Accept' => null],
        ];

        $exceptions = [
            'conflict'  => ['/conflict', 409, []],
            'not found' => ['/not-found', 404, []],
            'throttled' => ['/throttled', 429, ['Retry-After' => '60']],
        ];

        foreach (self::DEFERRING_STRATEGIES as $label => [$strategy, $debug]) {
            foreach ($accepts as $accept => $headers) {
                foreach ($exceptions as $exception => [$path, $status, $forwarded]) {
                    yield sprintf('%s, %s, %s', $label, $accept, $exception) => [$strategy, $debug, $headers, $path, $status, $forwarded];
                }
            }
        }
    }

    /**
     * Test that an API exception deferred to Laravel's renderer keeps its
     * status and headers.
     *
     * @param  string  $strategy
     * @param  bool  $debug
     * @param  array<string, string|null>  $headers
     * @param  string  $path
     * @param  int  $status
     * @param  array<string, string>  $forwarded
     * @return void
     */
    #[DataProvider('deferredApiExceptionProvider')]
    public function testDeferredApiExceptionKeepsItsStatusAndHeaders(string $strategy, bool $debug, array $headers, string $path, int $status, array $forwarded): void
    {
        Config::set('api-toolkit.exceptions.render_strategy', $strategy);
        Config::set('app.debug', $debug);

        $response = $this->get($path, $headers);

        $response->assertStatus($status);
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');

        foreach ($forwarded as $name => $value) {
            $response->assertHeader($name, $value);
        }
    }

    /**
     * Provide requests that expect JSON without naming application/json, under
     * each strategy that defers other requests to Laravel's renderer.
     *
     * @return iterable<string, array{string, bool, array<string, string>}>
     */
    public static function jsonExpectingRequestProvider(): iterable
    {
        $requests = [
            'ajax'            => ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*'],
            'vendor json'     => ['Accept' => 'application/vnd.api+json'],
            'problem details' => ['Accept' => 'application/problem+json'],
        ];

        foreach (self::DEFERRING_STRATEGIES as $label => [$strategy, $debug]) {
            foreach ($requests as $request => $headers) {
                yield sprintf('%s, %s', $label, $request) => [$strategy, $debug, $headers];
            }
        }
    }

    /**
     * Test that a request expecting JSON gets the envelope under a strategy
     * that defers other requests.
     *
     * @param  string  $strategy
     * @param  bool  $debug
     * @param  array<string, string>  $headers
     * @return void
     */
    #[DataProvider('jsonExpectingRequestProvider')]
    public function testJsonExpectingRequestGetsTheEnvelope(string $strategy, bool $debug, array $headers): void
    {
        Config::set('api-toolkit.exceptions.render_strategy', $strategy);
        Config::set('app.debug', $debug);

        $response = $this->get('/conflict', $headers);

        $response->assertStatus(409);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonPath('error.status', 409);
        $response->assertJsonPath('error.code', 10108);
    }
}
