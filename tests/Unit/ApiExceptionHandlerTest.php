<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Session\TokenMismatchException as LaravelTokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\UnauthorizedException as LaravelUnauthorizedException;
use Illuminate\Validation\ValidationException;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use SineMacula\ApiToolkit\Enums\HttpStatus;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\BadRequestException;
use SineMacula\ApiToolkit\Exceptions\ForbiddenException;
use SineMacula\ApiToolkit\Exceptions\InvalidInputException;
use SineMacula\ApiToolkit\Exceptions\NotAllowedException;
use SineMacula\ApiToolkit\Exceptions\NotFoundException;
use SineMacula\ApiToolkit\Exceptions\TokenMismatchException;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;
use SineMacula\ApiToolkit\Exceptions\UnauthenticatedException;
use SineMacula\ApiToolkit\Exceptions\UnhandledException;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ApiExceptionHandler::class)]
class ApiExceptionHandlerTest extends TestCase
{
    use InteractsWithNonPublicMembers;
    use MockeryPHPUnitIntegration;

    public function testHandlesRegistersReportAndRenderCallbacks(): void
    {
        $reportCallback = null;

        $reportChain = new class {
            public function stop(): self
            {
                return $this;
            }
        };

        $exceptions = \Mockery::mock(Exceptions::class);
        $exceptions->shouldReceive('report')->once()->with(\Mockery::on(function ($callback) use (&$reportCallback): bool {
            $reportCallback = $callback;

            return is_callable($callback);
        }))->andReturn($reportChain);
        $exceptions->shouldReceive('render')->once()->with(\Mockery::type('callable'));

        Log::shouldReceive('channel')->with('api-exceptions')->andReturnSelf();
        Log::shouldReceive('error')->once();

        ApiExceptionHandler::handles($exceptions);

        static::assertIsCallable($reportCallback);
        $reportCallback(new BadRequestException);
    }

    public function testRenderReturnsNullForNonJsonRequestsInDebugMode(): void
    {
        config()->set('app.debug', true);

        $request  = Request::create('/api/users', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']);
        $response = $this->invokeNonPublic(ApiExceptionHandler::class, 'render', new \RuntimeException('boom'), $request);

        static::assertNull($response);
    }

    public function testRenderReturnsStructuredJsonForMappedApiExceptions(): void
    {
        config()->set('app.debug', false);

        $request  = Request::create('/api/users', 'GET', ['pretty' => '1'], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = $this->invokeNonPublic(ApiExceptionHandler::class, 'render', new NotFoundHttpException('missing'), $request);

        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame(404, $response->getStatusCode());
        static::assertArrayHasKey('error', $response->getData(true));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('frameworkExceptionMappingProvider')]
    public function testMapApiExceptionHandlesKnownFrameworkExceptions(callable $throwableFactory, string $expectedClass): void
    {
        $mapped = $this->invokeNonPublic(ApiExceptionHandler::class, 'mapApiException', $throwableFactory());

        static::assertInstanceOf($expectedClass, $mapped);
    }

    public function testConvertApiExceptionToArrayIncludesMetaAndCanExposeDebugPreviousContext(): void
    {
        config()->set('app.debug', true);

        $exception = new BadRequestException(meta: ['foo' => 'bar'], previous: new \RuntimeException('inner'));

        $array = $this->invokeNonPublic(ApiExceptionHandler::class, 'convertApiExceptionToArray', $exception);

        static::assertSame(400, $array['error']['status']);
        static::assertSame(10100, $array['error']['code']);
        static::assertSame('bar', $array['error']['meta']['foo']);
        static::assertSame('inner', $array['error']['meta']['message']);

        config()->set('app.debug', false);

        $meta = $this->invokeNonPublic(ApiExceptionHandler::class, 'getApiExceptionMeta', new BadRequestException(meta: ['x' => 1], previous: new \RuntimeException('ignore')));

        static::assertSame(['x' => 1], $meta);
    }

    public function testConvertExceptionToStringAndContextGeneration(): void
    {
        $request = Request::create('/api/users', 'GET', ['q' => 'a']);
        $this->app->instance('request', $request);

        Auth::shouldReceive('id')->once()->andReturn(123);

        $context = $this->invokeNonPublic(ApiExceptionHandler::class, 'getContext');

        static::assertSame('GET', $context['method']);
        static::assertSame(123, $context['user_id']);

        Auth::shouldReceive('id')->once()->andThrow(new \RuntimeException('auth failed'));

        $fallbackContext = $this->invokeNonPublic(ApiExceptionHandler::class, 'getContext');

        static::assertArrayNotHasKey('user_id', $fallbackContext);

        $string = $this->invokeNonPublic(ApiExceptionHandler::class, 'convertExceptionToString', new \RuntimeException('boom', 123));

        static::assertStringContainsString('[123]', $string);
        static::assertStringContainsString('"boom"', $string);
    }

    public function testLogApiExceptionWritesToConfiguredChannels(): void
    {
        $exception = new BadRequestException;

        config()->set('api-toolkit.logging.cloudwatch.enabled', false);

        Log::shouldReceive('channel')->with('api-exceptions')->andReturnSelf();
        Log::shouldReceive('error')->once();

        $this->invokeNonPublic(ApiExceptionHandler::class, 'logApiException', $exception);

        config()->set('api-toolkit.logging.cloudwatch.enabled', true);

        Log::shouldReceive('channel')->with('api-exceptions')->andReturnSelf();
        Log::shouldReceive('channel')->with('cloudwatch-api-exceptions')->andReturnSelf();
        Log::shouldReceive('error')->twice();

        $this->invokeNonPublic(ApiExceptionHandler::class, 'logApiException', $exception);
    }

    /**
     * @return iterable<string, array{0: callable(): \Throwable, 1: class-string<ApiException>}>
     */
    public static function frameworkExceptionMappingProvider(): iterable
    {
        yield 'not found http exception' => [
            static fn (): \Throwable => new NotFoundHttpException,
            NotFoundException::class,
        ];

        yield 'backed enum case not found exception' => [
            static fn (): \Throwable => new BackedEnumCaseNotFoundException(HttpStatus::class, 'bad'),
            NotFoundException::class,
        ];

        yield 'model not found exception' => [
            static fn (): \Throwable => new ModelNotFoundException,
            NotFoundException::class,
        ];

        yield 'suspicious operation exception' => [
            static fn (): \Throwable => new SuspiciousOperationException,
            NotFoundException::class,
        ];

        yield 'method not allowed http exception' => [
            static fn (): \Throwable => new MethodNotAllowedHttpException([]),
            NotAllowedException::class,
        ];

        yield 'request exception interface implementation' => [
            static fn (): \Throwable => new class extends \RuntimeException implements RequestExceptionInterface {},
            BadRequestException::class,
        ];

        yield 'laravel unauthorized exception' => [
            static fn (): \Throwable => new LaravelUnauthorizedException,
            ForbiddenException::class,
        ];

        yield 'authorization exception' => [
            static fn (): \Throwable => new AuthorizationException,
            ForbiddenException::class,
        ];

        yield 'access denied http exception' => [
            static fn (): \Throwable => new AccessDeniedHttpException,
            ForbiddenException::class,
        ];

        yield 'authentication exception' => [
            static fn (): \Throwable => new AuthenticationException,
            UnauthenticatedException::class,
        ];

        yield 'laravel token mismatch exception' => [
            static fn (): \Throwable => new LaravelTokenMismatchException,
            TokenMismatchException::class,
        ];

        yield 'validation exception' => [
            static fn (): \Throwable => new ValidationException(validator([], ['name' => ['required']])),
            InvalidInputException::class,
        ];

        yield 'too many requests http exception' => [
            static fn (): \Throwable => new TooManyRequestsHttpException,
            TooManyRequestsException::class,
        ];

        yield 'fallback runtime exception' => [
            static fn (): \Throwable => new \RuntimeException('fallback'),
            UnhandledException::class,
        ];
    }
}
