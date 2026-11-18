<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Actors\EloquentActor;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\Events\ServiceCompleted;
use SineMacula\ApiToolkit\Services\Events\ServiceEvent;
use SineMacula\ApiToolkit\Services\Events\ServiceFailed;
use SineMacula\ApiToolkit\Services\Jobs\ServiceJob;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;
use Tests\Concerns\UsesDatabaseQueue;
use Tests\Fixtures\Actors\ActorUser;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Services\Input\Enums\StubStatusEnum;
use Tests\Fixtures\Services\Input\OrderPayload;
use Tests\Fixtures\Services\Jobs\PlaceOrderService;
use Tests\TestCase;

/**
 * Tests a service dispatched from an HTTP action through to a real worker.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Service::class)]
#[CoversClass(ServiceJob::class)]
final class ServiceHttpToWorkerJourneyTest extends TestCase
{
    use UsesDatabaseQueue;

    /** @var string The correlation id the request carries */
    private const string REQUEST_ID = 'order-request-id';

    /** @var string The date the order is due */
    private const string DUE = '2026-12-01T09:30:00+00:00';

    /** @var array<int, \SineMacula\ApiToolkit\Services\Events\ServiceEvent> The lifecycle events the worker fired */
    private array $events = [];

    /**
     * Route an action that queues the order for the acting user.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->useDatabaseQueue();

        Relation::morphMap(['actor_user' => ActorUser::class]);
        PlaceOrderService::reset();

        Event::listen([ServiceCompleted::class, ServiceFailed::class], function (ServiceEvent $event): void {
            $this->events[] = $event;
        });

        Route::post('/orders', static function (Request $request): JsonResponse {

            $user = $request->user();

            assert($user instanceof ActorUser);

            PlaceOrderService::make(OrderPayload::from(['due' => $request->date('due')] + $request->all()))
                ->withContext(ServiceContext::fromRequest($request, EloquentActor::for($user)))
                ->dispatch()
                ->onQueue('orders')
                ->delay(10);

            return new JsonResponse(null, 202);
        });
    }

    /**
     * Test that the queue and delay set on the returned dispatch apply.
     *
     * @return void
     */
    public function testDispatchOptionsApply(): void
    {
        $this->placeOrder(ActorUser::create(['name' => 'Buyer', 'email' => 'buyer@test.com']))->assertStatus(202);

        $job = DB::connection('queue_probe')->table('jobs')->sole();

        self::assertSame('orders', $job->queue);
        self::assertSame($job->created_at + 10, $job->available_at);

        $this->workUntilEmpty(queue: 'orders');

        self::assertNull(PlaceOrderService::$order);
    }

    /**
     * Test that the worker rebuilds the payload, keeps the request's context,
     * re-fetches the actor and releases the lock.
     *
     * @return void
     */
    public function testWorkerRunsTheOrderWithTheRequestsContext(): void
    {
        $user = ActorUser::create(['name' => 'Buyer', 'email' => 'buyer@test.com']);

        $this->placeOrder($user)->assertStatus(202);

        $user->update(['name' => 'Renamed Buyer']);

        $this->travel(11)->seconds();
        $this->workUntilEmpty(queue: 'orders');

        $order   = PlaceOrderService::$order;
        $context = PlaceOrderService::$context;

        self::assertNotNull($order);
        self::assertSame('ORD-1', $order->reference);
        self::assertSame(StubStatusEnum::ACTIVE, $order->status);
        self::assertSame(Platform::MOBILE_APP, $order->platform);
        self::assertTrue(CarbonImmutable::parse(self::DUE)->equalTo($order->due));

        self::assertNotNull($context);
        self::assertSame(ServiceSource::QUEUE, $context->source);
        self::assertSame(self::REQUEST_ID, $context->correlationId);
        self::assertSame('journey/1.0', $context->metadata[ServiceContext::METADATA_USER_AGENT]);
        self::assertSame('Buyer', $context->actor->actorLabel());
        self::assertSame('Renamed Buyer', PlaceOrderService::$actorName);

        self::assertCount(1, $this->events);
        self::assertInstanceOf(ServiceCompleted::class, $this->events[0]);
        self::assertSame(self::REQUEST_ID, $this->events[0]->correlationId);

        self::assertTrue(Cache::lock(PlaceOrderService::make($order)->getLockKey(), 10)->get());
        self::assertSame(0, DB::connection('queue_probe')->table('failed_jobs')->count());
    }

    /**
     * Test that a failed run on the worker reports the request's correlation id
     * and releases the lock.
     *
     * @return void
     */
    public function testFailedRunCarriesTheRequestsCorrelationId(): void
    {
        PlaceOrderService::$fails = true;

        $this->placeOrder(ActorUser::create(['name' => 'Buyer', 'email' => 'buyer@test.com']))->assertStatus(202);

        $this->travel(11)->seconds();
        $this->workUntilEmpty(tries: 1, queue: 'orders');

        $order = PlaceOrderService::$order;

        self::assertNotNull($order);
        self::assertCount(1, $this->events);
        self::assertInstanceOf(ServiceFailed::class, $this->events[0]);
        self::assertSame(self::REQUEST_ID, $this->events[0]->correlationId);
        self::assertTrue(Cache::lock(PlaceOrderService::make($order)->getLockKey(), 10)->get());
        self::assertSame(1, DB::connection('queue_probe')->table('failed_jobs')->count());
    }

    /**
     * Post an order as the given user with a request id.
     *
     * @param  \Tests\Fixtures\Actors\ActorUser  $user
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function placeOrder(ActorUser $user): TestResponse
    {
        return $this->actingAs($user)->postJson(
            '/orders',
            ['reference' => 'ORD-1', 'status' => 'active', 'platform' => 'mobile_app', 'due' => self::DUE],
            ['X-Request-Id' => self::REQUEST_ID, 'User-Agent' => 'journey/1.0'],
        );
    }
}
