<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ColumnProjectionApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use Tests\Concerns\RecordsStatements;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Role;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\PostKeyedOrganizationResource;
use Tests\Fixtures\Resources\RoleResource;
use Tests\TestCase;

/**
 * Feature tests for reads whose query joins a second table sharing column names
 * with the first, through a relation or a repository scope.
 *
 * The keys of users, organizations, pivot rows and posts overlap, so a column
 * read from the wrong table returns the wrong rows.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
#[CoversClass(ColumnProjectionApplier::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(OrderApplier::class)]
final class JoinedRepositoryQueryTest extends TestCase
{
    use RecordsStatements, RegistersApiExceptionHandler;

    /**
     * Register a route per root, the users one joining their organization, and
     * seed the rows.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [FilterableUserResource::class, RoleResource::class, PostKeyedOrganizationResource::class]);

        Route::middleware(ParseApiQuery::class)->get('/users', fn (UserRepository $repository): ApiResourceCollection => new ApiResourceCollection(
            $this->joined($repository)->withApiCriteria()->paginate(),
            FilterableUserResource::class,
        ));

        Route::middleware(ParseApiQuery::class)->get('/users/{id}', fn (UserRepository $repository, int $id): FilterableUserResource => new FilterableUserResource(
            $this->joined($repository)->scopeById($id)->firstOrFail(), // @phpstan-ignore staticMethod.dynamicCall
        ));

        Route::middleware(ParseApiQuery::class)->get('/roles', static fn (ApiCriteria $criteria): ApiResourceCollection => new ApiResourceCollection(
            $criteria->usingResource(RoleResource::class)->apply(new Role)->get(),
            RoleResource::class,
        ));

        Route::middleware(ParseApiQuery::class)->get('/organizations', static fn (ApiCriteria $criteria): ApiResourceCollection => new ApiResourceCollection(
            $criteria->usingResource(PostKeyedOrganizationResource::class)->apply(new Organization)->get(),
            PostKeyedOrganizationResource::class,
        ));

        $this->seedRows();
    }

    /**
     * Test that a root column filter reads the column of the model's table.
     *
     * @return void
     */
    public function testRootColumnFilterReadsTheModelsTable(): void
    {
        self::assertSame(['Bob'], $this->names($this->read('/users', ['filters' => ['name' => 'Bob']])));
    }

    /**
     * Test that a filter through a pivot carrying its own key reads the related
     * table's key.
     *
     * @return void
     */
    public function testFilterThroughAKeyedPivotReadsTheRelatedTable(): void
    {
        $alice = $this->userId('Alice');

        self::assertSame(['editor'], $this->names($this->read('/roles', ['filters' => ['users' => ['id' => ['$in' => [$alice]]]]])));
    }

    /**
     * Test that a filter through a has-many-through relation reads the far
     * table's key.
     *
     * @return void
     */
    public function testFilterThroughAHasManyThroughReadsTheFarTable(): void
    {
        $post = Post::query()->where('title', 'Bob writes')->value('id');

        self::assertSame(['Globex'], $this->names($this->read('/organizations', ['filters' => ['posts' => ['id' => ['$in' => [$post]]]]])));
    }

    /**
     * Test that an offset page orders by the column of the model's table.
     *
     * @return void
     */
    public function testOffsetOrderReadsTheModelsTable(): void
    {
        self::assertSame(['Carol', 'Bob', 'Alice'], $this->names($this->read('/users', ['order' => 'name:desc'])));
    }

    /**
     * Test that cursor pages seek by the column of the model's table, forwards
     * and back.
     *
     * @return void
     */
    public function testCursorPagesSeekByTheModelsTableForwardsAndBack(): void
    {
        $first = $this->read('/users', ['pagination' => 'cursor', 'order' => 'name', 'limit' => '2']);

        self::assertSame(['Alice', 'Bob'], $this->names($first));

        $next = $first->json('links.next');

        self::assertIsString($next);

        $second = $this->getJson($next)->assertOk();

        self::assertSame(['Carol'], $this->names($second));

        $prev = $second->json('links.prev');

        self::assertIsString($prev);
        self::assertSame(['Alice', 'Bob'], $this->names($this->getJson($prev)->assertOk()));
    }

    /**
     * Test that a narrowed projection selects the model's table's columns.
     *
     * @return void
     */
    public function testNarrowedProjectionSelectsTheModelsTable(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $response   = null;
        $statements = $this->recordStatements(function () use (&$response): void {
            $response = $this->read('/users', ['fields' => ['filterable_users' => 'id,name'], 'order' => 'name']);
        });

        self::assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, '.*'))));
        self::assertInstanceOf(TestResponse::class, $response);
        self::assertSame(
            [$this->userId('Alice'), $this->userId('Bob'), $this->userId('Carol')],
            array_column((array) $response->json('data'), 'id'),
        );
    }

    /**
     * Test that scopeById reads the key of the model's table.
     *
     * @return void
     */
    public function testScopeByIdReadsTheModelsKey(): void
    {
        $this->getJson('/users/' . $this->userId('Carol'))->assertOk()->assertJsonPath('data.name', 'Carol');
    }

    /**
     * Define the database migrations, adding the roles and their keyed and
     * timestamped pivot.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');

        Schema::create('roles', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('role_user', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });
    }

    /**
     * Scope the users to those joined to their organization, selecting only the
     * users' columns where the criteria select none.
     *
     * @param  \Tests\Fixtures\Repositories\UserRepository  $repository
     * @return \Tests\Fixtures\Repositories\UserRepository
     */
    private function joined(UserRepository $repository): UserRepository
    {
        return $repository->usingResource(FilterableUserResource::class)->addScope(static function (Builder $query): void {
            $query->getQuery()->columns ??= ['users.*'];
            $query->join('organizations', 'organizations.id', '=', 'users.organization_id');
        });
    }

    /**
     * Read the URI with the query parameters, asserting success.
     *
     * @param  string  $uri
     * @param  array<string, mixed>  $parameters
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(string $uri, array $parameters): TestResponse
    {
        if (isset($parameters['filters'])) {
            $parameters['filters'] = json_encode($parameters['filters'], JSON_THROW_ON_ERROR);
        }

        return $this->getJson($uri . '?' . http_build_query($parameters))->assertOk();
    }

    /**
     * Read the name of each row in the response, in order.
     *
     * @param  \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @return array<int, mixed>
     */
    private function names(TestResponse $response): array
    {
        return array_column((array) $response->json('data'), 'name');
    }

    /**
     * Return the id of the named user.
     *
     * @param  string  $name
     * @return int
     */
    private function userId(string $name): int
    {
        $id = User::query()->where('name', $name)->value('id');

        self::assertIsInt($id);

        return $id;
    }

    /**
     * Seed two organizations, three users, two roles and two posts.
     *
     * @return void
     */
    private function seedRows(): void
    {
        $acme   = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $globex = Organization::create(['name' => 'Globex', 'slug' => 'globex']);

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'organization_id' => $acme->id]);
        $bob   = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'organization_id' => $globex->id]);
        $carol = User::create(['name' => 'Carol', 'email' => 'carol@example.com', 'organization_id' => $acme->id]);

        $admin  = Role::create(['name' => 'admin']);
        $editor = Role::create(['name' => 'editor']);

        $admin->users()->attach($carol->id);
        $editor->users()->attach($alice->id);
        $admin->users()->attach($bob->id);

        Post::create(['user_id' => $bob->id, 'title' => 'Bob writes', 'body' => 'Body']);
        Post::create(['user_id' => $alice->id, 'title' => 'Alice writes', 'body' => 'Body']);
    }
}
