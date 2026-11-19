<?php

declare(strict_types = 1);

namespace Tests\Feature\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\BadRequestException;
use SineMacula\ApiToolkit\Exceptions\ConflictException;
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
use Tests\Fixtures\Input\DeniedFormRequest;
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
    use RecordsStatements, RegistersApiExceptionHandler;

    /** @var string The message a policy denies with */
    private const string DENIAL = 'Your trial has ended.';

    /** @var string The message an abort() carries */
    private const string ABORT_MESSAGE = 'Order already shipped';

    /** @var string The catalogue detail of a forbidden request */
    private const string FORBIDDEN_DETAIL = 'You do not have the necessary privileges to perform this action';

    /** @var string The catalogue detail of a missing resource */
    private const string NOT_FOUND_DETAIL = 'The requested resource could not be found';

    /** @var string The catalogue detail of an unavailable service */
    private const string UNAVAILABLE_DETAIL = 'The service is temporarily unavailable, please try again a little later';

    /** @var string The catalogue detail of an unhandled error */
    private const string UNHANDLED_DETAIL = 'Oh no! Something has gone wrong!';

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

        Route::get('/abort-with-message', static function (): never {
            abort(409, self::ABORT_MESSAGE);
        });

        Route::get('/missing-record', static function (): void {
            User::findOrFail(99999);
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

    /**
     * Provide policy results with the status, code, detail and reason each
     * renders behind the can middleware.
     *
     * @return iterable<string, array{bool|\Illuminate\Auth\Access\Response, int, int, string, string|null}>
     */
    public static function policyDenialProvider(): iterable
    {
        yield 'deny with a message and code' => [Response::deny(self::DENIAL, 'TRIAL_EXPIRED'), 403, 10102, self::DENIAL, 'TRIAL_EXPIRED'];
        yield 'deny without a message' => [Response::deny(), 403, 10102, self::FORBIDDEN_DETAIL, null];
        yield 'false' => [false, 403, 10102, self::FORBIDDEN_DETAIL, null];
        yield 'denyWithStatus(409)' => [Response::denyWithStatus(409, self::DENIAL, 'SHIPPED'), 409, 10113, self::DENIAL, 'SHIPPED'];
        yield 'denyAsNotFound()' => [Response::denyAsNotFound(), 404, 10103, self::NOT_FOUND_DETAIL, null];
        yield 'denyAsNotFound() with a message' => [Response::denyAsNotFound(self::DENIAL), 404, 10103, self::DENIAL, null];
        yield 'denyWithStatus(499)' => [Response::denyWithStatus(499, self::DENIAL), 500, 10001, self::UNHANDLED_DETAIL, null];
        yield 'denyWithStatus(503)' => [Response::denyWithStatus(503, self::DENIAL, 'DOWN'), 503, 10112, self::UNAVAILABLE_DETAIL, null];
    }

    /**
     * Test that a policy denial behind the can middleware renders its message
     * and code only when it renders as a client error.
     *
     * @param  bool|\Illuminate\Auth\Access\Response  $result
     * @param  int  $status
     * @param  int  $code
     * @param  string  $detail
     * @param  string|null  $reason
     * @return void
     */
    #[DataProvider('policyDenialProvider')]
    public function testPolicyDenialRendersItsMessageAndCodeAsAClientError(bool|Response $result, int $status, int $code, string $detail, ?string $reason): void
    {
        Gate::define('act', static fn (?Authenticatable $user): bool|Response => $result);

        Route::get('/policy', static fn (): array => ['ok' => true])->middleware('can:act');

        $response = $this->getJson('/policy');

        $response->assertStatus($status);
        $response->assertJsonPath('error.code', $code);
        $response->assertJsonPath('error.detail', $detail);
        $response->assertJsonPath('error.meta', $reason === null ? null : ['reason' => $reason]);
    }

    /**
     * Test that a policy throwing the default denial keeps the catalogue
     * detail.
     *
     * @return void
     */
    public function testPolicyThrowingTheDefaultDenialKeepsTheCatalogueDetail(): void
    {
        Gate::define('act', static fn (?Authenticatable $user): never => throw new AuthorizationException);

        Route::get('/policy', static fn (): array => ['ok' => true])->middleware('can:act');

        $this->getJson('/policy')->assertStatus(403)->assertJsonPath('error.detail', self::FORBIDDEN_DETAIL);
    }

    /**
     * Test that the gate's default denial response is carried for a policy
     * returning false.
     *
     * @return void
     */
    public function testDefaultDenialResponseIsCarried(): void
    {
        Gate::defaultDenialResponse(Response::deny(self::DENIAL));
        Gate::define('act', static fn (?Authenticatable $user): bool => false);

        Route::get('/policy', static fn (): array => ['ok' => true])->middleware('can:act');

        $this->getJson('/policy')->assertStatus(403)->assertJsonPath('error.detail', self::DENIAL);
    }

    /**
     * Test that a denial thrown by the action is carried.
     *
     * @return void
     */
    public function testDenialThrownByTheActionIsCarried(): void
    {
        Route::get('/thrown-denial', static function (): never {
            throw new AuthorizationException(self::DENIAL);
        });

        $this->getJson('/thrown-denial')->assertStatus(403)->assertJsonPath('error.detail', self::DENIAL);
    }

    /**
     * Test that a form request's authorization denial is carried.
     *
     * @return void
     */
    public function testFormRequestDenialIsCarried(): void
    {
        Route::post('/orders', static fn (DeniedFormRequest $request): array => ['ok' => true]);

        $this->postJson('/orders')->assertStatus(403)->assertJsonPath('error.detail', DeniedFormRequest::DENIAL);
    }

    /**
     * Provide failures whose message is not the application's to show, with the
     * status and detail each renders and the message it hides.
     *
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function unauthoredMessageProvider(): iterable
    {
        yield 'abort() with a message' => ['/abort-with-message', 409, 'The request could not be completed', self::ABORT_MESSAGE];
        yield 'missing record' => ['/missing-record', 404, self::NOT_FOUND_DETAIL, 'No query results'];
        yield 'missing route' => ['/unmatched', 404, self::NOT_FOUND_DETAIL, 'The route unmatched could not be found'];
    }

    /**
     * Test that a failure keeps its catalogue detail and hides its own message.
     *
     * @param  string  $uri
     * @param  int  $status
     * @param  string  $detail
     * @param  string  $hidden
     * @return void
     */
    #[DataProvider('unauthoredMessageProvider')]
    public function testUnauthoredMessageKeepsTheCatalogueDetail(string $uri, int $status, string $detail, string $hidden): void
    {
        Config::set('api-toolkit.exceptions.include_debug_info', false);

        $response = $this->getJson($uri);

        $response->assertStatus($status);
        $response->assertJsonPath('error.detail', $detail);
        $response->assertDontSee($hidden);
    }

    /**
     * Test that an abort() message appears only under the debug details.
     *
     * @return void
     */
    public function testAbortMessageAppearsOnlyUnderTheDebugDetails(): void
    {
        Config::set('api-toolkit.exceptions.include_debug_info', true);

        $response = $this->getJson('/abort-with-message');

        $response->assertJsonPath('error.detail', 'The request could not be completed');
        $response->assertJsonPath('error.debug.message', self::ABORT_MESSAGE);
    }

    /**
     * Test that a detail the exception is raised with is rendered.
     *
     * @return void
     */
    public function testRaisedDetailIsRendered(): void
    {
        Route::get('/already-invited', static function (): never {
            throw new ConflictException(detail: 'This email is already invited');
        });

        $this->getJson('/already-invited')->assertStatus(409)->assertJsonPath('error.detail', 'This email is already invited');
    }
}
