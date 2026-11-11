<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\Events\ServiceCompleted;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Jobs\ServiceJob;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Services\SimpleService;
use Tests\Fixtures\Services\StubActor;
use Tests\TestCase;

/**
 * Integration tests for running a service from an HTTP route and dispatch().
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Service::class)]
#[CoversClass(ServiceRunner::class)]
#[CoversClass(ServiceJob::class)]
#[CoversClass(ServiceContext::class)]
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

        Route::post('/service-user', static function (Request $request): UserResource {

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

            $user = $service->runOrFail();

            assert($user instanceof User);

            return UserResource::make($user);
        });

        Route::get('/service-context', static function (Request $request): array {

            $service = new class (new ArrayInput([])) extends Service {
                /**
                 * Return the client IP address read from the run context.
                 *
                 * @return array{ip: mixed}
                 */
                #[\Override]
                protected function handle(): array
                {
                    return ['ip' => $this->context()->metadata[ServiceContext::METADATA_IP]];
                }
            };

            $output = $service->withContext(ServiceContext::fromRequest($request, new StubActor))->runOrFail();

            assert(is_array($output));

            return $output;
        });
    }

    /**
     * Test that an HTTP action runs a service and returns its output.
     *
     * @return void
     */
    public function testHttpActionRunsServiceAndReturnsOutput(): void
    {
        $response = $this->postJson('/service-user', ['name' => 'Fabricated']);

        $response->assertStatus(201);
        $response->assertJsonPath('data._type', 'users');
        $response->assertJsonPath('data.name', 'Fabricated');
        $response->assertJsonPath('data.email', 'fabricated@service.test');

        $this->assertDatabaseHas('users', ['email' => 'fabricated@service.test']);
    }

    /**
     * Test that Service::dispatch() pushes a ServiceJob to the queue.
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

    /**
     * Test that a service reads the request-built context and correlation id.
     *
     * @return void
     */
    public function testHttpActionReadsTheContextBuiltFromTheRequest(): void
    {
        Event::fake([ServiceCompleted::class]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])
            ->getJson('/service-context', ['X-Request-Id' => 'edge-request-id']);

        $response->assertOk();
        $response->assertExactJson(['ip' => '198.51.100.4']);

        Event::assertDispatched(
            ServiceCompleted::class,
            static fn (ServiceCompleted $event): bool => $event->correlationId === 'edge-request-id'
                && $event->actor instanceof StubActor,
        );
    }
}
