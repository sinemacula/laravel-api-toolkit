<?php

declare(strict_types = 1);

namespace Tests\Feature\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\BadRequestException;
use SineMacula\ApiToolkit\Exceptions\GoneException;
use SineMacula\ApiToolkit\Exceptions\LockedException;
use SineMacula\ApiToolkit\Exceptions\PayloadTooLargeException;
use SineMacula\ApiToolkit\Exceptions\ServiceUnavailableException;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Feature tests for the exception taxonomy through the real kernel.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiExceptionHandler::class)]
final class ExceptionTaxonomyTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with the toolkit handler wired and throwing routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        // Laravel prepares this into a NotFoundHttpException
        Route::get('/model-missing', static function (): void {
            User::findOrFail(99999);
        });

        // A statusless AuthorizationException is prepared into an
        // AccessDeniedHttpException; abort(403) would yield a generic code
        Route::get('/forbidden', static function (): never {
            throw new AuthorizationException('This action is unauthorized.');
        });

        Route::get('/get-only', static fn (): array => ['ok' => true]);

        Route::get('/requires-auth', static fn (): array => ['ok' => true])
            ->middleware('auth');

        Route::get('/bad-request', static function (): never {
            throw new BadRequestException;
        });

        Route::get('/gone', static function (): never {
            throw new GoneException;
        });

        Route::get('/payload-too-large', static function (): never {
            throw new PayloadTooLargeException;
        });

        Route::get('/locked', static function (): never {
            throw new LockedException;
        });

        Route::get('/service-unavailable', static function (): never {
            throw new ServiceUnavailableException;
        });
    }

    /**
     * Test that a missing model renders as a 404 not-found envelope.
     *
     * @return void
     */
    public function testModelNotFoundIsRenderedAsNotFound(): void
    {
        $response = $this->getJson('/model-missing');

        $response->assertStatus(404);
        $response->assertJsonPath('error.status', 404);
        $response->assertJsonPath('error.code', 10103);
    }

    /**
     * Test that an authorization failure renders as a 403 forbidden envelope.
     *
     * @return void
     */
    public function testAuthorizationFailureIsRenderedAsForbidden(): void
    {
        $response = $this->getJson('/forbidden');

        $response->assertStatus(403);
        $response->assertJsonPath('error.status', 403);
        $response->assertJsonPath('error.code', 10102);
    }

    /**
     * Test that posting to a GET-only route renders a 405 not-allowed envelope.
     *
     * @return void
     */
    public function testMethodNotAllowedIsRenderedAsNotAllowed(): void
    {
        $response = $this->postJson('/get-only');

        $response->assertStatus(405);
        $response->assertJsonPath('error.status', 405);
        $response->assertJsonPath('error.code', 10104);
        $response->assertJsonPath('error.title', 'Not Allowed');
    }

    /**
     * Test that an unauthenticated request to an auth-guarded route renders a
     * 401 envelope.
     *
     * @return void
     */
    public function testAuthenticationFailureIsRenderedAsUnauthenticated(): void
    {
        $response = $this->getJson('/requires-auth');

        $response->assertStatus(401);
        $response->assertJsonPath('error.status', 401);
        $response->assertJsonPath('error.code', 10101);
        $response->assertJsonPath('error.title', 'Unauthenticated');
    }

    /**
     * Provide the directly-thrown taxonomy exceptions and their envelopes.
     *
     * @return iterable<string, array{string, int, int, string, string}>
     */
    public static function taxonomyExceptionProvider(): iterable
    {
        yield from [
            'bad request'         => ['/bad-request', 400, 10100, 'Bad Request', 'There was an issue with the request, please try again'],
            'gone'                => ['/gone', 410, 10109, 'Gone', 'The requested resource is no longer available'],
            'payload too large'   => ['/payload-too-large', 413, 10110, 'Payload Too Large', 'The request payload exceeds the maximum permitted size'],
            'locked'              => ['/locked', 423, 10111, 'Locked', 'The requested resource is locked'],
            'service unavailable' => ['/service-unavailable', 503, 10112, 'Service Unavailable', 'The service is temporarily unavailable, please try again a little later'],
        ];
    }

    /**
     * Test that a directly-thrown taxonomy exception renders its dedicated
     * envelope.
     *
     * @param  string  $path
     * @param  int  $status
     * @param  int  $code
     * @param  string  $title
     * @param  string  $detail
     * @return void
     */
    #[DataProvider('taxonomyExceptionProvider')]
    public function testDirectlyThrownTaxonomyExceptionsRenderTheirEnvelopes(string $path, int $status, int $code, string $title, string $detail): void
    {
        $response = $this->getJson($path);

        $response->assertStatus($status);
        $response->assertJsonPath('error.status', $status);
        $response->assertJsonPath('error.code', $code);
        $response->assertJsonPath('error.title', $title);
        $response->assertJsonPath('error.detail', $detail);
    }
}
