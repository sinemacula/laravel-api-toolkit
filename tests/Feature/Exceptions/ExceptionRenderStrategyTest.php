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
use SineMacula\ApiToolkit\Exceptions\LockUnavailableException;
use SineMacula\ApiToolkit\Exceptions\NotFoundException;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
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

    /** @var array<string, array<string, string|null>> Requests that do not expect JSON */
    private const array NON_JSON_ACCEPTS = [
        'html'      => ['Accept' => 'text/html'],
        'any type'  => ['Accept' => '*/*'],
        'no accept' => ['Accept' => null],
    ];

    /** @var string The message of the application failure the map renders as a conflict */
    private const string SETTLED_MESSAGE = 'The order is already settled';

    /**
     * Set up each test with the toolkit handler wired, failing routes and the
     * exception map they render through.
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

        Route::get('/settled', static function (): never {
            throw new \DomainException(self::SETTLED_MESSAGE);
        });

        Route::get('/lock-unavailable', static function (): never {
            throw new LockUnavailableException('The order is locked');
        });

        Route::get('/rate-limited', static function (): never {
            throw new TooManyRequestsHttpException(30);
        });

        Config::set('api-toolkit.exceptions.map', [
            \DomainException::class             => ConflictException::class,
            TooManyRequestsHttpException::class => ConflictException::class,
        ]);
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
        $exceptions = [
            'conflict'  => ['/conflict', 409, []],
            'not found' => ['/not-found', 404, []],
            'throttled' => ['/throttled', 429, ['Retry-After' => '60']],
        ];

        foreach (self::DEFERRING_STRATEGIES as $label => [$strategy, $debug]) {
            foreach (self::NON_JSON_ACCEPTS as $accept => $headers) {
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

    /**
     * Provide non-JSON requests crossed with the exceptions the map renders as
     * an API exception.
     *
     * @return iterable<string, array{array<string, string|null>, string, int, array<string, string>}>
     */
    public static function mappedExceptionProvider(): iterable
    {
        $exceptions = [
            'mapped application failure' => ['/settled', 409, []],
            'unavailable lock'           => ['/lock-unavailable', 429, []],
            'remapped rate limit'        => ['/rate-limited', 409, ['Retry-After' => '30']],
        ];

        foreach (self::NON_JSON_ACCEPTS as $accept => $headers) {
            foreach ($exceptions as $exception => [$path, $status, $forwarded]) {
                yield sprintf('%s, %s', $accept, $exception) => [$headers, $path, $status, $forwarded];
            }
        }
    }

    /**
     * Test that a mapped exception left to Laravel's renderer outside debug
     * mode answers with the status and headers of the exception it maps to.
     *
     * @param  array<string, string|null>  $headers
     * @param  string  $path
     * @param  int  $status
     * @param  array<string, string>  $forwarded
     * @return void
     */
    #[DataProvider('mappedExceptionProvider')]
    public function testMappedExceptionLeftToLaravelKeepsTheMappedStatusAndHeaders(array $headers, string $path, int $status, array $forwarded): void
    {
        Config::set('api-toolkit.exceptions.render_strategy', 'json_when_expected');
        Config::set('app.debug', false);

        $response = $this->get($path, $headers);

        $response->assertStatus($status);
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');

        foreach ($forwarded as $name => $value) {
            $response->assertHeader($name, $value);
        }
    }

    /**
     * Provide each strategy that defers a non-JSON request in debug mode.
     *
     * @return iterable<string, array{string}>
     */
    public static function debugDeferringStrategyProvider(): iterable
    {
        yield 'auto' => ['auto'];

        yield 'json_when_expected' => ['json_when_expected'];
    }

    /**
     * Test that a mapped exception left to Laravel's renderer in debug mode
     * renders as thrown, so the error page shows the real exception.
     *
     * @param  string  $strategy
     * @return void
     */
    #[DataProvider('debugDeferringStrategyProvider')]
    public function testMappedExceptionInDebugModeRendersTheOriginal(string $strategy): void
    {
        Config::set('api-toolkit.exceptions.render_strategy', $strategy);
        Config::set('app.debug', true);

        $response = $this->get('/settled', ['Accept' => 'text/html']);

        $response->assertStatus(500);
        $response->assertSee(\DomainException::class);
        $response->assertSee(self::SETTLED_MESSAGE);
    }

    /**
     * Provide every strategy and debug mode, crossed with a request that
     * expects JSON and one that does not.
     *
     * @return iterable<string, array{string, bool, array<string, string>}>
     */
    public static function everyStrategyProvider(): iterable
    {
        $accepts = [
            'json' => ['Accept' => 'application/json'],
            'html' => ['Accept' => 'text/html'],
        ];

        foreach (['always_json', 'json_when_expected', 'auto'] as $strategy) {
            foreach ([false, true] as $debug) {
                foreach ($accepts as $accept => $headers) {
                    yield sprintf('%s, debug %s, %s', $strategy, $debug ? 'on' : 'off', $accept) => [$strategy, $debug, $headers];
                }
            }
        }
    }

    /**
     * Test that an exception the map does not match is a server error under
     * every strategy and debug mode.
     *
     * @param  string  $strategy
     * @param  bool  $debug
     * @param  array<string, string>  $headers
     * @return void
     */
    #[DataProvider('everyStrategyProvider')]
    public function testUnmappedExceptionIsAServerErrorUnderEveryStrategy(string $strategy, bool $debug, array $headers): void
    {
        Config::set('api-toolkit.exceptions.render_strategy', $strategy);
        Config::set('app.debug', $debug);

        $this->get('/unhandled', $headers)->assertStatus(500);
    }
}
