<?php

namespace Tests\Unit\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Session\TokenMismatchException as LaravelTokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Validation\UnauthorizedException as LaravelUnauthorizedException;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\BadRequestException;
use SineMacula\ApiToolkit\Exceptions\TokenMismatchException;
use SineMacula\Http\Enums\HttpMethod;
use Symfony\Component\HttpFoundation\Exception\BadRequestException as SymfonyBadRequestException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException as SymfonyHttpException;
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
class ApiExceptionHandlerTest extends TestCase
{
    /** @var string Test API endpoint path. */
    private const string API_PATH = '/api/test';

    /** @var string JSON content type header value. */
    private const string ACCEPT_JSON = 'application/json';

    /** @var string Generic error message used in test fixtures. */
    private const string GENERIC_ERROR_MESSAGE = 'Something went wrong';

    /**
     * Test that handles registers report and render callbacks.
     *
     * @return void
     */
    public function testHandlesRegistersReportAndRenderCallbacks(): void
    {
        $exceptions = $this->createMock(Exceptions::class);

        $reportable = new class {
            /**
             * @return $this
             */
            public function stop(): static
            {
                return $this;
            }
        };

        $exceptions->expects(static::once())
            ->method('report')
            ->willReturn($reportable);

        $exceptions->expects(static::once())
            ->method('render');

        ApiExceptionHandler::handles($exceptions);
    }

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
    public function testRenderMapsExceptionsCorrectly(
        \Throwable $inputException,
        int $expectedHttpCode,
        int $expectedErrorCode
    ): void {
        $request = Request::create(self::API_PATH, HttpMethod::GET->getVerb());
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, $inputException, $request);

        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame($expectedHttpCode, $response->getStatusCode());

        $data = $response->getData(true);

        static::assertIsArray($data);
        static::assertSame($expectedHttpCode, $data['error']['status']);
        static::assertSame($expectedErrorCode, $data['error']['code']);
    }

    /**
     * Test that the generic catch-all preserves the original status code,
     * derives the title from the HTTP status, and uses the generic error
     * code.
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

        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame(409, $response->getStatusCode());

        $data = $response->getData(true);

        static::assertIsArray($data);
        static::assertSame(409, $data['error']['status']);
        static::assertSame(10113, $data['error']['code']);
        static::assertSame('Conflict', $data['error']['title']);
    }

    /**
     * Test that the generic catch-all preserves headers from the original
     * HTTP exception.
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

        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame('120', $response->headers->get('Retry-After'));
    }

    /**
     * Test that application-layer database exceptions are not mapped to
     * HTTP semantics and remain 500.
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

        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame(500, $response->getStatusCode());
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

        static::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        static::assertArrayHasKey('error', $data);
        static::assertArrayHasKey('status', $data['error']);
        static::assertArrayHasKey('code', $data['error']);
        static::assertArrayHasKey('title', $data['error']);
        static::assertArrayHasKey('detail', $data['error']);
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

        static::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        static::assertArrayHasKey('meta', $data['error']);
        static::assertArrayHasKey('exception', $data['error']['meta']);
        static::assertArrayHasKey('trace', $data['error']['meta']);
        static::assertSame(self::GENERIC_ERROR_MESSAGE, $data['error']['meta']['message']);
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

        static::assertNull($response);
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

        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame(400, $response->getStatusCode());

        $data = $response->getData(true);

        static::assertSame(10100, $data['error']['code']);
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

        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame(422, $response->getStatusCode());

        $data = $response->getData(true);

        static::assertIsArray($data);
        static::assertSame(10106, $data['error']['code']);
    }

    /**
     * Test that a Laravel session token mismatch maps to the toolkit token
     * mismatch exception with the non-standard 419 status code.
     *
     * The mapping is asserted directly because rendering the toolkit
     * TokenMismatchException requires the package translations, which are
     * not registered in this isolated test application.
     *
     * @return void
     */
    public function testTokenMismatchExceptionMapsToToolkitTokenMismatch(): void
    {
        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'mapApiException');
        $mapped     = $reflection->invoke(null, new LaravelTokenMismatchException('CSRF token mismatch.'));

        static::assertInstanceOf(TokenMismatchException::class, $mapped);
        static::assertSame(419, $mapped->getStatusCode());
        static::assertSame(10105, $mapped::getInternalErrorCode());
    }

    /**
     * Test that the json_when_expected strategy returns null for requests
     * that do not expect JSON.
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

        static::assertNull($response);
    }

    /**
     * Test that the json_when_expected strategy renders JSON when the
     * request expects JSON.
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

        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame(500, $response->getStatusCode());
    }

    /**
     * Test that the auto strategy renders JSON for non-JSON requests when
     * debug mode is disabled.
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

        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame(500, $response->getStatusCode());
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

        static::assertInstanceOf(JsonResponse::class, $response);

        $content = $response->getContent();

        static::assertIsString($content);
        static::assertStringContainsString("\n    ", $content);
    }

    /**
     * Test that JSON output is compact when the pretty request parameter is
     * not provided.
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

        static::assertInstanceOf(JsonResponse::class, $response);

        $content = $response->getContent();

        static::assertIsString($content);
        static::assertStringNotContainsString("\n", $content);
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

        static::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        static::assertIsArray($data);
        static::assertArrayNotHasKey('meta', $data['error']);
    }

    /**
     * Test that the include_debug_info config takes precedence over a
     * disabled debug mode.
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

        static::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        static::assertIsArray($data);
        static::assertArrayHasKey('meta', $data['error']);
        static::assertSame(self::GENERIC_ERROR_MESSAGE, $data['error']['meta']['message']);
        static::assertArrayHasKey('trace', $data['error']['meta']);
    }

    /**
     * Test that the include_debug_info config takes precedence over an
     * enabled debug mode.
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

        static::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        static::assertIsArray($data);
        static::assertArrayNotHasKey('meta', $data['error']);
    }

    /**
     * Test that custom meta is returned untouched when debug mode is
     * enabled but the exception has no previous exception.
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

        static::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        static::assertIsArray($data);
        static::assertSame(['custom' => 'value'], $data['error']['meta']);
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

        static::assertInstanceOf(JsonResponse::class, $response);

        $data = $response->getData(true);

        static::assertIsArray($data);

        $meta = $data['error']['meta'];

        static::assertSame('value', $meta['custom']);
        static::assertSame(self::GENERIC_ERROR_MESSAGE, $meta['message']);
        static::assertSame(\RuntimeException::class, $meta['exception']);
        static::assertSame($previous->getFile(), $meta['file']);
        static::assertSame($previous->getLine(), $meta['line']);
        static::assertArrayHasKey('trace', $meta);
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

            $frames_with_args = array_filter(
                $previous->getTrace(),
                static fn (array $frame): bool => array_key_exists('args', $frame),
            );

            static::assertNotEmpty($frames_with_args);

            $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
            $response   = $reflection->invoke(null, $previous, $request);

            static::assertInstanceOf(JsonResponse::class, $response);

            $data = $response->getData(true);

            static::assertIsArray($data);
            static::assertIsArray($data['error']['meta']['trace']);
            static::assertNotEmpty($data['error']['meta']['trace']);

            foreach ($data['error']['meta']['trace'] as $frame) {
                static::assertArrayNotHasKey('args', $frame);
            }
        } finally {
            if ($original !== false) {
                ini_set('zend.exception_ignore_args', $original);
            }
        }
    }

    /**
     * Test that the report callback registered via handles() invokes
     * logApiException when an ApiException is reported.
     *
     * @return void
     */
    public function testHandlesReportCallbackInvokesLogApiException(): void
    {
        $mock_channel = \Mockery::mock(LoggerInterface::class);
        $mock_channel->shouldReceive('error')->once()->withAnyArgs();

        Log::shouldReceive('channel')
            ->with('api-exceptions')
            ->once()
            ->andReturn($mock_channel);

        $reportable = new class {
            /**
             * @return $this
             */
            public function stop(): static
            {
                return $this;
            }
        };

        $captured_callback = null;

        $exceptions = static::createStub(Exceptions::class);
        $exceptions->method('report')
            ->willReturnCallback(function ($callback) use (&$captured_callback, $reportable) {
                $captured_callback = $callback;

                return $reportable;
            });
        $exceptions->method('render');

        ApiExceptionHandler::handles($exceptions);

        static::assertNotNull($captured_callback);

        $captured_callback(new BadRequestException);
    }

    /**
     * Test that logApiException logs to the api-exceptions channel.
     *
     * @return void
     */
    public function testLogApiExceptionLogsToApiExceptionsChannel(): void
    {
        $mock_channel = \Mockery::mock(LoggerInterface::class);
        $mock_channel->shouldReceive('error')->once()->withAnyArgs();

        Log::shouldReceive('channel')
            ->with('api-exceptions')
            ->once()
            ->andReturn($mock_channel);

        $exception  = new BadRequestException;
        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'logApiException');

        $reflection->invoke(null, $exception);
    }

    /**
     * Test that logApiException also logs to cloudwatch when enabled.
     *
     * @return void
     */
    public function testLogApiExceptionAlsoLogsToCloudWatchWhenEnabled(): void
    {
        config()->set('api-toolkit.logging.cloudwatch.enabled', true);

        $mock_api_channel = \Mockery::mock(LoggerInterface::class);
        $mock_api_channel->shouldReceive('error')->once()->withAnyArgs();

        $mock_cw_channel = \Mockery::mock(LoggerInterface::class);
        $mock_cw_channel->shouldReceive('error')->once()->withAnyArgs();

        Log::shouldReceive('channel')
            ->with('api-exceptions')
            ->once()
            ->andReturn($mock_api_channel);

        Log::shouldReceive('channel')
            ->with('cloudwatch-api-exceptions')
            ->once()
            ->andReturn($mock_cw_channel);

        $exception  = new BadRequestException;
        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'logApiException');

        $reflection->invoke(null, $exception);
    }

    /**
     * Test that convertExceptionToString returns a formatted string.
     *
     * @return void
     */
    public function testConvertExceptionToStringReturnsFormattedString(): void
    {
        $exception  = new \RuntimeException(self::GENERIC_ERROR_MESSAGE, 42);
        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'convertExceptionToString');

        $result = $reflection->invoke(null, $exception);

        static::assertStringContainsString('[42]', $result);
        static::assertStringContainsString(self::GENERIC_ERROR_MESSAGE, $result);
        static::assertStringContainsString('on line', $result);
        static::assertStringContainsString('of file', $result);
    }

    /**
     * Test that getContext returns request method, path, and data.
     *
     * @return void
     */
    public function testGetContextReturnsRequestContext(): void
    {
        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'getContext');

        $result = $reflection->invoke(null);

        static::assertIsArray($result);
        static::assertArrayHasKey('method', $result);
        static::assertArrayHasKey('path', $result);
    }

    /**
     * Test that getContext includes the authenticated user id and filters
     * out empty values.
     *
     * @return void
     */
    public function testGetContextIncludesUserIdAndFiltersEmptyValues(): void
    {
        Auth::shouldReceive('id')->andReturn(42);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'getContext');

        $result = $reflection->invoke(null);

        static::assertIsArray($result);
        static::assertSame(42, $result['user_id']);
        static::assertArrayNotHasKey('data', $result);
    }

    /**
     * Test that getContext falls back gracefully when Auth::id() throws.
     *
     * @return void
     */
    public function testGetContextFallsBackWhenAuthThrows(): void
    {
        Auth::shouldReceive('id')->andThrow(new \RuntimeException('No auth guard'));

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'getContext');

        $result = $reflection->invoke(null);

        static::assertIsArray($result);
        static::assertArrayHasKey('method', $result);
        static::assertSame(['method', 'path', 'data'], array_keys($result));
    }

    /**
     * Test that getContext redacts sensitive request keys - matched as
     * case-insensitive substrings, recursively through nested arrays - so
     * credentials never reach the exception log.
     *
     * @return void
     */
    public function testGetContextRedactsSensitiveRequestKeys(): void
    {
        $request = Request::create(self::API_PATH, 'POST', [
            'email'     => 'alice@example.com',
            'password'  => 'super-secret',
            'API_TOKEN' => 'tok_live_123',
            'nested'    => ['client_secret' => 'shh', 'keep' => 'visible'],
        ]);

        assert($this->app !== null);

        $this->app->instance('request', $request);
        RequestFacade::clearResolvedInstance('request');

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'getContext');

        $result = $reflection->invoke(null);

        static::assertIsArray($result);

        $data = $result['data'];

        static::assertIsArray($data);
        static::assertSame('alice@example.com', $data['email']);
        static::assertSame('[redacted]', $data['password']);
        static::assertSame('[redacted]', $data['API_TOKEN']);

        $nested = $data['nested'];

        static::assertIsArray($nested);
        static::assertSame('[redacted]', $nested['client_secret']);
        static::assertSame('visible', $nested['keep']);
    }

    /**
     * Test that configured sensitive keys are matched case-insensitively, so an
     * upper-case denylist entry still redacts a lower-case request key.
     *
     * @return void
     */
    public function testGetContextRedactsCaseInsensitiveConfiguredKeys(): void
    {
        config()->set('api-toolkit.exceptions.sensitive_keys', ['SECRET']);

        $data = $this->contextDataForRequest(['my_secret' => 'shh', 'email' => 'a@b.com']);

        static::assertSame('[redacted]', $data['my_secret']);
        static::assertSame('a@b.com', $data['email']);
    }

    /**
     * Test that a non-array sensitive-keys config falls back to the default
     * denylist rather than disabling redaction.
     *
     * @return void
     */
    public function testGetContextFallsBackToDefaultSensitiveKeysForNonArrayConfig(): void
    {
        config()->set('api-toolkit.exceptions.sensitive_keys', 'password');

        $data = $this->contextDataForRequest(['password' => 'super-secret', 'email' => 'a@b.com']);

        static::assertSame('[redacted]', $data['password']);
        static::assertSame('a@b.com', $data['email']);
    }

    /**
     * Test that an empty configured key is ignored rather than matching - and
     * therefore redacting - every request key.
     *
     * @return void
     */
    public function testGetContextIgnoresEmptyConfiguredSensitiveKeys(): void
    {
        config()->set('api-toolkit.exceptions.sensitive_keys', ['', 'password']);

        $data = $this->contextDataForRequest(['password' => 'super-secret', 'email' => 'a@b.com']);

        static::assertSame('[redacted]', $data['password']);
        static::assertSame('a@b.com', $data['email']);
    }

    /**
     * Define the test environment.
     *
     * Loads the package's exception translations so rendered error responses
     * include a non-empty detail rather than relying on the (now-fixed)
     * raw-key fallback.
     *
     * @param  mixed  $app
     * @return void
     */
    protected function defineEnvironment(mixed $app): void
    {
        /** @var \Illuminate\Translation\Translator $translator */
        $translator = $app['translator'];

        $translator->addNamespace('api-toolkit', __DIR__ . '/../../../resources/lang');
    }

    /**
     * Resolve getContext()'s redacted request data for a POST body.
     *
     * @param  array<string, mixed>  $body
     * @return array<array-key, mixed>
     */
    private function contextDataForRequest(array $body): array
    {
        $request = Request::create(self::API_PATH, 'POST', $body);

        assert($this->app !== null);

        $this->app->instance('request', $request);
        RequestFacade::clearResolvedInstance('request');

        $result = (new \ReflectionMethod(ApiExceptionHandler::class, 'getContext'))->invoke(null);

        static::assertIsArray($result);

        $data = $result['data'];

        static::assertIsArray($data);

        return $data;
    }
}
