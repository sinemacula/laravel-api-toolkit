<?php

declare(strict_types = 1);

namespace Tests\Integration\Query;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Query\ApiQuerySnapshot;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use Tests\Concerns\UsesDatabaseQueue;
use Tests\Fixtures\Jobs\QueryReplayingJob;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Integration tests that a parsed query carried into queued work selects and
 * shapes the same rows there.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiQueryParser::class)]
#[CoversClass(ApiCriteria::class)]
final class QueuedQuerySnapshotTest extends TestCase
{
    use UsesDatabaseQueue;

    /** @var string The refusal raised when no API query is parsed */
    private const string UNPARSED = 'API criteria need a parsed API query: apply them inside a request handled by ParseApiQuery, or inside ApiQuery::using().';

    /** @var string A filter selecting only the inactive users */
    private const string INACTIVE = '{"status":{"$eq":"inactive"}}';

    /**
     * Seed users on a private database queue.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        QueryReplayingJob::$rendered = null;

        Config::set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class]);

        $this->useDatabaseQueue();

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        User::create(['name' => 'Charlie', 'email' => 'charlie@example.com', 'status' => 'inactive']);
        User::create(['name' => 'Dana', 'email' => 'dana@example.com', 'status' => 'inactive']);
    }

    /**
     * Test that a job carrying the request's snapshot acts on only the rows it
     * selects and renders the fields it asks for, on a worker that starts with
     * an empty parser.
     *
     * @return void
     */
    public function testQueuedJobReplaysTheRequestsQuery(): void
    {
        ApiQuery::parse(Request::create('/users', 'GET', [
            'filters' => self::INACTIVE,
            'fields'  => ['users' => 'name'],
            'limit'   => '1',
        ]));

        Queue::push(new QueryReplayingJob(ApiQuery::snapshot()));

        $this->workUntilEmpty(1);

        self::assertSame([
            ['_type' => 'users', 'id' => 2, 'name' => 'Charlie'],
            ['_type' => 'users', 'id' => 3, 'name' => 'Dana'],
        ], QueryReplayingJob::$rendered);
        self::assertSame(0, DB::connection('queue_probe')->table('failed_jobs')->count());
    }

    /**
     * Test that a job applying the criteria with no snapshot fails instead of
     * reading every row.
     *
     * @return void
     */
    public function testQueuedJobWithoutASnapshotFails(): void
    {
        Queue::push(new QueryReplayingJob);

        $this->workUntilEmpty(1);

        $exception = DB::connection('queue_probe')->table('failed_jobs')->value('exception');

        self::assertNull(QueryReplayingJob::$rendered);
        self::assertIsString($exception);
        self::assertStringStartsWith('LogicException: ' . self::UNPARSED, $exception);
    }

    /**
     * Test that a job run on the sync connection inside a request leaves the
     * request's query in place afterwards.
     *
     * @return void
     */
    public function testSyncJobLeavesTheRequestsQueryIntact(): void
    {
        Route::get('/users', static function (): JsonResponse {

            Queue::connection('sync')->push(new QueryReplayingJob(new ApiQuerySnapshot(['filters' => self::INACTIVE, 'fields' => ['users' => 'email']])));

            return new JsonResponse(['filters' => ApiQuery::getFilters(), 'fields' => ApiQuery::getFields('users')]);
        })->middleware(ParseApiQuery::class);

        $this->getJson('/users?fields[users]=name')
            ->assertOk()
            ->assertExactJson(['filters' => [], 'fields' => ['name']]);

        self::assertSame([
            ['_type' => 'users', 'id' => 2, 'email' => 'charlie@example.com'],
            ['_type' => 'users', 'id' => 3, 'email' => 'dana@example.com'],
        ], QueryReplayingJob::$rendered);
    }
}
