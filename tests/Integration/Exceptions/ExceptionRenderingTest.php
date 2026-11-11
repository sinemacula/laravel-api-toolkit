<?php

declare(strict_types = 1);

namespace Tests\Integration\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\ConflictException;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder;
use Tests\TestCase;

/**
 * End-to-end integration tests for exception rendering.
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

        self::assertInstanceOf(Handler::class, $handler);

        // Mirror the bootstrap/app.php withExceptions() wiring consumers use
        ApiExceptionHandler::handles(new Exceptions($handler));

        config()->set('app.debug', false);

        Route::get('/abort-conflict', static function (): never {
            abort(409);
        });

        Route::get('/toolkit-exception', static function (): never {
            throw new ConflictException(['resource' => 'order']);
        });

        Route::post('/validate', static function (Request $request): array {
            $request->validate(['email' => 'required|email']); // @phpstan-ignore staticMethod.dynamicCall

            return ['ok' => true];
        });

        Route::get('/token-mismatch', static function (): never {
            throw new TokenMismatchException('CSRF token mismatch.');
        });

        // Like the 419 mismatch: no status enum case and no title translation
        Route::get('/missing-title', static function (): never {
            throw new class extends ApiException {
                /** The internal error code for the test exception. */
                public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::TOKEN_MISMATCH;

                /**
                 * Return a status code with no HTTP status enum case.
                 *
                 * @return int
                 */
                #[\Override]
                public static function getHttpStatusCode(): int
                {
                    return 419;
                }

                /**
                 * Resolve from a namespace with no registered translations.
                 *
                 * @return string
                 */
                #[\Override]
                protected function getNamespace(): string
                {
                    return 'missing-title-namespace';
                }
            };
        });

        Route::get('/unhandled', static function (): never {
            throw new \RuntimeException('Something broke internally');
        });
    }

    /**
     * Test that abort() renders in the toolkit error format with its status.
     *
     * @return void
     */
    public function testAbortIsRenderedInToolkitErrorFormat(): void
    {
        $response = $this->getJson('/abort-conflict');

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
     * Test that a toolkit ApiException renders with its status, code and meta.
     *
     * @return void
     */
    public function testToolkitApiExceptionIsRenderedWithMeta(): void
    {
        $response = $this->getJson('/toolkit-exception');

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
     * Test that a ValidationException renders as a 422 with the errors as meta.
     *
     * @return void
     */
    public function testValidationExceptionIsRenderedWithValidationErrors(): void
    {
        $response = $this->postJson('/validate', []);

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
     * Test that a session token mismatch renders as 419 through the kernel.
     *
     * Laravel converts TokenMismatchException to a generic 419 HttpException
     * before render callbacks run.
     *
     * @return void
     */
    public function testTokenMismatchIsRenderedAs419(): void
    {
        $response = $this->getJson('/token-mismatch');

        $response->assertStatus(419);
        $response->assertJsonPath('error.status', 419);
        $response->assertJsonPath('error.code', 10105);
    }

    /**
     * Test that an exception with no title translation gets a fallback title.
     *
     * @return void
     */
    public function testExceptionWithMissingTitleTranslationStillRenders(): void
    {
        $response = $this->getJson('/missing-title');

        $response->assertStatus(419);
        $response->assertJsonPath('error.status', 419);
        $response->assertJsonPath('error.title', 'Unknown Error');
    }

    /**
     * Test that an unhandled exception leaks no internal details without debug.
     *
     * @return void
     */
    public function testUnhandledExceptionIsRenderedWithoutInternalDetails(): void
    {
        $response = $this->getJson('/unhandled');

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
     * Test that json_when_expected defers to default rendering for HTML.
     *
     * @return void
     */
    public function testJsonWhenExpectedStrategyDefersToDefaultRenderingForHtmlRequests(): void
    {
        config()->set('api-toolkit.exceptions.render_strategy', 'json_when_expected');

        $response = $this->get('/abort-conflict', ['Accept' => 'text/html']);

        $response->assertStatus(409);
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $content = $response->baseResponse->getContent();

        self::assertIsString($content);
        self::assertStringNotContainsString('"error"', $content);
    }

    /**
     * Test that the rendered error payload matches the documented envelope.
     *
     * The ErrorEnvelope schema is hand-mirrored, so this stops it drifting from
     * the runtime payload.
     *
     * @return void
     */
    public function testRenderedErrorPayloadMatchesDocumentedEnvelopeSchema(): void
    {
        assert($this->app !== null);

        $schema     = $this->app->make(ErrorResponseBuilder::class)->buildEnvelopeSchema();
        $properties = data_get($schema, 'properties.error.properties');

        assert(is_array($properties));

        $full = (array) $this->getJson('/toolkit-exception')->json('error');

        self::assertEqualsCanonicalizing(array_keys($properties), array_keys($full));

        $minimal  = (array) $this->getJson('/unhandled')->json('error');
        $envelope = Helper::toJSON($schema);

        self::assertIsObject($envelope);

        foreach ([$full, $minimal] as $payload) {

            $result = (new Validator)->validate(Helper::toJSON(['error' => $payload]), $envelope);

            self::assertTrue(
                $result->isValid(),
                'The rendered error payload must validate against the ErrorEnvelope schema.',
            );
        }
    }
}
