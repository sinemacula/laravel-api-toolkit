<?php

declare(strict_types = 1);

namespace Tests\Feature\Resources;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use Tests\Fixtures\Models\AggregateLine;
use Tests\Fixtures\Models\AggregateOwner;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\AggregateOwnerResource;
use Tests\Fixtures\Resources\AggregatingUserResource;
use Tests\TestCase;

/**
 * Feature tests for the literal JSON each relation aggregate renders on every
 * engine.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValueResolver::class)]
final class AggregateWireFormatHttpTest extends TestCase
{
    /** @var string|null */
    private ?string $timezone = null;

    /**
     * Register the users and owners routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [AggregatingUserResource::class, AggregateOwnerResource::class]);

        Route::middleware(ParseApiQuery::class)->get('/users', static function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(AggregatingUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, AggregatingUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/owners/{owner}', static function (string $owner): AggregateOwnerResource {

            $model = AggregateOwner::query()->findOrFail($owner);

            return new AggregateOwnerResource($model, true);
        });
    }

    /**
     * Restore the default timezone a test changed.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if ($this->timezone !== null) {
            date_default_timezone_set($this->timezone);
        }

        parent::tearDown();
    }

    /**
     * Provide each application timezone with the instants the stored post times
     * read as.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function timezoneProvider(): iterable
    {
        yield 'UTC' => ['UTC', '2026-01-01T08:00:00.000000Z', '2026-03-01T09:30:00.000000Z'];
        yield 'Europe/Paris' => ['Europe/Paris', '2026-01-01T07:00:00.000000Z', '2026-03-01T08:30:00.000000Z'];
    }

    /**
     * Test that a count is an integer, an existence check a boolean, and a
     * minimum or maximum timestamp a UTC instant, while over zero related rows
     * the minimum and maximum are null.
     *
     * @param  string  $timezone
     * @param  string  $earliest
     * @param  string  $latest
     * @return void
     */
    #[DataProvider('timezoneProvider')]
    public function testAggregatesRenderTheirDocumentedJsonTypes(string $timezone, string $earliest, string $latest): void
    {
        $this->timezone = date_default_timezone_get();

        date_default_timezone_set($timezone);
        Config::set('app.timezone', $timezone);

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);

        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);

        Post::query()->insert([
            ['user_id' => $alice->id, 'title' => 'Later', 'body' => 'Content', 'published' => true, 'created_at' => '2026-03-01 09:30:00'],
            ['user_id' => $alice->id, 'title' => 'Earlier', 'body' => 'Content', 'published' => false, 'created_at' => '2026-01-01 08:00:00'],
        ]);

        $content = (string) $this->getJson('/users?' . http_build_query([
            'fields'     => ['aggregating_users' => 'name,aggregates'],
            'aggregates' => ['aggregating_users' => 'posts_count,has_published_posts,posts_min_created_at,last_posted_at'],
        ]))->assertOk()->baseResponse->getContent();

        self::assertStringContainsString(sprintf(
            '"aggregates":{"posts_count":2,"has_published_posts":true,"posts_min_created_at":"%s","last_posted_at":"%s"},"name":"Alice"',
            $earliest,
            $latest,
        ), $content);
        self::assertStringContainsString(
            '"aggregates":{"posts_count":0,"has_published_posts":false,"posts_min_created_at":null,"last_posted_at":null},"name":"Bob"',
            $content,
        );
    }

    /**
     * Test that a sum and an average over a decimal column are JSON numbers, a
     * minimum reads through the related decimal cast as an exact string, and a
     * maximum over the uncast column renders as the engine reads it, a float on
     * SQLite and an exact string elsewhere.
     *
     * @return void
     */
    public function testDecimalAggregatesRenderAsDocumentedOnEachEngine(): void
    {
        $owner = DB::table('aggregate_owners')->insertGetId(['name' => 'Ada']);

        AggregateLine::query()->insert([
            ['owner_id' => $owner, 'price' => '0.10', 'cost' => '0.10'],
            ['owner_id' => $owner, 'price' => '0.20', 'cost' => '0.20'],
        ]);

        $aggregates = $this->getJson('/owners/' . $owner)->assertOk()->json('data.aggregates');

        self::assertIsArray($aggregates);
        self::assertSame(['lines_sum_price', 'lines_avg_price', 'lines_min_price', 'lines_max_cost'], array_keys($aggregates));
        self::assertIsFloat($aggregates['lines_sum_price']);
        self::assertEqualsWithDelta(0.3, $aggregates['lines_sum_price'], 1e-9);
        self::assertIsFloat($aggregates['lines_avg_price']);
        self::assertEqualsWithDelta(0.15, $aggregates['lines_avg_price'], 1e-9);
        self::assertSame('0.10', $aggregates['lines_min_price']);
        self::assertSame(DB::connection()->getDriverName() === 'sqlite' ? 0.2 : '0.20', $aggregates['lines_max_cost']);
    }

    /**
     * Test that every decimal aggregate over zero related rows is null.
     *
     * @return void
     */
    public function testDecimalAggregatesOverNoLinesAreNull(): void
    {
        $owner = DB::table('aggregate_owners')->insertGetId(['name' => 'Ada']);

        self::assertStringContainsString(
            '"aggregates":{"lines_sum_price":null,"lines_avg_price":null,"lines_min_price":null,"lines_max_cost":null}',
            (string) $this->getJson('/owners/' . $owner)->assertOk()->baseResponse->getContent(),
        );
    }

    /**
     * Create the owners and their decimal lines.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::dropIfExists('aggregate_owners');
        Schema::create('aggregate_owners', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::dropIfExists('aggregate_lines');
        Schema::create('aggregate_lines', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id')->index();
            $table->decimal('price', 10, 2);
            $table->decimal('cost', 10, 2);
        });
    }
}
