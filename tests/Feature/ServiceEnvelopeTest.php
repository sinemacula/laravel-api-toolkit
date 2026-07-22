<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\ForbiddenException;
use SineMacula\ApiToolkit\Exceptions\InvalidInputException;
use SineMacula\ApiToolkit\Exceptions\TooManyRequestsException;
use SineMacula\ApiToolkit\Services\Actors\EloquentActor;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceResult;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Services\AuthorizingService;
use Tests\Fixtures\Services\InsertThenFailService;
use Tests\Fixtures\Services\LockableService;
use Tests\Fixtures\Services\ValidatingUserService;
use Tests\TestCase;

/**
 * Feature tests proving the service failure path renders the right envelope.
 *
 * Each route runs a service through run()->throw()->output() inside the real
 * request lifecycle, so a captured business failure is rethrown, mapped by the
 * toolkit exception handler, and rendered as the taxonomy JSON envelope. Covers
 * the four failure origins: input validation (422), lock contention (429),
 * authorization denial (403), and a handle() failure that rolls its write back
 * behind a 500.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Service::class)]
#[CoversClass(ServiceRunner::class)]
#[CoversClass(ServiceResult::class)]
#[CoversClass(ApiExceptionHandler::class)]
#[CoversClass(ForbiddenException::class)]
#[CoversClass(InvalidInputException::class)]
#[CoversClass(TooManyRequestsException::class)]
final class ServiceEnvelopeTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up the toolkit handler and the service-backed failure routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::post('/service-validate', static function (Request $request): array {

            $output = (new ValidatingUserService(new ArrayInput($request->all())))
                ->run()
                ->throw()
                ->output();

            return ['output' => $output];
        });

        Route::post('/service-lock', static function (): array {

            (new LockableService)->run()->throw()->output();

            return ['output' => true];
        });

        Route::post('/service-authorize', static function (Request $request): array {

            $service = new AuthorizingService;
            $user    = $request->user();

            if ($user instanceof User) {
                $service->by(EloquentActor::for($user));
            }

            $service->run()->throw()->output();

            return ['output' => true];
        });

        Route::post('/service-handle-fail', static function (): array {

            (new InsertThenFailService)->run()->throw();

            return ['output' => true];
        });
    }

    /**
     * Test that a service validation failure renders the 422 envelope.
     *
     * @return void
     */
    public function testValidationFailureRendersInvalidInputEnvelope(): void
    {
        $response = $this->postJson('/service-validate', ['age' => 30]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.status', 422);
        $response->assertJsonPath('error.code', 10106);

        $errors = $response->json('error.meta.city');

        self::assertIsArray($errors);
        self::assertNotEmpty($errors);
    }

    /**
     * Test that service lock contention renders the 429 envelope.
     *
     * @return void
     */
    public function testLockContentionRendersTooManyRequestsEnvelope(): void
    {
        $lock = Cache::lock((new LockableService)->getLockKey(), 60);

        self::assertTrue($lock->get());

        try {
            $response = $this->postJson('/service-lock');

            $response->assertStatus(429);
            $response->assertJsonPath('error.status', 429);
            $response->assertJsonPath('error.code', 10107);
        } finally {
            $lock->release();
        }
    }

    /**
     * Test that a service authorize() denial renders the 403 envelope.
     *
     * @return void
     */
    public function testAuthorizationDenialRendersForbiddenEnvelope(): void
    {
        $user = User::create(['name' => 'Acting', 'email' => 'acting@service.test']);

        $response = $this->actingAs($user)->postJson('/service-authorize');

        $response->assertStatus(403);
        $response->assertJsonPath('error.status', 403);
        $response->assertJsonPath('error.code', 10102);
    }

    /**
     * Test that a handle() failure rolls back and renders a 500 envelope.
     *
     * @return void
     */
    public function testHandleFailureRollsBackAndRendersErrorEnvelope(): void
    {
        $response = $this->postJson('/service-handle-fail');

        $response->assertStatus(500);
        $response->assertJsonPath('error.status', 500);

        $this->assertDatabaseCount('users', 0);
    }
}
