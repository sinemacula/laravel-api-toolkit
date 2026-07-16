<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Jobs\ServiceJob;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Services\SimpleService;
use Tests\Fixtures\Services\StubActor;
use Tests\TestCase;

/**
 * Integration tests for the service lifecycle driven over the wire.
 *
 * Proves the two edge invocations of a service: an HTTP route action runs a
 * service inside the real request lifecycle and returns the created subject
 * through the toolkit resource response, and Service::dispatch() pushes a
 * ServiceJob carrying the service class and the explicit actor context onto a
 * faked queue. The queue re-hydration path itself is covered elsewhere; this
 * suite proves only the HTTP and dispatch entry points.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Service::class)]
#[CoversClass(ServiceRunner::class)]
#[CoversClass(ServiceJob::class)]
final class ServiceHttpAndDispatchTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with the toolkit handler and a service-backed route.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::post('/api/service-user', static function (Request $request): UserResource {

            $service = new class (new ArrayInput($request->all())) extends Service {
                /**
                 * Create a user from the input and return it as the output.
                 *
                 * @return \Tests\Fixtures\Models\User
                 */
                #[\Override]
                protected function handle(): mixed
                {
                    $data = $this->input->toArray();
                    $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : 'anonymous';

                    return User::create([
                        'name'  => $name,
                        'email' => strtolower($name) . '@service.test',
                    ]);
                }
            };

            $user = $service->run()->throw()->output();

            assert($user instanceof User);

            return UserResource::make($user);
        });
    }

    /**
     * Test that an HTTP action runs a service and returns its output.
     *
     * The route action runs the full service lifecycle in-process, so the
     * created user is reflected in the toolkit resource body (including the
     * service-derived email) and as a committed row in the database.
     *
     * @return void
     */
    public function testHttpActionRunsServiceAndReturnsOutput(): void
    {
        $response = $this->postJson('/api/service-user', ['name' => 'Fabricated']);

        $response->assertStatus(201);
        $response->assertJsonPath('data._type', 'users');
        $response->assertJsonPath('data.name', 'Fabricated');
        $response->assertJsonPath('data.email', 'fabricated@service.test');

        $this->assertDatabaseHas('users', ['email' => 'fabricated@service.test']);
    }

    /**
     * Test that Service::dispatch() pushes a ServiceJob to the queue.
     *
     * The real dispatch path is exercised under a faked queue: the pushed job
     * carries the concrete service class-string and the explicit actor context,
     * with the source defaulting to INTERNAL for a synchronous dispatch.
     *
     * @return void
     */
    public function testDispatchPushesServiceJobWithActorContext(): void
    {
        Queue::fake();

        (new SimpleService)->by(new StubActor)->dispatch();

        Queue::assertPushed(ServiceJob::class, static fn (ServiceJob $job): bool => is_a($job->service, SimpleService::class, true)
                && $job->context->actor instanceof StubActor
                && $job->context->actor->actorIdentifier() === 'stub-id'
                && $job->context->source                   === ServiceSource::INTERNAL);
    }
}
