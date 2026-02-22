<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use SineMacula\ApiToolkit\Exceptions\InvalidInputException;
use SineMacula\ApiToolkit\Exceptions\MaintenanceModeException;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\FormRequest;
use SineMacula\ApiToolkit\Http\Middleware\JsonPrettyPrint;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Middleware\PreventRequestsDuringMaintenance;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequests;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequestsWithRedis;
use SineMacula\ApiToolkit\Http\Middleware\Traits\ThrottleRequestsTrait;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class FormRequestAndMiddlewareTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testFormRequestThrowsInvalidInputExceptionOnFailedValidation(): void
    {
        $request = new class extends FormRequest {
            public function authorize(): bool
            {
                return true;
            }

            public function rules(): array
            {
                return [];
            }

            public function trigger(Validator $validator): void
            {
                $this->failedValidation($validator);
            }
        };

        $validator = validator([], ['name' => ['required']]);

        $this->expectException(InvalidInputException::class);

        try {
            $request->trigger($validator);
        } catch (InvalidInputException $exception) {
            static::assertArrayHasKey('name', $exception->getCustomMeta() ?? []);
            throw $exception;
        }
    }

    public function testJsonPrettyPrintMiddlewareFormatsResponseOnlyWhenRequested(): void
    {
        $middleware = new JsonPrettyPrint;
        $request    = Request::create('/api/users', 'GET', ['pretty' => '1']);

        $response = $middleware->handle($request, static fn () => new Response('{"ok":true}'));

        static::assertStringContainsString("\n", (string) $response->getContent());

        $plain = $middleware->handle(Request::create('/api/users', 'GET'), static fn () => new Response('{"ok":true}'));

        static::assertSame('{"ok":true}', $plain->getContent());
    }

    public function testParseApiQueryMiddlewareDelegatesToFacadeAndReturnsNextResponse(): void
    {
        $middleware = new ParseApiQuery;
        $request    = Request::create('/api/users', 'GET');

        ApiQuery::shouldReceive('parse')->once()->with($request);

        $response = $middleware->handle($request, static fn () => new Response('next'));

        static::assertSame('next', $response->getContent());
    }

    public function testMaintenanceMiddlewareConvertsHttpExceptionToCustomException(): void
    {
        config()->set('api-toolkit.maintenance_mode.except', ['health']);

        $maintenance = new class {
            public function active(): bool
            {
                return true;
            }

            public function data(): array
            {
                return [];
            }
        };

        $app = \Mockery::mock(Application::class);
        $app->shouldReceive('maintenanceMode')->andReturn($maintenance);

        $middleware = new PreventRequestsDuringMaintenance($app);

        $request = Request::create('/api/users', 'GET');

        $this->expectException(MaintenanceModeException::class);

        $middleware->handle($request, static fn () => new Response('ok'));
    }

    public function testMaintenanceMiddlewarePassesThroughWhenAppNotInMaintenance(): void
    {
        $maintenance = new class {
            public function active(): bool
            {
                return false;
            }

            public function data(): array
            {
                throw new HttpException(503);
            }
        };

        $app = \Mockery::mock(Application::class);
        $app->shouldReceive('maintenanceMode')->andReturn($maintenance);

        $middleware = new PreventRequestsDuringMaintenance($app);

        $response = $middleware->handle(Request::create('/api/users', 'GET'), static fn () => new Response('ok'));

        static::assertSame('ok', $response->getContent());
    }

    public function testThrottleRequestSignatureTraitRequiresRouteAndCanUseUserIdentifier(): void
    {
        $middleware = new class {
            use ThrottleRequestsTrait;

            public function signature(Request $request): string
            {
                return $this->resolveRequestSignature($request);
            }
        };

        $request = Request::create('/api/users', 'GET', server: ['SERVER_NAME' => 'example.test', 'REMOTE_ADDR' => '127.0.0.1']);

        $this->expectException(\RuntimeException::class);
        $middleware->signature($request);
    }

    public function testThrottleRequestSignatureHashesRequestData(): void
    {
        $middleware = new class {
            use ThrottleRequestsTrait;

            public function signature(Request $request): string
            {
                return $this->resolveRequestSignature($request);
            }
        };

        $request = Request::create('/api/users', 'GET', server: ['SERVER_NAME' => 'example.test', 'REMOTE_ADDR' => '127.0.0.1']);

        $route = new Route(['GET'], '/api/users', static fn () => null);
        $request->setRouteResolver(static fn () => $route);

        $request->setUserResolver(static fn () => new class {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        });

        $signature = $middleware->signature($request);

        static::assertSame(40, strlen($signature));
    }

    public function testThrottleMiddlewareClassesCanBeInstantiated(): void
    {
        static::assertInstanceOf(ThrottleRequests::class, app()->make(ThrottleRequests::class));
        static::assertInstanceOf(ThrottleRequestsWithRedis::class, app()->make(ThrottleRequestsWithRedis::class));
    }
}
