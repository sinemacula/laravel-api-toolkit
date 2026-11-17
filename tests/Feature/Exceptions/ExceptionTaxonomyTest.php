<?php

declare(strict_types = 1);

namespace Tests\Feature\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\BadRequestException;
use SineMacula\ApiToolkit\Exceptions\GoneException;
use SineMacula\ApiToolkit\Exceptions\LockedException;
use SineMacula\ApiToolkit\Exceptions\PayloadTooLargeException;
use SineMacula\ApiToolkit\Exceptions\ServiceUnavailableException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException as SymfonyBadRequestException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException as SymfonyHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\RecordsStatements;
use Tests\Concerns\RegistersApiExceptionHandler;
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
    use RecordsStatements, RegistersApiExceptionHandler;

    /** @var class-string<\Throwable>|null The class the render callbacks received */
    private ?string $received = null;

    /**
     * Set up each test with the toolkit handler wired and throwing routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->exceptionConfiguration()->render(function (\Throwable $exception): null {
            $this->received = $exception::class;

            return null;
        });

        $this->registerApiExceptionHandler();

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
     * Test that an unmatched route renders the not-found envelope without
     * touching the database.
     *
     * @return void
     */
    public function testUnmatchedRouteIsRenderedAsNotFoundWithoutStatements(): void
    {
        $statements = $this->recordStatements(fn () => $this->getJson('/unmatched')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 10103));

        self::assertStatementCount(0, $statements);
    }

    /**
     * Provide exceptions Laravel converts before rendering, with the class the
     * render callbacks receive and the status and code it renders.
     *
     * @return iterable<string, array{\Closure(): \Throwable, class-string<\Throwable>, int, int}>
     */
    public static function preparedExceptionProvider(): iterable
    {
        yield 'model not found' => [static fn (): \Throwable => new ModelNotFoundException, NotFoundHttpException::class, 404, 10103];
        yield 'backed enum case not found' => [static fn (): \Throwable => new BackedEnumCaseNotFoundException('Status', 'none'), NotFoundHttpException::class, 404, 10103];
        yield 'records not found' => [static fn (): \Throwable => new RecordsNotFoundException, NotFoundHttpException::class, 404, 10103];
        yield 'record not found' => [static fn (): \Throwable => new RecordNotFoundException, NotFoundHttpException::class, 404, 10103];
        yield 'suspicious operation' => [static fn (): \Throwable => new SuspiciousOperationException, BadRequestHttpException::class, 400, 10100];
        yield 'request exception' => [static fn (): \Throwable => new SymfonyBadRequestException, BadRequestHttpException::class, 400, 10100];
        yield 'authorization without a status' => [static fn (): \Throwable => new AuthorizationException, AccessDeniedHttpException::class, 403, 10102];
        yield 'authorization with 419' => [static fn (): \Throwable => (new AuthorizationException)->withStatus(419), SymfonyHttpException::class, 419, 10105];
        yield 'authorization with an unknown status' => [static fn (): \Throwable => (new AuthorizationException)->withStatus(499), SymfonyHttpException::class, 500, 10001];
        yield 'token mismatch' => [static fn (): \Throwable => new TokenMismatchException, SymfonyHttpException::class, 419, 10105];
        yield 'origin mismatch' => [static fn (): \Throwable => new OriginMismatchException, SymfonyHttpException::class, 403, 10102];
    }

    /**
     * Test that an exception Laravel converts reaches the render callbacks as
     * the exception it becomes, and renders as that.
     *
     * @param  \Closure(): \Throwable  $factory
     * @param  class-string<\Throwable>  $received
     * @param  int  $status
     * @param  int  $code
     * @return void
     */
    #[DataProvider('preparedExceptionProvider')]
    public function testPreparedExceptionRendersAsTheExceptionItBecomes(\Closure $factory, string $received, int $status, int $code): void
    {
        Route::get('/prepared', static function () use ($factory): never {
            throw $factory();
        });

        $response = $this->getJson('/prepared');

        self::assertSame($received, $this->received);

        $response->assertStatus($status);
        $response->assertJsonPath('error.status', $status);
        $response->assertJsonPath('error.code', $code);
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
