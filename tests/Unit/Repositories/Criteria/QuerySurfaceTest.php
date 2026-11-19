<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator;
use SineMacula\ApiToolkit\Repositories\Criteria\CallerAdmission;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Repositories\Criteria\SortTarget;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\RelationTraversal;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\LocalisesToolkitMessages;
use Tests\Fixtures\Models\Gathering;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\GatheringResource;
use Tests\Fixtures\Resources\GuardedQueryablePostResource;
use Tests\Fixtures\Resources\GuardedQueryableUserResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\PublicUserResource;
use Tests\Fixtures\Resources\SortableUserResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the QuerySurface enforcement policy.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QuerySurface::class)]
final class QuerySurfaceTest extends TestCase
{
    use LocalisesToolkitMessages;

    /**
     * Test that only a declared filter column is permitted.
     *
     * @return void
     */
    public function testPermitsDeclaredAndRejectsUndeclaredFilter(): void
    {
        $surface = $this->make(filterable: ['email' => Capability::EXACT]);

        $this->assertPermits(fn () => $surface->guardFilter('email'), 'email');

        try {
            $surface->guardFilter('password');
            self::fail('Expected a ValidationException for an undeclared filter key.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('filters.password', $exception->errors());
            self::assertSame('The "password" key is not a permitted query parameter for this resource.', $exception->errors()['filters.password'][0]);
        }
    }

    /**
     * Test that the operator gate follows the column's capability.
     *
     * @return void
     */
    public function testGatesEachOperatorAgainstTheDeclaringColumnsCapability(): void
    {
        $surface = $this->make(filterable: ['status' => Capability::ENUM, 'email' => Capability::EXACT]);

        $this->assertPermits(fn () => $surface->guardFilterOperator('status', '$neq'), 'status');

        try {
            $surface->guardFilterOperator('email', '$neq');
            self::fail('Expected a ValidationException for an operator the capability does not answer.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['The "$neq" operator is not permitted on the "email" key for this resource, which accepts $eq, $in, $null, $notNull.'],
                $exception->errors()['filters.email.$neq'] ?? [],
            );
        }
    }

    /**
     * Test that the operator gate rejects an undeclared column as undeclared.
     *
     * @return void
     */
    public function testOperatorGateRejectsAnUndeclaredColumnAsAnUndeclaredKey(): void
    {
        $surface = $this->make(filterable: ['email' => Capability::EXACT]);

        try {
            $surface->guardFilterOperator('password', '$eq');
            self::fail('Expected a ValidationException for an undeclared filter key.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['The "password" key is not a permitted query parameter for this resource.'],
                $exception->errors()['filters.password'] ?? [],
            );
        }
    }

    /**
     * Test that a registered token is admitted only on the capabilities it was
     * registered with, and listed only where admitted.
     *
     * @return void
     */
    public function testGatesARegisteredTokenByItsCapabilities(): void
    {
        $this->operators()->register('$starts', static function (): void {}, Capability::RANGE);

        $surface = $this->make(filterable: ['id' => Capability::RANGE, 'email' => Capability::EXACT, 'code' => Capability::OPAQUE]);

        $this->assertPermits(fn () => $surface->guardFilterOperator('id', '$starts'), 'id');
        $this->assertRejectsWith(fn () => $surface->guardFilterOperator('email', '$starts'), $this->unpermitted('$starts', 'email', '$eq, $in, $null, $notNull'));
        $this->assertRejectsWith(fn () => $surface->guardFilterOperator('code', '$starts'), $this->unpermitted('$starts', 'code', '$eq'));
        $this->assertRejectsWith(
            fn () => $surface->guardFilterOperator('id', '$neq'),
            $this->unpermitted('$neq', 'id', '$eq, $in, $gt, $ge, $lt, $le, $between, $null, $notNull, $starts'),
        );
    }

    /**
     * Test that a removed shipped token is refused and no longer offered.
     *
     * @return void
     */
    public function testRefusesAndStopsOfferingARemovedToken(): void
    {
        $this->operators()->remove('$between');

        $surface = $this->make(filterable: ['id' => Capability::RANGE]);

        $this->assertRejectsWith(
            fn () => $surface->guardFilterOperator('id', '$between'),
            $this->unpermitted('$between', 'id', '$eq, $in, $gt, $ge, $lt, $le, $null, $notNull'),
        );
    }

    /**
     * Test that a column whose capability admits no registered token refuses
     * every operator as accepting none.
     *
     * @return void
     */
    public function testRefusesEveryOperatorOnACapabilityAdmittingNone(): void
    {
        $this->operators()->remove('$contains');

        $surface = $this->make(filterable: ['settings' => Capability::DOCUMENT]);

        $this->assertRejectsWith(
            fn () => $surface->guardFilterOperator('settings', '$contains'),
            ['filters.settings.$contains' => ['The "settings" key accepts no registered filter operators.']],
        );
    }

    /**
     * Test that a related level gates a registered token by its own column's
     * capability.
     *
     * @return void
     */
    public function testGatesARegisteredTokenOnARelatedLevelByItsColumn(): void
    {
        $this->operators()->register('$starts', static function (): void {}, Capability::RANGE);

        $author = $this->make(relations: ['author'], resourceMap: [User::class => UserResource::class])->through('author', new User, false);

        $this->assertPermits(fn () => $author->guardFilterOperator('id', '$starts'), 'id');
        $this->assertRejectsWith(fn () => $author->guardFilterOperator('name', '$starts'), $this->unpermitted('$starts', 'name', '$eq, $in, $null, $notNull'));
    }

    /**
     * Test that every rejection is worded in the current locale.
     *
     * @return void
     */
    public function testRejectionsAreWordedInTheCurrentLocale(): void
    {
        $this->localiseToolkitMessages([
            'query.undeclared_key'         => 'Der Schlüssel ":key" ist nicht zulässig.',
            'filters.unpermitted_operator' => 'Der Operator ":operator" ist für ":key" nicht zulässig, erlaubt sind :accepts.',
            'filters.no_operators'         => 'Der Schlüssel ":key" nimmt keine Operatoren an.',
        ]);

        $this->operators()->remove('$contains');

        $surface = $this->make(filterable: ['email' => Capability::EXACT, 'settings' => Capability::DOCUMENT]);

        $this->assertRejectsWith(fn () => $surface->guardSort('secret'), ['order.secret' => ['Der Schlüssel "secret" ist nicht zulässig.']]);
        $this->assertRejectsWith(fn () => $surface->guardFilterOperator('password', '$eq'), ['filters.password' => ['Der Schlüssel "password" ist nicht zulässig.']]);
        $this->assertRejectsWith(
            fn () => $surface->guardFilterOperator('email', '$neq'),
            ['filters.email.$neq' => ['Der Operator "$neq" ist für "email" nicht zulässig, erlaubt sind $eq, $in, $null, $notNull.']],
        );
        $this->assertRejectsWith(
            fn () => $surface->guardFilterOperator('settings', '$contains'),
            ['filters.settings.$contains' => ['Der Schlüssel "settings" nimmt keine Operatoren an.']],
        );
    }

    /**
     * Test that sort columns and traversable relations must be declared.
     *
     * @return void
     */
    public function testGovernsSortAndRelationDeclarations(): void
    {
        $surface = $this->make(sortable: ['created_at'], relations: ['posts']);

        $this->assertPermits(fn () => $surface->guardSort('created_at'), 'created_at');
        $this->assertPermits(fn () => $surface->guardRelation('posts'), 'posts');

        $this->assertRejects(fn () => $surface->guardSort('secret'), 'order.secret');
        $this->assertRejects(fn () => $surface->guardRelation('audits'), 'filters.audits');
    }

    /**
     * Test that isDeclaredRelation reports the traversable set without
     * throwing.
     *
     * @return void
     */
    public function testIsDeclaredRelationReportsTheTraversableSetWithoutThrowing(): void
    {
        $surface = $this->make(relations: ['posts']);

        self::assertTrue($surface->isDeclaredRelation('posts'));
        self::assertFalse($surface->isDeclaredRelation('audits'));
    }

    /**
     * Test that a surface no resource governs rejects every key.
     *
     * @return void
     */
    public function testUngovernedSurfaceRejectsEveryKey(): void
    {
        $surface = new QuerySurface(null, $this->traversal([Post::class => PostResource::class]), $this->admission(), $this->operators());

        $this->assertRejects(fn () => $surface->guardFilter('title'), 'filters.title');
        $this->assertRejects(fn () => $surface->guardFilterOperator('title', '$eq'), 'filters.title');
        $this->assertRejects(fn () => $surface->guardSort('title'), 'order.title');
        $this->assertRejects(fn () => $surface->guardSort('aggregates.tags_count'), 'order.aggregates.tags_count');
        $this->assertRejects(fn () => $surface->guardSort('user.name'), 'order.user.name');
        $this->assertRejects(fn () => $surface->guardRelation('user'), 'filters.user');
        $this->assertRejects(fn () => $surface->through('user', new User, false)->guardFilter('name'), 'filters.name');
    }

    /**
     * Test that a relation is governed by the resource declared over it, not
     * the related model's mapped resource.
     *
     * @return void
     */
    public function testThroughPrefersTheDeclaredResourceOverTheMappedOne(): void
    {
        $surface = $this->make(relations: ['author'], resources: ['author' => PublicUserResource::class], resourceMap: [User::class => UserResource::class]);

        $author = $surface->through('author', new User, false);

        $this->assertPermits(fn () => $author->guardFilter('name'), 'name');
        $this->assertPermits(fn () => $author->guardSort('name'), 'name');
        $this->assertRejects(fn () => $author->guardFilter('email'), 'filters.email');
        $this->assertRejects(fn () => $author->guardSort('email'), 'order.email');
    }

    /**
     * Test that a relation on the root's own model is governed by its declared
     * resource rather than the root's.
     *
     * @return void
     */
    public function testSelfRelationIsGovernedByItsDeclaredResource(): void
    {
        $surface = $this->make(
            filterable : ['email' => Capability::EXACT],
            relations  : ['manager'],
            resources  : ['manager' => PublicUserResource::class],
            resourceMap: [User::class => UserResource::class],
        );

        $manager = $surface->through('manager', new User, false);

        $this->assertPermits(fn () => $surface->guardFilter('email'), 'email');
        $this->assertRejects(fn () => $manager->guardFilter('email'), 'filters.email');
        $this->assertPermits(fn () => $manager->guardFilter('name'), 'name');
    }

    /**
     * Test that a relation declaring no resource is governed by the related
     * model's mapped resource.
     *
     * @return void
     */
    public function testThroughFallsBackToTheMappedResourceForAnAccessorOnlyRelation(): void
    {
        $surface = $this->make(relations: ['posts'], resourceMap: [Post::class => PostResource::class]);

        $posts = $surface->through('posts', new Post, false);

        $this->assertPermits(fn () => $posts->guardFilterOperator('title', '$eq'), 'title');
        $this->assertRejects(fn () => $posts->guardFilterOperator('title', '$contains'), 'filters.title.$contains');
        $this->assertRejects(fn () => $posts->guardSort('title'), 'order.title');
    }

    /**
     * Test that a polymorphic descent is governed by the concrete type's mapped
     * resource, whatever resource the relation declares.
     *
     * @return void
     */
    public function testThroughResolvesAPolymorphicDescentPerType(): void
    {
        $surface = $this->make(
            relations  : ['noteable'],
            resources  : ['noteable' => PublicUserResource::class],
            resourceMap: [User::class => FilterableUserResource::class, Post::class => PostResource::class],
        );

        $this->assertPermits(fn () => $surface->through('noteable', new User, true)->guardFilter('email'), 'email');
        $this->assertPermits(fn () => $surface->through('noteable', new Post, true)->guardFilter('title'), 'title');
        $this->assertRejects(fn () => $surface->through('noteable', new Post, true)->guardFilter('email'), 'filters.email');
    }

    /**
     * Test that a level no resource governs refuses every key beneath it.
     *
     * @return void
     */
    public function testThroughFailsClosedWhenNoResourceGovernsTheLevel(): void
    {
        $surface = $this->make(
            relations  : ['posts', 'user', 'tags'],
            resources  : ['tags' => \stdClass::class],
            resourceMap: [User::class => \stdClass::class],
        );

        $this->assertRejects(fn () => $surface->through('posts', new Post, false)->guardFilter('title'), 'filters.title');
        $this->assertRejects(fn () => $surface->through('user', new User, false)->guardFilter('name'), 'filters.name');
        $this->assertRejects(fn () => $surface->through('tags', new Post, false)->guardRelation('user'), 'filters.user');
    }

    /**
     * Test that every key only caller-guarded fields declare is refused to a
     * caller they hide the fields from, exactly as an undeclared key is.
     *
     * @return void
     */
    public function testRefusesCallerGuardedKeysAsUndeclaredToAHiddenCaller(): void
    {
        $surface = $this->governedBy(GuardedQueryableUserResource::class);

        $this->assertRejectsWith(fn () => $surface->guardFilter('email'), $this->undeclared('filters', 'email'));
        $this->assertRejectsWith(fn () => $surface->guardFilterOperator('email', '$eq'), $this->undeclared('filters', 'email'));
        $this->assertRejectsWith(fn () => $surface->guardSort('email'), $this->undeclared('order', 'email'));
        $this->assertRejectsWith(fn () => $surface->guardRelation('posts'), $this->undeclared('filters', 'posts'));

        self::assertFalse($surface->isDeclaredRelation('posts'));

        $this->assertPermits(fn () => $surface->guardFilterOperator('name', '$eq'), 'name');
        $this->assertPermits(fn () => $surface->guardSort('name'), 'name');
    }

    /**
     * Test that a caller the guards pass is admitted to every key they gate.
     *
     * @return void
     */
    public function testAdmitsCallerGuardedKeysForAPassingCaller(): void
    {
        $surface = $this->governedBy(GuardedQueryableUserResource::class, asAdmin: true);

        $this->assertPermits(fn () => $surface->guardFilter('email'), 'email');
        $this->assertPermits(fn () => $surface->guardFilterOperator('email', '$eq'), 'email');
        $this->assertPermits(fn () => $surface->guardSort('email'), 'email');
        $this->assertPermits(fn () => $surface->guardRelation('posts'), 'posts');

        self::assertTrue($surface->isDeclaredRelation('posts'));
    }

    /**
     * Test that a related level is gated by the related resource's caller
     * guards.
     *
     * @return void
     */
    public function testGatesRelatedKeysByTheRelatedResourcesCallerGuards(): void
    {
        $guest = $this->governedBy(GuardedQueryablePostResource::class)->through('user', new User, false);
        $admin = $this->governedBy(GuardedQueryablePostResource::class, asAdmin: true)->through('user', new User, false);

        $this->assertRejectsWith(fn () => $guest->guardFilter('email'), $this->undeclared('filters', 'email'));
        $this->assertPermits(fn () => $guest->guardFilter('name'), 'name');
        $this->assertPermits(fn () => $admin->guardFilter('email'), 'email');
    }

    /**
     * Test that each caller guard runs once per request, across the keys of
     * every level that reads it.
     *
     * @return void
     */
    public function testRunsEachCallerGuardOncePerRequest(): void
    {
        $runs  = 0;
        $guard = static function () use (&$runs): bool {

            $runs++;

            return true;
        };

        $schema = new CompiledSchema(
            fields               : ['email' => new CompiledFieldDefinition(null, null, null, null, null, null, [], [], [], [], callerGuards: [$guard])],
            filterableColumns    : ['email' => Capability::EXACT],
            sortableColumns      : ['email'],
            conditionalFilterKeys: ['email' => ['email']],
            conditionalSortKeys  : ['email' => ['email']],
        );

        $surface = new QuerySurface($schema, $this->traversal([]), $this->admission(), $this->operators());

        $surface->guardFilter('email');
        $surface->guardFilterOperator('email', '$eq');
        $surface->guardSort('email');
        $surface->guardSort('email');

        self::assertSame(1, $runs);
    }

    /**
     * Test that a scope resolves at the root and on a related level, and an
     * undeclared or caller-hidden one resolves to nothing.
     *
     * @return void
     */
    public function testResolvesADeclaredScopeTheCallerMayInvokeAtEachLevel(): void
    {
        $root     = new QuerySurface(SchemaCompiler::compile(GatheringResource::class), $this->traversal([]), $this->admission(), $this->operators());
        $sessions = $root->through('sessions', new Gathering, false);

        self::assertSame('near', $root->scopeFor('near')?->scope);
        self::assertSame('hasStarted', $sessions->scopeFor('past')?->scope);
        self::assertNull($sessions->scopeFor('near'));
        self::assertNull($root->scopeFor('cancelled'));
        self::assertNull($root->scopeFor('mine'));
        self::assertNull($this->make()->scopeFor('near'));
        self::assertNull((new QuerySurface(null, $this->traversal([]), $this->admission(), $this->operators()))->scopeFor('near'));

        $member = new QuerySurface(SchemaCompiler::compile(GatheringResource::class), $this->traversal([]), $this->admission(true), $this->operators());

        self::assertSame('ownedByCaller', $member->scopeFor('mine')?->scope);
    }

    /**
     * Test that a column, an aggregate key and a relation key each resolve to
     * what they order by.
     *
     * @return void
     */
    public function testResolvesEachOrderKeyFormToItsTarget(): void
    {
        $schema  = SchemaCompiler::compile(SortableUserResource::class);
        $surface = new QuerySurface($schema, $this->traversal([]), $this->admission(), $this->operators());

        self::assertEquals(SortTarget::column('name'), $surface->guardSort('name'));
        self::assertEquals(SortTarget::aggregate($schema->getSortableMetrics()['aggregates.published_posts']), $surface->guardSort('aggregates.published_posts'));
        self::assertEquals(SortTarget::related('latestArticle', 'views'), $surface->guardSort('latestArticle.views'));
    }

    /**
     * Test that a key naming no sortable aggregate, relation or child column,
     * or reaching past one hop, is refused as undeclared.
     *
     * @return void
     */
    public function testRefusesUnreachableAggregateAndRelationKeysAsUndeclared(): void
    {
        $surface = new QuerySurface(SchemaCompiler::compile(SortableUserResource::class), $this->traversal([]), $this->admission(), $this->operators());

        foreach (['aggregates.comments_count', 'aggregates', 'posts.title', 'latestArticle.title', 'latestArticle.views.total', 'latestArticle'] as $key) {
            $this->assertRejectsWith(fn () => $surface->guardSort($key), $this->undeclared('order', $key));
        }
    }

    /**
     * Test that a sortable root column wins over an aggregate of the same key,
     * and an aggregate over a relation whose prefix it shares.
     *
     * @return void
     */
    public function testResolvesARootColumnThenAnAggregateThenARelation(): void
    {
        $aggregate = new CompiledAggregateDefinition('name', 'posts', AggregateFunction::COUNT, null, null, false, [], unindexedReason: 'Leaderboard');
        $relation  = new CompiledFieldDefinition(null, null, 'aggregates', SortableUserResource::class, null, null, [], [], [], [], sortThroughReason: 'Admin list');
        $surface   = fn (array $columns): QuerySurface => new QuerySurface(
            new CompiledSchema(
                fields              : ['aggregates' => $relation],
                aggregates          : ['name' => $aggregate],
                sortableColumns     : $columns,
                traversableResources: ['aggregates' => SortableUserResource::class],
                sortableRelations   : ['aggregates' => ['aggregates']],
            ),
            $this->traversal([]),
            $this->admission(),
            $this->operators(),
        );

        self::assertEquals(SortTarget::column('aggregates.name'), $surface(['aggregates.name'])->guardSort('aggregates.name'));
        self::assertEquals(SortTarget::aggregate($aggregate), $surface([])->guardSort('aggregates.name'));
    }

    /**
     * Test that a caller-guarded aggregate, relation or child column is refused
     * as undeclared to a caller the guards hide it from, and admitted to one
     * they pass.
     *
     * @return void
     */
    public function testGatesAggregateAndRelationKeysByEveryCallerGuardOnTheirPath(): void
    {
        $guest = $this->guardedSorts(asAdmin: false);
        $admin = $this->guardedSorts(asAdmin: true);

        foreach (['aggregates.posts_count', 'user.name', 'author.email'] as $key) {
            $this->assertRejectsWith(fn () => $guest->guardSort($key), $this->undeclared('order', $key));
            $this->assertPermits(fn () => $admin->guardSort($key), $key);
        }

        $this->assertPermits(fn () => $guest->guardSort('author.name'), 'author.name');
    }

    /**
     * Test that a sortable relation whose declared resource is no API resource
     * orders by nothing.
     *
     * @return void
     */
    public function testRefusesARelationKeyWhoseResourceIsNoApiResource(): void
    {
        $schema  = new CompiledSchema([], traversableResources: ['user' => \stdClass::class], sortableRelations: ['user' => ['user']]);
        $surface = new QuerySurface($schema, $this->traversal([]), $this->admission(), $this->operators());

        $this->assertRejectsWith(fn () => $surface->guardSort('user.name'), $this->undeclared('order', 'user.name'));
    }

    /**
     * Build a surface whose count, relation and related column only an admin
     * may order by, for a guest or for the admin.
     *
     * @param  bool  $asAdmin
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function guardedSorts(bool $asAdmin): QuerySurface
    {
        $admin = static fn (mixed $request): bool => $request instanceof Request && data_get($request->user(), 'is_admin') === true;
        $field = static fn (string $relation, array $guards): CompiledFieldDefinition => new CompiledFieldDefinition(
            accessor         : null,
            compute          : null,
            relation         : $relation,
            resource         : GuardedQueryableUserResource::class,
            fields           : null,
            constraint       : null,
            extras           : [],
            needs            : [],
            guards           : [],
            transformers     : [],
            callerGuards     : $guards,
            sortThroughReason: 'Admin list',
        );

        $count  = new CompiledAggregateDefinition('posts_count', 'posts', AggregateFunction::COUNT, null, null, false, [], callerGuards: [$admin], unindexedReason: 'Leaderboard');
        $schema = new CompiledSchema(
            fields              : ['user' => $field('user', [$admin]), 'author' => $field('author', [])],
            aggregates          : ['posts_count' => $count],
            traversableResources: ['user' => GuardedQueryableUserResource::class, 'author' => GuardedQueryableUserResource::class],
            sortableRelations   : ['user' => ['user'], 'author' => ['author']],
        );

        return new QuerySurface($schema, $this->traversal([]), $this->admission($asAdmin), $this->operators());
    }

    /**
     * Build a query surface over the given declarations.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Enums\Capability>  $filterable
     * @param  array<int, string>  $sortable
     * @param  array<int, string>  $relations
     * @param  array<string, string>  $resources
     * @param  array<string, string>  $resourceMap
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function make(array $filterable = [], array $sortable = [], array $relations = [], array $resources = [], array $resourceMap = []): QuerySurface
    {
        return new QuerySurface(
            new CompiledSchema([], [], $filterable, $sortable, $relations, [], $resources),
            $this->traversal($resourceMap),
            $this->admission(),
            $this->operators(),
        );
    }

    /**
     * Build the caller admission for a guest, or for the seeded admin.
     *
     * @param  bool  $asAdmin
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\CallerAdmission
     */
    private function admission(bool $asAdmin = false): CallerAdmission
    {
        $request = Request::create('/');

        $request->setUserResolver(static fn (): ?User => $asAdmin ? new User(['email' => 'admin@example.com']) : null);

        return new CallerAdmission($request, new GuardEvaluator);
    }

    /**
     * Build the surface a resource governs for a guest, or for the admin.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resource
     * @param  bool  $asAdmin
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function governedBy(string $resource, bool $asAdmin = false): QuerySurface
    {
        return new QuerySurface(
            SchemaCompiler::compile($resource),
            $this->traversal([User::class => GuardedQueryableUserResource::class, Post::class => GuardedQueryablePostResource::class]),
            $this->admission($asAdmin),
            $this->operators(),
        );
    }

    /**
     * Build the relation traversal over the given resource map.
     *
     * @param  array<string, string>  $resourceMap
     * @return \SineMacula\ApiToolkit\Schema\RelationTraversal
     */
    private function traversal(array $resourceMap): RelationTraversal
    {
        return new RelationTraversal(app(SchemaIntrospectionProvider::class), $resourceMap);
    }

    /**
     * Return the bound operator registry the surfaces gate against.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry
     */
    private function operators(): OperatorRegistry
    {
        return app(OperatorRegistry::class);
    }

    /**
     * Build the errors an operator a column does not admit is refused with.
     *
     * @param  string  $operator
     * @param  string  $key
     * @param  string  $accepts
     * @return array<string, array<int, string>>
     */
    private function unpermitted(string $operator, string $key, string $accepts): array
    {
        $message = sprintf('The "%s" operator is not permitted on the "%s" key for this resource, which accepts %s.', $operator, $key, $accepts);

        return ['filters.' . $key . '.' . $operator => [$message]];
    }

    /**
     * Build the errors an undeclared key is refused with.
     *
     * @param  string  $parameter
     * @param  string  $key
     * @return array<string, array<int, string>>
     */
    private function undeclared(string $parameter, string $key): array
    {
        return [$parameter . '.' . $key => [sprintf('The "%s" key is not a permitted query parameter for this resource.', $key)]];
    }

    /**
     * Assert that invoking the guard permits the key.
     *
     * @param  callable(): mixed  $guard
     * @param  string  $key
     * @return void
     */
    private function assertPermits(callable $guard, string $key): void
    {
        $rejection = null;

        try {
            $guard();
        } catch (ValidationException $exception) {
            $rejection = $exception->errors();
        }

        self::assertNull($rejection, 'The "' . $key . '" key was rejected but should have been permitted.');
    }

    /**
     * Assert that the guard rejects with exactly the given errors.
     *
     * @param  callable(): mixed  $guard
     * @param  array<string, array<int, string>>  $errors
     * @return void
     */
    private function assertRejectsWith(callable $guard, array $errors): void
    {
        try {
            $guard();
            self::fail('Expected a ValidationException for ' . implode(', ', array_keys($errors)) . '.');
        } catch (ValidationException $exception) {
            self::assertSame($errors, $exception->errors());
        }
    }

    /**
     * Assert that the guard rejects with a ValidationException for the key.
     *
     * @param  callable(): mixed  $guard
     * @param  string  $errorKey
     * @return void
     */
    private function assertRejects(callable $guard, string $errorKey): void
    {
        try {
            $guard();
            self::fail('Expected a ValidationException for key ' . $errorKey . '.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey($errorKey, $exception->errors());
        }
    }
}
