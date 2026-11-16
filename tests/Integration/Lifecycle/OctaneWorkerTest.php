<?php

declare(strict_types = 1);

namespace Tests\Integration\Lifecycle;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataGeneration;
use SineMacula\ApiToolkit\Events\CacheFlushed;
use SineMacula\ApiToolkit\Events\WritePoolFlushFailed;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Listeners\OctaneFlushListener;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use Tests\Concerns\RunsOctaneWorker;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\DeferrableUserRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * End-to-end tests of the toolkit lifecycle through a real Octane worker, where
 * every request, task and tick runs in its own sandboxed application.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheManager::class)]
#[CoversClass(OctaneFlushListener::class)]
#[CoversClass(WritePoolFlushSubscriber::class)]
final class OctaneWorkerTest extends TestCase
{
    use RunsOctaneWorker;

    /** @var array<int, \SineMacula\ApiToolkit\Repositories\Concerns\WritePool> The pool each deferring request wrote to */
    private array $requestPools = [];

    /**
     * Register the routes the worker serves.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/octane/users', function (Request $request, DeferrableUserRepository $users): Response {

            $users->defer(['name' => 'Octane', 'email' => $request->string('email')->toString()]);

            $this->requestPools[] = app(WritePool::class);

            return response()->noContent();
        });

        Route::middleware(ParseApiQuery::class)->get('/octane/query', static fn (): JsonResponse => new JsonResponse([
            'fields' => ApiQuery::getFields('users'),
            'limit'  => ApiQuery::getLimit(),
        ]));

        Route::middleware(ParseApiQuery::class)->get('/octane/users', static function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(UserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, UserResource::class);
        });

        Route::get('/octane/operators', static fn (OperatorRegistry $registry): JsonResponse => new JsonResponse([
            'registry' => spl_object_id($registry),
            'starts'   => $registry->has('$starts'),
        ]));
    }

    /**
     * Test that a request's deferred row is persisted once its response is
     * sent, leaving nothing buffered.
     *
     * @return void
     */
    public function testDeferredRowPersistsWhenTheRequestIsHandled(): void
    {
        $client = $this->serveThroughOctane(Request::create('/octane/users', 'POST', ['email' => 'alice@octane.test']));

        self::assertSame([], $client->errors);
        self::assertSame(204, $client->responses[0]->getStatusCode());
        self::assertSame(['alice@octane.test'], DB::table('users')->pluck('email')->all());
        self::assertCount(1, $this->requestPools);
        self::assertTrue($this->requestPools[0]->isEmpty());
    }

    /**
     * Test that consecutive requests persist their own rows once each.
     *
     * @return void
     */
    public function testConsecutiveRequestsPersistTheirOwnRowsOnce(): void
    {
        $client = $this->serveThroughOctane(
            Request::create('/octane/users', 'POST', ['email' => 'alice@octane.test']),
            Request::create('/octane/users', 'POST', ['email' => 'bob@octane.test']),
        );

        self::assertSame([], $client->errors);
        self::assertSame(['alice@octane.test', 'bob@octane.test'], DB::table('users')->orderBy('email')->pluck('email')->all());
        self::assertNotSame($this->requestPools[0], $this->requestPools[1]);
    }

    /**
     * Test that a request's failed flush under collect is reported and
     * dispatches WritePoolFlushFailed.
     *
     * @return void
     */
    public function testFailedFlushOfARequestDispatchesTheFailureEvent(): void
    {
        Config::set('api-toolkit.deferred_writes.on_failure', 'collect');

        User::create(['name' => 'Taken', 'email' => 'taken@octane.test']);

        Exceptions::fake();

        $failures = [];

        Event::listen(WritePoolFlushFailed::class, static function (WritePoolFlushFailed $event) use (&$failures): void {
            $failures[] = $event->flushResult->failureCount();
        });

        $client = $this->serveThroughOctane(Request::create('/octane/users', 'POST', ['email' => 'taken@octane.test']));

        self::assertSame([], $client->errors);
        self::assertSame([1], $failures);
        Exceptions::assertReported(WritePoolFlushException::class);
        self::assertSame(1, DB::table('users')->count());
    }

    /**
     * Test that one request's parsed query is not visible to the next.
     *
     * @return void
     */
    public function testParsedQueryDoesNotLeakIntoTheNextRequest(): void
    {
        $client = $this->serveThroughOctane(
            Request::create('/octane/query?fields[users]=name&limit=5', 'GET'),
            Request::create('/octane/query', 'GET'),
        );

        self::assertSame([], $client->errors);
        self::assertSame('{"fields":["name"],"limit":5}', $client->responses[0]->getContent());
        self::assertSame('{"fields":null,"limit":null}', $client->responses[1]->getContent());
    }

    /**
     * Test that the toolkit state is flushed once per request, task and tick.
     *
     * @return void
     */
    public function testStateIsFlushedOncePerRequestTaskAndTick(): void
    {
        $flushes = new \ArrayObject;

        Event::listen(CacheFlushed::class, static function (CacheFlushed $event) use ($flushes): void {
            $flushes->append($event);
        });

        $this->serveThroughOctane(Request::create('/octane/query', 'GET'), Request::create('/octane/query', 'GET'));

        self::assertCount(2, $flushes);

        $this->runOctaneTasks(static fn (): int => 1, static fn (): int => 2);

        self::assertCount(4, $flushes);

        $this->runOctaneTicks(3);

        self::assertCount(7, $flushes);
    }

    /**
     * Test that a warmed worker serves the schema metadata without catalogue
     * queries until the generation is advanced elsewhere.
     *
     * @return void
     */
    public function testWarmedWorkerSkipsCatalogueQueriesUntilTheGenerationAdvances(): void
    {
        // Octane flushes the array store before every operation
        Config::set('cache.default', 'file');

        User::create(['name' => 'Alice', 'email' => 'alice@octane.test']);

        $catalogueQueries = new \ArrayObject;

        DB::listen(static function (QueryExecuted $query) use ($catalogueQueries): void {
            if (preg_match('/pragma|sqlite_master|sqlite_schema/i', $query->sql) !== 1) {
                return;
            }

            $catalogueQueries->append($query->sql);
        });

        $catalogueQueriesToList = function () use ($catalogueQueries): int {
            $catalogueQueries->exchangeArray([]);

            self::assertSame(200, $this->serveThroughOctane(Request::create('/octane/users', 'GET'))->responses[0]->getStatusCode());

            return count($catalogueQueries);
        };

        self::assertGreaterThan(0, $catalogueQueriesToList());
        self::assertSame(0, $catalogueQueriesToList());

        (new MetadataGeneration)->advance();

        self::assertGreaterThan(0, $catalogueQueriesToList());
    }

    /**
     * Test that an operator a provider registers on the base application is
     * resolved by every request.
     *
     * @return void
     */
    public function testOperatorRegisteredByAProviderResolvesInEveryRequest(): void
    {
        $app = $this->app;

        assert($app !== null);

        $app->register(new class ($app) extends ServiceProvider {
            /**
             * Register a custom filter operator.
             *
             * @return void
             */
            public function boot(): void
            {
                $registry = $this->app->make(OperatorRegistry::class);
                $equal    = $registry->resolve('$eq');

                assert($equal !== null);

                $registry->register('$starts', $equal);
            }
        });

        $client = $this->serveThroughOctane(Request::create('/octane/operators', 'GET'), Request::create('/octane/operators', 'GET'));

        $expected = json_encode(['registry' => spl_object_id($app->make(OperatorRegistry::class)), 'starts' => true]);

        self::assertSame([], $client->errors);
        self::assertSame($expected, $client->responses[0]->getContent());
        self::assertSame($expected, $client->responses[1]->getContent());
    }
}
