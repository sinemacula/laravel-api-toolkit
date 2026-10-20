<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Session\TokenMismatchException as LaravelTokenMismatchException;
use Illuminate\Validation\UnauthorizedException as LaravelUnauthorizedException;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\BadRequestException;
use SineMacula\ApiToolkit\Exceptions\ConflictException;
use SineMacula\ApiToolkit\Exceptions\LockUnavailableException;
use SineMacula\ApiToolkit\Exceptions\MaintenanceModeException;
use SineMacula\ApiToolkit\Exceptions\TokenMismatchException;
use SineMacula\ApiToolkit\Exceptions\UnhandledException;
use SineMacula\Http\Enums\HttpMethod;
use Symfony\Component\HttpFoundation\Exception\BadRequestException as SymfonyBadRequestException;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException as SymfonyHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface as SymfonyHttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Tests for the ApiExceptionHandler.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiExceptionHandler::class)]
final class ApiExceptionHandlerTest extends TestCase
{
    /** @var string Test API endpoint path. */
    private const string API_PATH = '/test';

    /** @var string JSON content type header value. */
    private const string ACCEPT_JSON = 'application/json';

    /** @var string Generic error message used in test fixtures. */
    private const string GENERIC_ERROR_MESSAGE = 'Something went wrong';

    /**
     * Provide exception mapping test cases.
     *
     * @return iterable<string, array{\Throwable, int, int}>
     */
    public static function exceptionMappingProvider(): iterable
    {
        yield 'NotFoundHttpException -> 404' => [
            new NotFoundHttpException,
            404,
            10103,
        ];

        yield 'ModelNotFoundException -> 404' => [
            new ModelNotFoundException,
            404,
            10103,
        ];

        yield 'BackedEnumCaseNotFoundException -> 404' => [
            new BackedEnumCaseNotFoundException('App\Enums\Status', 'unknown'),
            404,
            10103,
        ];

        yield 'SuspiciousOperationException -> 404' => [
            new SuspiciousOperationException,
            404,
            10103,
        ];

        yield 'RecordsNotFoundException -> 404' => [
            new RecordsNotFoundException,
            404,
            10103,
        ];

        yield 'AuthorizationException -> 403' => [
            new AuthorizationException,
            403,
            10102,
        ];

        yield 'LaravelUnauthorizedException -> 403' => [
            new LaravelUnauthorizedException,
            403,
            10102,
        ];

        yield 'AccessDeniedHttpException -> 403' => [
            new AccessDeniedHttpException,
            403,
            10102,
        ];

        yield 'AuthenticationException -> 401' => [
            new AuthenticationException,
            401,
            10101,
        ];

        yield 'TooManyRequestsHttpException -> 429' => [
            new TooManyRequestsHttpException,
            429,
            10107,
        ];

        yield 'MethodNotAllowedHttpException -> 405' => [
            new MethodNotAllowedHttpException(['GET', 'POST']),
            405,
            10104,
        ];

        yield 'BadRequestHttpException -> 400' => [
            new BadRequestHttpException,
            400,
            10100,
        ];

        yield 'Symfony request exception -> 400' => [
            new SymfonyBadRequestException,
            400,
            10100,
        ];

        yield 'ServiceUnavailableHttpException -> 503' => [
            new ServiceUnavailableHttpException,
            503,
            10112,
        ];

        yield 'PostTooLargeException -> 413' => [
            new PostTooLargeException,
            413,
            10110,
        ];

        yield 'abort(409) -> 409' => [
            new SymfonyHttpException(409),
            409,
            10113,
        ];

        yield 'abort(423) -> 423' => [
            new SymfonyHttpException(423),
            423,
            10113,
        ];

        yield 'abort(451) -> 451' => [
            new SymfonyHttpException(451),
            451,
            10113,
        ];

        yield 'HttpException with unknown status -> 500' => [
            new SymfonyHttpException(599),
            500,
            10001,
        ];

        yield 'Generic exception -> 500' => [
            new \RuntimeException(self::GENERIC_ERROR_MESSAGE),
            500,
            10001,
        ];
    }

    /**
     * Test that render maps various Laravel exceptions to the correct HTTP
     * status and internal error code.
     *
     * @param  \Throwable  $inputException
     * @param  int  $expectedHttpCode
     * @param  int  $expectedErrorCode
     * @return void
     */
    #[DataProvider('exceptionMappingProvider')]
    public function testRenderMapsExceptionsCorrectly(\Throwable $inputException, int $expectedHttpCode, int $expectedErrorCode): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, $inputException, $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame($expectedHttpCode, $response->getStatusCode());

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertSame($expectedHttpCode, $data['error']['status']);
        self::assertSame($expectedErrorCode, $data['error']['code']);
    }

    /**
     * Test that the generic catch-all preserves the original status code,
     * derives the title from the HTTP status, and uses the generic error code.
     *
     * @return void
     */
    public function testGenericHttpExceptionCatchAllPreservesStatusAndDerivesTitle(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, new SymfonyHttpException(409), $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(409, $response->getStatusCode());

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertSame(409, $data['error']['status']);
        self::assertSame(10113, $data['error']['code']);
        self::assertSame('Conflict', $data['error']['title']);
    }

    /**
     * Test that the generic catch-all preserves headers from the original HTTP
     * exception.
     *
     * @return void
     */
    public function testGenericHttpExceptionCatchAllPreservesHeaders(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $exception = new SymfonyHttpException(429, 'Too many requests', null, ['Retry-After' => '120']);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, $exception, $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame('120', $response->headers->get('Retry-After'));
    }

    /**
     * Test that application-layer database exceptions are not mapped to HTTP
     * semantics and remain 500.
     *
     * @return void
     */
    public function testUniqueConstraintViolationRemainsUnhandled(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $exception = new UniqueConstraintViolationException(
            'testing',
            'insert into users',
            [],
            new \RuntimeException('Duplicate entry'),
        );

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, $exception, $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());
    }

    /**
     * Test that a LockUnavailableException is mapped to a 429 response.
     *
     * @return void
     */
    public function testLockUnavailableMapsTo429(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, new LockUnavailableException, $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(429, $response->getStatusCode());

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertSame(429, $data['error']['status']);
        self::assertSame(10107, $data['error']['code']);
    }

    /**
     * Test that JSON rendering includes the expected error structure.
     *
     * @return void
     */
    public function testJsonRenderingIncludesErrorStructure(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, new NotFoundHttpException, $request);

        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        self::assertArrayHasKey('error', $data);
        self::assertArrayHasKey('status', $data['error']);
        self::assertArrayHasKey('code', $data['error']);
        self::assertArrayHasKey('title', $data['error']);
        self::assertArrayHasKey('detail', $data['error']);
    }

    /**
     * Test that debug mode includes meta with exception trace.
     *
     * @return void
     */
    public function testDebugModeIncludesMetaWithTrace(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', true);

        $original = new \RuntimeException(self::GENERIC_ERROR_MESSAGE);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, $original, $request);

        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        self::assertArrayHasKey('meta', $data['error']);
        self::assertArrayHasKey('exception', $data['error']['meta']);
        self::assertArrayHasKey('trace', $data['error']['meta']);
        self::assertSame(self::GENERIC_ERROR_MESSAGE, $data['error']['meta']['message']);
    }

    /**
     * Test that non-JSON request in debug mode returns null.
     *
     * @return void
     */
    public function testNonJsonRequestInDebugModeReturnsNull(): void
    {
        $request = Request::create('/test', HttpMethod::GET->getVerb());
        $request->headers->set('Accept', 'text/html');

        config()->set('app.debug', true);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, new \RuntimeException('error'), $request);

        self::assertNull($response);
    }

    /**
     * Test that an already-mapped ApiException is rendered directly.
     *
     * @return void
     */
    public function testApiExceptionIsRenderedDirectly(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $exception = new BadRequestException(['field' => 'value']);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, $exception, $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());

        $data = $response->getData(true);

        self::assertSame(10100, $data['error']['code']);
    }

    /**
     * Test that ValidationException maps to 422.
     *
     * This test is separate because ValidationException requires a Validator
     * instance that needs the Laravel translator.
     *
     * @return void
     */
    public function testValidationExceptionMapsToUnprocessableEntity(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $validator = new Validator(app('translator'), [], []);
        $exception = new ValidationException($validator);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, $exception, $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(422, $response->getStatusCode());

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertSame(10106, $data['error']['code']);
    }

    /**
     * Test that a Laravel session token mismatch maps to the toolkit token
     * mismatch exception with the non-standard 419 status code.
     *
     * The mapping is asserted directly because rendering the toolkit
     * TokenMismatchException requires the package translations, which are not
     * registered in this isolated test application.
     *
     * @return void
     */
    public function testTokenMismatchExceptionMapsToToolkitTokenMismatch(): void
    {
        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'mapApiException');
        $mapped     = $reflection->invoke(null, new LaravelTokenMismatchException('CSRF token mismatch.'));

        self::assertInstanceOf(TokenMismatchException::class, $mapped);
        self::assertSame(419, $mapped->getStatusCode());
        self::assertSame(10105, $mapped::getInternalErrorCode());
    }

    /**
     * Test that the json_when_expected strategy returns null for requests that
     * do not expect JSON.
     *
     * @return void
     */
    public function testJsonWhenExpectedStrategyReturnsNullForNonJsonRequests(): void
    {
        $request = Request::create('/test', HttpMethod::GET->getVerb());
        $request->headers->set('Accept', 'text/html');

        config()->set('app.debug', false);
        config()->set('api-toolkit.exceptions.render_strategy', 'json_when_expected');

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, new \RuntimeException('error'), $request);

        self::assertNull($response);
    }

    /**
     * Test that the json_when_expected strategy renders JSON when the request
     * expects JSON.
     *
     * @return void
     */
    public function testJsonWhenExpectedStrategyRendersJsonWhenJsonIsExpected(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);
        config()->set('api-toolkit.exceptions.render_strategy', 'json_when_expected');

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, new \RuntimeException('error'), $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());
    }

    /**
     * Test that the auto strategy renders JSON for non-JSON requests when debug
     * mode is disabled.
     *
     * @return void
     */
    public function testAutoStrategyRendersJsonForNonJsonRequestsWhenDebugDisabled(): void
    {
        $request = Request::create('/test', HttpMethod::GET->getVerb());
        $request->headers->set('Accept', 'text/html');

        config()->set('app.debug', false);
        config()->set('api-toolkit.exceptions.render_strategy', 'auto');

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, new \RuntimeException('error'), $request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());
    }

    /**
     * Test that the pretty request parameter produces indented JSON output.
     *
     * @return void
     */
    public function testPrettyParameterFormatsJsonWithIndentation(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb(), ['pretty' => '1']);
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, new NotFoundHttpException, $request);

        self::assertInstanceOf(JsonResponse::class, $response);

        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringContainsString("\n    ", $content);
    }

    /**
     * Test that JSON output is compact when the pretty request parameter is not
     * provided.
     *
     * @return void
     */
    public function testJsonOutputIsCompactWithoutPrettyParameter(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, new NotFoundHttpException, $request);

        self::assertInstanceOf(JsonResponse::class, $response);

        $content = $response->getContent();

        self::assertIsString($content);
        self::assertStringNotContainsString("\n", $content);
    }

    /**
     * Test that an empty meta value is omitted from the error payload.
     *
     * @return void
     */
    public function testEmptyMetaIsOmittedFromErrorPayload(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, new NotFoundHttpException, $request);

        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertArrayNotHasKey('meta', $data['error']);
    }

    /**
     * Test that the include_debug_info config takes precedence over a disabled
     * debug mode.
     *
     * @return void
     */
    public function testIncludeDebugInfoConfigTakesPrecedenceOverDisabledDebugMode(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);
        config()->set('api-toolkit.exceptions.include_debug_info', true);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, new \RuntimeException(self::GENERIC_ERROR_MESSAGE), $request);

        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertArrayHasKey('meta', $data['error']);
        self::assertSame(self::GENERIC_ERROR_MESSAGE, $data['error']['meta']['message']);
        self::assertArrayHasKey('trace', $data['error']['meta']);
    }

    /**
     * Test that the include_debug_info config takes precedence over an enabled
     * debug mode.
     *
     * @return void
     */
    public function testIncludeDebugInfoConfigTakesPrecedenceOverEnabledDebugMode(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', true);
        config()->set('api-toolkit.exceptions.include_debug_info', false);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, new \RuntimeException(self::GENERIC_ERROR_MESSAGE), $request);

        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertArrayNotHasKey('meta', $data['error']);
    }

    /**
     * Test that custom meta is returned untouched when debug mode is enabled
     * but the exception has no previous exception.
     *
     * @return void
     */
    public function testCustomMetaIsReturnedWhenDebugEnabledWithoutPreviousException(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', true);

        $exception = new BadRequestException(['custom' => 'value']);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, $exception, $request);

        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        self::assertIsArray($data);
        self::assertSame(['custom' => 'value'], $data['error']['meta']);
    }

    /**
     * Test that debug meta merges custom meta with the previous exception
     * details.
     *
     * @return void
     */
    public function testDebugMetaMergesCustomMetaWithPreviousExceptionDetails(): void
    {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', true);

        $previous  = new \RuntimeException(self::GENERIC_ERROR_MESSAGE);
        $exception = new BadRequestException(['custom' => 'value'], null, $previous);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, $exception, $request);

        self::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        self::assertIsArray($data);

        $meta = $data['error']['meta'];

        self::assertSame('value', $meta['custom']);
        self::assertSame(self::GENERIC_ERROR_MESSAGE, $meta['message']);
        self::assertSame(\RuntimeException::class, $meta['exception']);
        self::assertSame($previous->getFile(), $meta['file']);
        self::assertSame($previous->getLine(), $meta['line']);
        self::assertArrayHasKey('trace', $meta);
    }

    /**
     * Test that debug trace frames exclude call arguments.
     *
     * @return void
     */
    public function testDebugTraceFramesExcludeCallArguments(): void
    {
        $original = ini_set('zend.exception_ignore_args', '0');

        try {
            $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
            $request->headers->set('Accept', self::ACCEPT_JSON);

            config()->set('app.debug', true);

            $previous = call_user_func(
                static fn (string $secret): \RuntimeException => new \RuntimeException(self::GENERIC_ERROR_MESSAGE),
                'sensitive-value',
            );

            $framesWithArgs = array_filter(
                $previous->getTrace(),
                static fn (array $frame): bool => array_key_exists('args', $frame),
            );

            self::assertNotEmpty($framesWithArgs);

            $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
            $response   = $reflection->invoke(null, $previous, $request);

            self::assertInstanceOf(JsonResponse::class, $response);

            $data = $response->getData(true);

            self::assertIsArray($data);
            self::assertArrayHasKey('error', $data);
            self::assertArrayHasKey('meta', $data['error']);
            self::assertArrayHasKey('trace', $data['error']['meta']);
            self::assertIsArray($data['error']['meta']['trace']);
            self::assertNotEmpty($data['error']['meta']['trace']);

            // Assert on the whole frame set at once rather than per frame: the
            // number of trace frames is the ambient call-stack depth, which
            // differs between a serial run and a paratest worker, so a
            // per-frame loop makes the assertion count runner-dependent (and
            // the test non-deterministic). A single set assertion proves no
            // rendered frame retains call arguments regardless of depth.
            $renderedFramesWithArgs = array_filter(
                $data['error']['meta']['trace'],
                static fn (array $frame): bool => array_key_exists('args', $frame),
            );

            self::assertSame([], $renderedFramesWithArgs);
        } finally {
            if ($original !== false) {
                ini_set('zend.exception_ignore_args', $original);
            }
        }
    }

    /**
     * Provide the position of an extra reportable relative to handles().
     *
     * @return iterable<string, array{bool}>
     */
    public static function registrationOrderProvider(): iterable
    {
        yield 'reportable registered before handles()' => [true];

        yield 'reportable registered after handles()' => [false];
    }

    /**
     * Test that a handled API exception is not reported whatever the order in
     * which another reportable was registered, while any other exception is
     * still reported as itself.
     *
     * @param  bool  $reportableFirst
     * @return void
     */
    #[DataProvider('registrationOrderProvider')]
    public function testHandledExceptionIsNotReportedWhateverTheRegistrationOrder(bool $reportableFirst): void
    {
        [$handler, $exceptions] = $this->exceptionHandler();

        $captured = [];
        $capture  = static function (\Throwable $exception) use (&$captured): bool {
            $captured[] = $exception;

            return false;
        };

        if ($reportableFirst) {
            $exceptions->report($capture);
        }

        ApiExceptionHandler::handles($exceptions);

        if (!$reportableFirst) {
            $exceptions->report($capture);
        }

        $runtime = new \RuntimeException(self::GENERIC_ERROR_MESSAGE);

        $handler->report(new ConflictException);
        $handler->report($runtime);

        self::assertSame([$runtime], $captured);
    }

    /**
     * Test that a deliberately thrown 5xx API exception is handled and so not
     * reported.
     *
     * @return void
     */
    public function testMaintenanceModeExceptionIsNotReported(): void
    {
        [$handler, $exceptions] = $this->exceptionHandler();

        self::assertTrue($handler->shouldReport(new MaintenanceModeException));

        ApiExceptionHandler::handles($exceptions);

        self::assertFalse($handler->shouldReport(new MaintenanceModeException));
    }

    /**
     * Test that a hand-thrown unhandled exception is still reported as the same
     * instance.
     *
     * @return void
     */
    public function testHandThrownUnhandledExceptionIsReported(): void
    {
        [$handler, $exceptions] = $this->exceptionHandler();

        $captured = [];

        ApiExceptionHandler::handles($exceptions);

        $exceptions->report(static function (\Throwable $exception) use (&$captured): bool {
            $captured[] = $exception;

            return false;
        });

        $unhandled = new UnhandledException;

        $handler->report($unhandled);

        self::assertSame([$unhandled], $captured);
    }

    /**
     * Test that Laravel's validation unauthorized exception is not reported
     * once the toolkit handler is registered.
     *
     * @return void
     */
    public function testLaravelUnauthorizedExceptionIsNotReported(): void
    {
        [$handler, $exceptions] = $this->exceptionHandler();

        self::assertTrue($handler->shouldReport(new LaravelUnauthorizedException));

        ApiExceptionHandler::handles($exceptions);

        self::assertFalse($handler->shouldReport(new LaravelUnauthorizedException));
    }

    /**
     * Test that a non-API exception is rendered through the registered handler
     * as the generic 500 envelope without leaking its internal message.
     *
     * @return void
     */
    public function testNonApiExceptionRendersAsGenericServerError(): void
    {
        config()->set('app.debug', false);
        config()->set('api-toolkit.exceptions.include_debug_info', false);

        [$handler, $exceptions] = $this->exceptionHandler();

        ApiExceptionHandler::handles($exceptions);

        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        $response = $handler->render($request, new \RuntimeException('internal detail'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame([
            'error' => [
                'status' => 500,
                'code'   => UnhandledException::getInternalErrorCode(),
                'title'  => 'Unknown Error',
                'detail' => 'Oh no! Something has gone wrong!',
            ],
        ], $response->getData(true));
        self::assertStringNotContainsString('internal detail', (string) $response->getContent());
    }

    /**
     * Test that the reporting samples cover every entry of the handler's
     * exception map, so a new mapping cannot skip the reporting check.
     *
     * @return void
     */
    public function testReportingSamplesCoverEveryExceptionMapEntry(): void
    {
        $map = (new \ReflectionMethod(ApiExceptionHandler::class, 'exceptionMap'))->invoke(null);

        self::assertIsArray($map);

        $mapped  = array_keys($map);
        $sampled = array_column(iterator_to_array(self::reportingSampleProvider()), 0);

        sort($mapped);
        sort($sampled);

        self::assertSame($mapped, $sampled);
    }

    /**
     * Provide statuses carried by an HTTP exception that does not extend the
     * Symfony base class, with whether the toolkit reports it.
     *
     * @return iterable<string, array{int, int, bool}>
     */
    public static function httpExceptionImplementorProvider(): iterable
    {
        yield 'client error' => [409, 409, false];

        yield 'session token mismatch' => [419, 419, false];

        yield 'server error' => [503, 503, true];

        yield 'unknown status' => [499, 500, true];
    }

    /**
     * Test that an HTTP exception implementing the Symfony interface without
     * extending its base class is reported only when it renders as a server
     * error.
     *
     * @param  int  $status
     * @param  int  $expectedStatus
     * @param  bool  $expectedReported
     * @return void
     */
    #[DataProvider('httpExceptionImplementorProvider')]
    public function testHttpExceptionImplementorIsReportedOnlyAsServerError(int $status, int $expectedStatus, bool $expectedReported): void
    {
        $exception = self::httpExceptionImplementor($status);

        $mapped = (new \ReflectionMethod(ApiExceptionHandler::class, 'mapApiException'))->invoke(null, $exception);

        self::assertInstanceOf(ApiException::class, $mapped);
        self::assertSame($expectedStatus, $mapped->getStatusCode());

        [$handler, $exceptions] = $this->exceptionHandler();

        self::assertTrue($handler->shouldReport($exception));

        ApiExceptionHandler::handles($exceptions);

        self::assertSame($expectedReported, $handler->shouldReport($exception));
    }

    /**
     * Provide a sample of every source type in the handler's exception map,
     * with the status the map resolves it to.
     *
     * @return iterable<string, array{class-string, \Closure(): \Throwable, int}>
     */
    public static function reportingSampleProvider(): iterable
    {
        yield 'not found http' => [
            NotFoundHttpException::class,
            static fn (): \Throwable => new NotFoundHttpException,
            404,
        ];

        yield 'backed enum case not found' => [
            BackedEnumCaseNotFoundException::class,
            static fn (): \Throwable => new BackedEnumCaseNotFoundException('App\Enums\Status', 'unknown'),
            404,
        ];

        yield 'model not found' => [
            ModelNotFoundException::class,
            static fn (): \Throwable => new ModelNotFoundException,
            404,
        ];

        yield 'suspicious operation' => [
            SuspiciousOperationException::class,
            static fn (): \Throwable => new SuspiciousOperationException,
            404,
        ];

        yield 'records not found' => [
            RecordsNotFoundException::class,
            static fn (): \Throwable => new RecordsNotFoundException,
            404,
        ];

        yield 'method not allowed' => [
            MethodNotAllowedHttpException::class,
            static fn (): \Throwable => new MethodNotAllowedHttpException(['GET']),
            405,
        ];

        yield 'bad request http' => [
            BadRequestHttpException::class,
            static fn (): \Throwable => new BadRequestHttpException,
            400,
        ];

        yield 'symfony request exception' => [
            RequestExceptionInterface::class,
            static fn (): \Throwable => new SymfonyBadRequestException,
            400,
        ];

        yield 'laravel unauthorized' => [
            LaravelUnauthorizedException::class,
            static fn (): \Throwable => new LaravelUnauthorizedException,
            403,
        ];

        yield 'authorization' => [
            AuthorizationException::class,
            static fn (): \Throwable => new AuthorizationException,
            403,
        ];

        yield 'access denied http' => [
            AccessDeniedHttpException::class,
            static fn (): \Throwable => new AccessDeniedHttpException,
            403,
        ];

        yield 'authentication' => [
            AuthenticationException::class,
            static fn (): \Throwable => new AuthenticationException,
            401,
        ];

        yield 'token mismatch' => [
            LaravelTokenMismatchException::class,
            static fn (): \Throwable => new LaravelTokenMismatchException,
            419,
        ];

        yield 'validation' => [
            ValidationException::class,
            static fn (): \Throwable => ValidationException::withMessages(['email' => 'invalid']),
            422,
        ];

        yield 'too many requests http' => [
            TooManyRequestsHttpException::class,
            static fn (): \Throwable => new TooManyRequestsHttpException,
            429,
        ];

        yield 'lock unavailable' => [
            LockUnavailableException::class,
            static fn (): \Throwable => new LockUnavailableException,
            429,
        ];

        yield 'service unavailable http' => [
            ServiceUnavailableHttpException::class,
            static fn (): \Throwable => new ServiceUnavailableHttpException,
            503,
        ];

        yield 'post too large' => [
            PostTooLargeException::class,
            static fn (): \Throwable => new PostTooLargeException,
            413,
        ];

        yield 'symfony http exception' => [
            SymfonyHttpExceptionInterface::class,
            static fn (): \Throwable => self::httpExceptionImplementor(409),
            409,
        ];
    }

    /**
     * Test that every exception the handler maps to a client error is not
     * reported once the toolkit handler is registered.
     *
     * @param  class-string  $source
     * @param  \Closure(): \Throwable  $factory
     * @param  int  $expectedStatus
     * @return void
     */
    #[DataProvider('reportingSampleProvider')]
    public function testMappedClientErrorsAreNotReported(string $source, \Closure $factory, int $expectedStatus): void
    {
        $sample = $factory();

        self::assertInstanceOf($source, $sample);

        $mapped = (new \ReflectionMethod(ApiExceptionHandler::class, 'mapApiException'))->invoke(null, $sample);

        self::assertInstanceOf(ApiException::class, $mapped);
        self::assertSame($expectedStatus, $mapped->getStatusCode());

        if ($expectedStatus >= 500) {
            return;
        }

        [$handler, $exceptions] = $this->exceptionHandler();

        ApiExceptionHandler::handles($exceptions);

        self::assertFalse($handler->shouldReport($sample));
    }

    /**
     * Define the test environment.
     *
     * Loads the package's exception translations so rendered error responses
     * include a non-empty detail rather than relying on the (now-fixed) raw-key
     * fallback.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        /** @var \Illuminate\Translation\Translator $translator */
        $translator = $app['translator'];

        $translator->addNamespace('api-toolkit', __DIR__ . '/../../../resources/lang');
    }

    /**
     * Resolve the application's real exception handler and its configuration
     * wrapper.
     *
     * @return array{\Illuminate\Foundation\Exceptions\Handler, \Illuminate\Foundation\Configuration\Exceptions}
     */
    private function exceptionHandler(): array
    {
        assert($this->app !== null);

        $handler = $this->app->make(ExceptionHandlerContract::class);

        assert($handler instanceof Handler);

        return [$handler, new Exceptions($handler)];
    }

    /**
     * Build an HTTP exception that implements the Symfony interface without
     * extending its base class, so Laravel's own don't-report list misses it.
     *
     * @param  int  $status
     * @return \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
     */
    private static function httpExceptionImplementor(int $status): SymfonyHttpExceptionInterface
    {
        return new class ($status) extends \RuntimeException implements SymfonyHttpExceptionInterface {
            /**
             * Create a new HTTP exception with the given status.
             *
             * @param  int  $status
             */
            public function __construct(

                /** The HTTP status code */
                private readonly int $status,
            ) {
                parent::__construct();
            }

            /**
             * Get the HTTP status code.
             *
             * @return int
             */
            #[\Override]
            public function getStatusCode(): int
            {
                return $this->status;
            }

            /**
             * Get the response headers.
             *
             * @return array<string, string>
             */
            #[\Override]
            public function getHeaders(): array
            {
                return [];
            }
        };
    }
}
