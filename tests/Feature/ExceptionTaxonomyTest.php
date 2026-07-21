<?php

declare(strict_types = 1);

namespace Tests\Feature;

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
 * Feature tests rounding out the exception taxonomy through the real kernel.
 *
 * With the toolkit handler wired as a consuming application wires it, a missing
 * model surfaces as a not-found envelope and an authorization failure surfaces
 * as a forbidden envelope, each carrying the matching HTTP status and internal
 * error code.
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

        // findOrFail on an empty table raises a ModelNotFoundException, which
        // Laravel prepares into a NotFoundHttpException before the toolkit
        // render callback maps it to the not-found envelope.
        Route::get('/api/model-missing', static function (): void {
            User::findOrFail(99999);
        });

        // A statusless AuthorizationException is prepared into an
        // AccessDeniedHttpException, which the toolkit maps to the forbidden
        // envelope. abort(403) would instead yield a generic HTTP error code.
        Route::get('/api/forbidden', static function (): never {
            throw new AuthorizationException('This action is unauthorized.');
        });

        // A GET-only route so that a POST raises the router's
        // MethodNotAllowedHttpException, which the toolkit maps to the
        // not-allowed envelope.
        Route::get('/api/get-only', static fn (): array => ['ok' => true]);

        // A route behind the auth middleware so an unauthenticated JSON caller
        // raises an AuthenticationException, which the toolkit maps to the
        // unauthenticated envelope.
        Route::get('/api/requires-auth', static fn (): array => ['ok' => true])
            ->middleware('auth');

        // Directly-thrown taxonomy exceptions render their dedicated envelopes
        // rather than the generic HTTP error an abort() would produce.
        Route::get('/api/bad-request', static function (): never {
            throw new BadRequestException;
        });

        Route::get('/api/gone', static function (): never {
            throw new GoneException;
        });

        Route::get('/api/payload-too-large', static function (): never {
            throw new PayloadTooLargeException;
        });

        Route::get('/api/locked', static function (): never {
            throw new LockedException;
        });

        Route::get('/api/service-unavailable', static function (): never {
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
        $response = $this->getJson('/api/model-missing');

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
        $response = $this->getJson('/api/forbidden');

        $response->assertStatus(403);
        $response->assertJsonPath('error.status', 403);
        $response->assertJsonPath('error.code', 10102);
    }

    /**
     * Test that posting to a GET-only route renders as a 405 not-allowed
     * envelope.
     *
     * @return void
     */
    public function testMethodNotAllowedIsRenderedAsNotAllowed(): void
    {
        $response = $this->postJson('/api/get-only');

        $response->assertStatus(405);
        $response->assertJsonPath('error.status', 405);
        $response->assertJsonPath('error.code', 10104);
        $response->assertJsonPath('error.title', 'Not Allowed');
    }

    /**
     * Test that an unauthenticated request to an auth-guarded route renders as
     * a 401 unauthenticated envelope.
     *
     * @return void
     */
    public function testAuthenticationFailureIsRenderedAsUnauthenticated(): void
    {
        $response = $this->getJson('/api/requires-auth');

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
            'bad request'         => ['/api/bad-request', 400, 10100, 'Bad Request', 'There was an issue with the request, please try again'],
            'gone'                => ['/api/gone', 410, 10109, 'Gone', 'The requested resource is no longer available'],
            'payload too large'   => ['/api/payload-too-large', 413, 10110, 'Payload Too Large', 'The request payload exceeds the maximum permitted size'],
            'locked'              => ['/api/locked', 423, 10111, 'Locked', 'The requested resource is locked'],
            'service unavailable' => ['/api/service-unavailable', 503, 10112, 'Service Unavailable', 'The service is temporarily unavailable, please try again a little later'],
        ];
    }

    /**
     * Test that a directly-thrown taxonomy exception renders its dedicated
     * status, code, title, and detail rather than the generic HTTP error.
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
