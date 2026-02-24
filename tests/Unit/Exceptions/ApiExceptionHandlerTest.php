<?php

namespace Tests\Unit\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\BadRequestException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
     * @return iterable<string, array{\Throwable, int}>
     */
    public static function exceptionMappingProvider(): iterable
    {
        yield 'NotFoundHttpException -> 404' => [
            new NotFoundHttpException,
            404,
        ];

        yield 'ModelNotFoundException -> 404' => [
            new ModelNotFoundException,
            404,
        ];

        yield 'AuthorizationException -> 403' => [
            new AuthorizationException,
            403,
        ];

        yield 'AuthenticationException -> 401' => [
            new AuthenticationException,
            401,
        ];

        yield 'TooManyRequestsHttpException -> 429' => [
            new TooManyRequestsHttpException,
            429,
        ];

        yield 'MethodNotAllowedHttpException -> 405' => [
            new MethodNotAllowedHttpException(['GET', 'POST']),
            405,
        ];

        yield 'Generic exception -> 500' => [
            new \RuntimeException(self::GENERIC_ERROR_MESSAGE),
            500,
        ];
    }

    /**
     * Test that render maps various Laravel exceptions to the correct HTTP
     * status.
     *
     * @param  \Throwable  $inputException
     * @param  int  $expectedHttpCode
     * @return void
     */
    #[DataProvider('exceptionMappingProvider')]
    public function testRenderMapsExceptionsCorrectly(\Throwable $inputException, int $expectedHttpCode): void
    {
        $request = Request::create(self::API_PATH, 'GET');
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, $inputException, $request);

        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame($expectedHttpCode, $response->getStatusCode());
    }

    /**
     * Test that JSON rendering includes the expected error structure.
     *
     * @return void
     */
    public function testJsonRenderingIncludesErrorStructure(): void
    {
        $request = Request::create(self::API_PATH, 'GET');
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
        $request = Request::create(self::API_PATH, 'GET');
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
        $request = Request::create('/test', 'GET');
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
        $request = Request::create(self::API_PATH, 'GET');
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
        $request = Request::create(self::API_PATH, 'GET');
        $request->headers->set('Accept', self::ACCEPT_JSON);

        config()->set('app.debug', false);

        $validator = new Validator(app('translator'), [], []);
        $exception = new ValidationException($validator);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, $exception, $request);

        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame(422, $response->getStatusCode());
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

        $exceptions = $this->createMock(Exceptions::class);
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
    }
}
