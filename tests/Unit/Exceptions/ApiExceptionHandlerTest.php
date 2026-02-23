<?php

namespace Tests\Unit\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * Test that render maps various Laravel exceptions to the correct HTTP status.
     *
     * @param  int  $expectedHttpCode
     * @return void
     */
    #[DataProvider('exceptionMappingProvider')]
    public function testRenderMapsExceptionsCorrectly(\Throwable $inputException, int $expectedHttpCode): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Accept', 'application/json');

        $this->app['config']->set('app.debug', false);

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
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Accept', 'application/json');

        $this->app['config']->set('app.debug', false);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, new NotFoundHttpException, $request);

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
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Accept', 'application/json');

        $this->app['config']->set('app.debug', true);

        $original = new \RuntimeException('Something went wrong');

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, $original, $request);

        $data = $response->getData(true);

        static::assertArrayHasKey('meta', $data['error']);
        static::assertArrayHasKey('exception', $data['error']['meta']);
        static::assertArrayHasKey('trace', $data['error']['meta']);
        static::assertSame('Something went wrong', $data['error']['meta']['message']);
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

        $this->app['config']->set('app.debug', true);

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
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Accept', 'application/json');

        $this->app['config']->set('app.debug', false);

        $exception = new BadRequestException(['field' => 'value']);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, $exception, $request);

        static::assertSame(400, $response->getStatusCode());

        $data = $response->getData(true);

        static::assertSame(10100, $data['error']['code']);
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
            new \RuntimeException('Something went wrong'),
            500,
        ];
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
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Accept', 'application/json');

        $this->app['config']->set('app.debug', false);

        $validator = new Validator($this->app['translator'], [], []);
        $exception = new ValidationException($validator);

        $reflection = new \ReflectionMethod(ApiExceptionHandler::class, 'render');
        $response   = $reflection->invoke(null, $exception, $request);

        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame(422, $response->getStatusCode());
    }
}
