<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request as RequestFacade;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class ApiRepositoryIntegrationTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function testRepositoryUsingResourcePropagatesToApiCriteriaAndResolvesMappedResource(): void
    {
        $repository = $this->app->make(UserRepository::class);

        $repository->usingResource(PostResource::class)->withApiCriteria();

        $criteria = $repository->getCriteria()->first();

        static::assertInstanceOf(ApiCriteria::class, $criteria);
        static::assertSame(PostResource::class, $this->getNonPublicProperty($criteria, 'customResourceClass'));

        $repository->usingResource(UserResource::class);

        static::assertSame(UserResource::class, $this->getNonPublicProperty($criteria, 'customResourceClass'));

        $repository->usingResource(null);

        static::assertSame(UserResource::class, $repository->getResourceClass());
    }

    public function testRepositoryPaginatesWithOffsetAndCursorModes(): void
    {
        User::query()->create(['name' => 'One']);
        User::query()->create(['name' => 'Two']);
        User::query()->create(['name' => 'Three']);

        $repository = $this->app->make(UserRepository::class);

        $offsetRequest = HttpRequest::create('/api/users', 'GET', [
            'page'  => 1,
            'limit' => 2,
        ]);

        $this->app->instance('request', $offsetRequest);
        RequestFacade::swap($offsetRequest);
        ApiQuery::parse($offsetRequest);

        $offsetPaginator = $repository->paginate();

        static::assertInstanceOf(LengthAwarePaginator::class, $offsetPaginator);
        static::assertSame(2, $offsetPaginator->perPage());

        $cursorRequest = HttpRequest::create('/api/users', 'GET', [
            'pagination' => 'cursor',
            'limit'      => 1,
        ]);

        $this->app->instance('request', $cursorRequest);
        RequestFacade::swap($cursorRequest);
        ApiQuery::parse($cursorRequest);

        $cursorPaginator = $repository->paginate();

        static::assertInstanceOf(CursorPaginator::class, $cursorPaginator);
        static::assertSame(1, $cursorPaginator->perPage());
    }

    public function testRepositorySetsAttributesCastsRelationsAndInternalHelpers(): void
    {
        $repository   = $this->app->make(UserRepository::class);
        $organization = Organization::query()->create(['name' => 'Acme']);
        $firstTag     = Tag::query()->create(['name' => 'alpha']);
        $secondTag    = Tag::query()->create(['name' => 'beta']);
        $user         = User::query()->create(['name' => 'Alice']);

        Relation::enforceMorphMap([
            'user' => User::class,
            'tag'  => Tag::class,
        ]);

        $saved = $repository->setAttributes($user, new Collection([
            'age'              => '42',
            'active'           => 1,
            'meta'             => ['role' => 'admin'],
            'settings'         => ['theme' => 'dark'],
            'state'            => UserStatus::ACTIVE,
            'score'            => '123.45',
            'organization'     => $organization,
            'tags'             => collect([$firstTag, $secondTag]),
            'missing_relation' => 'ignored',
        ]));

        static::assertTrue($saved);

        $user->refresh();

        static::assertSame(42, $user->age);
        static::assertTrue($user->active);
        static::assertSame(['role' => 'admin'], $user->meta);
        static::assertSame('dark', $user->settings->theme);
        static::assertSame(UserStatus::ACTIVE, $user->state);
        static::assertSame($organization->id, $user->organization_id);
        static::assertEqualsCanonicalizing([$firstTag->id, $secondTag->id], $user->tags()->pluck('tags.id')->all());

        $repository->setAttributes($user, ['score' => '']);
        $user->refresh();

        static::assertNull($user->score);

        Log::shouldReceive('error')->once();
        static::assertNull($this->invokeNonPublic($repository, 'resolveCastForRelation', 'doesNotExist'));

        static::assertSame('associate', $this->invokeNonPublic($repository, 'resolveCastForRelation', 'organization'));
        static::assertSame('associate', $this->invokeNonPublic($repository, 'resolveCastForRelation', 'owner'));
        static::assertSame('sync', $this->invokeNonPublic($repository, 'resolveCastForRelation', 'tags'));
        static::assertSame('sync', $this->invokeNonPublic($repository, 'resolveCastForRelation', 'polymorphicTags'));
        static::assertNull($this->invokeNonPublic($repository, 'resolveCastForRelation', 'posts'));
        static::assertNull($this->invokeNonPublic($repository, 'resolveCastForRelation', 'setAttribute'));

        static::assertTrue($this->invokeNonPublic($repository, 'castMatchesLaravelCast', AsStringable::class, AsStringable::class));
        static::assertTrue($this->invokeNonPublic($repository, 'castMatchesLaravelCast', 'decimal:2', 'decimal:.*'));
        static::assertTrue($this->invokeNonPublic($repository, 'castMatchesLaravelCast', 'boolean', 'boolean'));
        static::assertFalse($this->invokeNonPublic($repository, 'castMatchesLaravelCast', 'integer', 'boolean'));

        static::assertSame('enum', $this->invokeNonPublic($repository, 'resolveCastForAttribute', 'state', UserStatus::class));
        static::assertSame('string', $this->invokeNonPublic($repository, 'resolveCastForAttribute', 'score', 'decimal:2'));
        static::assertSame('associate', $this->invokeNonPublic($repository, 'resolveCastForAttribute', 'organization', null));

        $this->invokeNonPublic($repository, 'setAttribute', $user, 'age', null, 'integer');
        $this->invokeNonPublic($repository, 'setAttribute', $user, 'meta', null, 'array');
        $this->invokeNonPublic($repository, 'setAttribute', $user, 'settings', null, 'object');
        $this->invokeNonPublic($repository, 'setAttribute', $user, 'score', '', 'string');

        static::assertNull($user->age);
        static::assertNull($user->meta);
        static::assertNull($user->settings);
        static::assertNull($user->score);

        $this->invokeNonPublic($repository, 'storeCastsInCache');
        static::assertNotEmpty($this->invokeNonPublic($repository, 'resolveCastsFromCache'));

        $cachedRepository = $this->app->make(UserRepository::class);
        $this->invokeNonPublic($cachedRepository, 'resolveAttributeCasts');

        static::assertNotEmpty($this->getNonPublicProperty($cachedRepository, 'casts'));
    }

    public function testRepositoryScopeHelpersApplyWhereInConstraints(): void
    {
        $first  = User::query()->create(['name' => 'One']);
        $second = User::query()->create(['name' => 'Two']);

        $repository = $this->app->make(UserRepository::class);

        $single = $repository->scopeById($first->id)->get();
        static::assertCount(1, $single);
        static::assertSame($first->id, $single->first()->id);

        $many = $repository->scopeByIds([$first->id, $first->id, $second->id])->get();

        static::assertCount(2, $many);
        static::assertEqualsCanonicalizing([$first->id, $second->id], $many->pluck('id')->all());
    }
}
