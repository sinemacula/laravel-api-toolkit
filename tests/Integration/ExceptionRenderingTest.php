<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\ConflictException;
use Tests\TestCase;

/**
 * End-to-end integration tests for exception rendering.
 *
 * Registers the toolkit exception handler against the application's real
 * exception handler (mirroring the bootstrap/app.php withExceptions()
 * wiring) and asserts the toolkit JSON error format through real HTTP
 * routes dispatched via the HTTP kernel.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiExceptionHandler::class)]
final class ExceptionRenderingTest extends TestCase
{
    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        assert($this->app !== null);

        $handler = $this->app->make(ExceptionHandlerContract::class);

        if (!$handler instanceof Handler) {
            self::fail('The application exception handler must extend the foundation handler.');
        }

        // Mirror the bootstrap/app.php wiring used by consuming applications:
        // ->withExceptions(fn (Exceptions $e) =>
        // ApiExceptionHandler::handles($e))
        ApiExceptionHandler::handles(new Exceptions($handler));

        config()->set('app.debug', false);
        config()->set('logging.channels.api-exceptions', [
            'driver'  => 'monolog',
            'handler' => NullHandler::class,
        ]);

        Route::get('/api/abort-conflict', static function (): never {
            abort(409);
        });

        Route::get('/api/toolkit-exception', static function (): never {
            throw new ConflictException(['resource' => 'order']);
        });

        Route::post('/api/validate', static function (Request $request): array {
            $request->validate(['email' => 'required|email']);

            return ['ok' => true];
        });

        Route::get('/api/token-mismatch', static function (): never {
            throw new TokenMismatchException('CSRF token mismatch.');
        });

        Route::get('/api/unhandled', static function (): never {
            throw new \RuntimeException('Something broke internally');
        });
    }

    /**
     * Test that abort() inside a route is rendered in the toolkit JSON
     * error format with the original status code preserved.
     *
     * @return void
     */
    public function testAbortIsRenderedInToolkitErrorFormat(): void
    {
        $response = $this->getJson('/api/abort-conflict');

        $response->assertStatus(409);
        $response->assertExactJson([
            'error' => [
                'status' => 409,
                'code'   => 10113,
                'title'  => 'Conflict',
                'detail' => 'The request could not be completed',
            ],
        ]);
    }

    /**
     * Test that a toolkit ApiException thrown inside a route is rendered
     * with its own status, code, and custom meta.
     *
     * @return void
     */
    public function testToolkitApiExceptionIsRenderedWithMeta(): void
    {
        $response = $this->getJson('/api/toolkit-exception');

        $response->assertStatus(409);
        $response->assertJson([
            'error' => [
                'status' => 409,
                'code'   => 10108,
                'title'  => 'Conflict',
                'meta'   => ['resource' => 'order'],
            ],
        ]);
    }

    /**
     * Test that a ValidationException raised by request validation is
     * rendered as a 422 with the validation errors as meta.
     *
     * @return void
     */
    public function testValidationExceptionIsRenderedWithValidationErrors(): void
    {
        $response = $this->postJson('/api/validate', []);

        $response->assertStatus(422);
        $response->assertJson([
            'error' => [
                'status' => 422,
                'code'   => 10106,
            ],
        ]);

        $errors = $response->json('error.meta.email');

        self::assertIsArray($errors);
        self::assertNotEmpty($errors);
    }

    /**
     * Test that a session token mismatch renders as 419 through the real
     * kernel.
     *
     * Laravel's handler converts TokenMismatchException to a generic 419
     * HttpException before render callbacks run; the mapper restores the
     * dedicated toolkit exception.
     *
     * @return void
     */
    public function testTokenMismatchIsRenderedAs419(): void
    {
        $response = $this->getJson('/api/token-mismatch');

        $response->assertStatus(419);
        $response->assertJsonPath('error.status', 419);
        $response->assertJsonPath('error.code', 10105);
    }

    /**
     * Test that an unexpected exception is rendered as an unhandled error
     * without leaking internal details when debug is disabled.
     *
     * @return void
     */
    public function testUnhandledExceptionIsRenderedWithoutInternalDetails(): void
    {
        $response = $this->getJson('/api/unhandled');

        $response->assertStatus(500);
        $response->assertExactJson([
            'error' => [
                'status' => 500,
                'code'   => 10001,
                'title'  => 'Unknown Error',
                'detail' => 'Oh no! Something has gone wrong!',
            ],
        ]);

        $content = $response->baseResponse->getContent();

        self::assertIsString($content);
        self::assertStringNotContainsString('Something broke internally', $content);
    }

    /**
     * Test that the json_when_expected strategy defers to Laravel's default
     * rendering for requests that do not expect JSON.
     *
     * @return void
     */
    public function testJsonWhenExpectedStrategyDefersToDefaultRenderingForHtmlRequests(): void
    {
        config()->set('api-toolkit.exceptions.render_strategy', 'json_when_expected');

        $response = $this->get('/api/abort-conflict', ['Accept' => 'text/html']);

        $response->assertStatus(409);
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $content = $response->baseResponse->getContent();

        self::assertIsString($content);
        self::assertStringNotContainsString('"error"', $content);
    }
}
