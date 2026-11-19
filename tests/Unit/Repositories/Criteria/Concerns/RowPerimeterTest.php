<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RowPerimeter;
use SineMacula\ApiToolkit\Schema\Field;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Note;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OwnPostResource;
use Tests\Fixtures\Resources\PostOwnerResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\PostTagResource;
use Tests\Fixtures\Resources\PublishedArticleResource;
use Tests\Fixtures\Resources\PublishedPostOwnerResource;
use Tests\Fixtures\Resources\PublishedPostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the RowPerimeter.
 *
 * The probe resource runs whatever rule the request carries, so each case
 * states its own rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RowPerimeter::class)]
final class RowPerimeterTest extends TestCase
{
    /** @var string The own-posts rule without a caller, which matches nothing */
    private const string OWN_POSTS = '(0 = 1)';

    /** @var string The rule that matches only published posts */
    private const string PUBLISHED_POSTS = '("posts"."published" = ?)';

    /**
     * Provide the resources that do not restrict their rows.
     *
     * @return iterable<string, array{string|null}>
     */
    public static function unrestrictedResourceProvider(): iterable
    {
        yield 'no resource' => [null];
        yield 'not a resource' => [\stdClass::class];
        yield 'a resource keeping the default rule' => [PostResource::class];
    }

    /**
     * Test that a resource keeping the default rule does not restrict.
     *
     * @param  string|null  $resource
     * @return void
     */
    #[DataProvider('unrestrictedResourceProvider')]
    public function testResourceKeepingTheDefaultRuleDoesNotRestrict(?string $resource): void
    {
        self::assertFalse(RowPerimeter::restricts($resource));

        $query = Post::query();

        $this->perimeter()->constrain($query, $resource);

        self::assertSame('select * from "posts"', $query->toSql());
    }

    /**
     * Test that a resource restricts whether it declares the rule or inherits
     * it.
     *
     * @return void
     */
    public function testDeclaredAndInheritedRulesRestrict(): void
    {
        self::assertTrue(RowPerimeter::restricts(OwnPostResource::class));
        self::assertTrue(RowPerimeter::restricts((new class (null) extends OwnPostResource {})::class));
    }

    /**
     * Test that a relation is resolved by calling its method, and anything else
     * resolves to nothing.
     *
     * @return void
     */
    public function testRelationOfResolvesOnlyRelations(): void
    {
        self::assertInstanceOf(HasMany::class, RowPerimeter::relationOf(new User, 'posts'));
        self::assertNull(RowPerimeter::relationOf(new User, 'fullLabel'));
        self::assertNull(RowPerimeter::relationOf(new User, 'ghost'));
    }

    /**
     * Test that an aggregate constraint is returned as given where nothing
     * restricts the rows it counts, and wrapped otherwise.
     *
     * @return void
     */
    public function testAggregateConstraintIsWrappedOnlyWhereARuleApplies(): void
    {
        $existing = static function (Builder $query): void {
            $query->where('title', 'Launch');
        };

        self::assertSame($existing, $this->perimeter([Post::class => PostResource::class])->wrapAggregate($existing, UserResource::class, 'publishedPosts'));
        self::assertNull($this->perimeter([Post::class => PostResource::class])->wrapAggregate(null, UserResource::class, 'publishedPosts'));

        $wrapped = $this->perimeter([Post::class => OwnPostResource::class])->wrapAggregate($existing, UserResource::class, 'publishedPosts');

        self::assertSame('select * from "posts" where "title" = ? and ' . self::OWN_POSTS, $this->builderAfter($wrapped, Post::query()));
    }

    /**
     * Test that a path whose relation cannot be resolved ahead of the load is
     * wrapped to resolve its resource from the model it loads.
     *
     * @return void
     */
    public function testUnresolvablePathResolvesItsResourceAsItLoads(): void
    {
        $decorated = $this->perimeter([Post::class => OwnPostResource::class])->decorate(['ghost'], new User);

        self::assertSame(['ghost'], array_keys($decorated));
        self::assertInstanceOf(\Closure::class, $decorated['ghost']);
        self::assertStringEndsWith(self::OWN_POSTS, $this->sqlAfter($decorated['ghost'], (new User)->posts()));
    }

    /**
     * Test that the rule is added as one nested where group.
     *
     * @return void
     */
    public function testRuleIsAddedAsOneNestedWhereGroup(): void
    {
        $query = Post::query()->where('title', 'Launch');

        $this->perimeter(user: (new User)->forceFill(['id' => 7]))->constrain($query, OwnPostResource::class);

        self::assertSame('select * from "posts" where "title" = ? and ("posts"."user_id" in (?))', $query->toSql());
        self::assertSame(['Launch', 7], $query->getBindings());
        self::assertSame('Nested', $query->getQuery()->wheres[1]['type']);
    }

    /**
     * Test that a rule joined by or cannot widen the query it is added to.
     *
     * @return void
     */
    public function testOrJoinedRuleCannotWidenTheOuterQuery(): void
    {
        $query = Post::query()->where('title', 'Launch');

        $this->probe(static function (Builder $rule): void {
            $rule->where('published', true)->orWhere('user_id', 1);
        })->constrain($query, self::probeResource());

        self::assertSame('select * from "posts" where "title" = ? and ("published" = ? or "user_id" = ?)', $query->toSql());
    }

    /**
     * Provide rules that do more than add where clauses.
     *
     * @return iterable<string, array{\Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): mixed}>
     */
    public static function widenedRuleProvider(): iterable
    {
        yield 'join' => [static fn (Builder $rule): mixed => $rule->join('users', 'users.id', '=', 'posts.user_id')];
        yield 'select' => [static fn (Builder $rule): mixed => $rule->select('id')];
        yield 'order' => [static fn (Builder $rule): mixed => $rule->orderBy('id')];
        yield 'group' => [static fn (Builder $rule): mixed => $rule->groupBy('user_id')];
        yield 'having' => [static fn (Builder $rule): mixed => $rule->having('user_id', '>', 1)];
        yield 'limit' => [static fn (Builder $rule): mixed => $rule->limit(1)];
        yield 'offset' => [static fn (Builder $rule): mixed => $rule->offset(1)];
        yield 'union' => [static fn (Builder $rule): mixed => $rule->union(Post::query()->toBase())];
        yield 'lock' => [static fn (Builder $rule): mixed => $rule->lockForUpdate()];
        yield 'eager load' => [static fn (Builder $rule): mixed => $rule->with('user')];
        yield 'removed global scope' => [static fn (Builder $rule): mixed => $rule->withoutGlobalScope('tenant')];
        yield 'global scope' => [static fn (Builder $rule): mixed => $rule->withGlobalScope('tenant', static fn (Builder $query): mixed => $query)];
    }

    /**
     * Test that a rule doing more than adding where clauses is refused.
     *
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): mixed  $rule
     * @return void
     */
    #[DataProvider('widenedRuleProvider')]
    public function testRuleDoingMoreThanAddingWheresIsRefused(\Closure $rule): void
    {
        $resource = self::probeResource();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf('The row rule of %s may only add where clauses to the query', $resource));

        $this->probe($rule)->constrain(Post::query(), $resource);
    }

    /**
     * Test that a rule may call a local scope.
     *
     * @return void
     */
    public function testRuleMayCallALocalScope(): void
    {
        $model = new #[Table('posts')] class extends Model {
            /**
             * Narrow to published posts.
             *
             * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
             * @return void
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
             */
            public function scopePublished(Builder $query): void
            {
                $query->where('published', true);
            }
        };

        $query = $model->newQuery();

        $this->probe(static fn (Builder $rule): mixed => $rule->published())->constrain($query, self::probeResource()); // @phpstan-ignore method.notFound

        self::assertSame('select * from "posts" where ("published" = ?)', $query->toSql());
    }

    /**
     * Test that the documented fail-closed rule matches nothing without a
     * caller.
     *
     * @return void
     */
    public function testFailClosedRuleMatchesNothingWithoutACaller(): void
    {
        $author = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        Post::create(['user_id' => $author->id, 'title' => 'Launch', 'body' => 'Body']);

        $query = Post::query();

        $this->perimeter()->constrain($query, OwnPostResource::class);

        self::assertSame('select * from "posts" where ' . self::OWN_POSTS, $query->toSql());
        self::assertCount(0, $query->get());
    }

    /**
     * Test that a rule on a relation back to its own model qualifies its
     * columns by the alias the subquery gives the table.
     *
     * @return void
     */
    public function testSelfRelationRuleQualifiesByTheSubqueryAlias(): void
    {
        $perimeter = $this->perimeter();

        $query = Article::query()->whereHas('siblingArticles', static function (Builder $query) use ($perimeter): void {
            $perimeter->constrain($query, PublishedArticleResource::class);
        });

        self::assertMatchesRegularExpression('/ as "(laravel_reserved_\d+)" where .* and \("\1"\."status" = \?\)/', $query->toSql());
    }

    /**
     * Test that a wrapped constraint runs the existing one first, on a relation
     * or its builder, and hands it the resource's aggregate decorator.
     *
     * @return void
     */
    public function testWrapRunsTheExistingConstraintFirst(): void
    {
        $decorated = null;

        $wrapped = $this->perimeter()->wrap(static function (mixed $relation, mixed $decorate = null) use (&$decorated): void {

            assert($relation instanceof HasMany && $decorate instanceof \Closure);

            $relation->where('title', 'Launch');

            $decorated = $decorate([['relation' => 'tags as tags_count', 'column' => '*', 'function' => 'count']]);
        }, OwnPostResource::class);

        $relation = (new User)->posts();

        $wrapped($relation);

        self::assertStringEndsWith('"title" = ? and (0 = 1)', $relation->toSql());
        self::assertSame([['relation' => 'tags as tags_count', 'column' => '*', 'function' => 'count']], $decorated);

        $query = Post::query();

        $this->perimeter()->wrap(null, OwnPostResource::class)($query);

        self::assertSame('select * from "posts" where (0 = 1)', $query->toSql());
    }

    /**
     * Test that a constraint given no resource governs by the resource mapped
     * to the model it loads, and leaves an unmapped model untouched.
     *
     * @return void
     */
    public function testWrapWithoutAResourceGovernsByTheLoadedModel(): void
    {
        $posts = (new User)->posts();

        $this->perimeter([Post::class => OwnPostResource::class])->wrap(null)($posts);

        self::assertStringEndsWith('and ' . self::OWN_POSTS, $posts->toSql());

        $organization = (new User)->organization();
        $before       = $organization->toSql();

        $this->perimeter([Post::class => OwnPostResource::class])->wrap(null)($organization);

        self::assertSame($before, $organization->toSql());
    }

    /**
     * Test that a map no restricting resource governs is returned identical.
     *
     * @return void
     */
    public function testMapNoRestrictingResourceGovernsIsReturnedIdentical(): void
    {
        $with = ['posts', 'posts.tags' => static function (): void {}];

        self::assertSame($with, $this->perimeter([Post::class => PostResource::class])->decorate($with, new User));
        self::assertSame($with, $this->perimeter([Post::class => OwnPostResource::class])->decorate($with, new User, ['posts' => PostResource::class]));
        self::assertSame([], $this->perimeter([Post::class => OwnPostResource::class])->decorate([], new User));
    }

    /**
     * Test that a restricting prefix gets its own entry, wrapped, and that an
     * unrestricted path keeps its constraint.
     *
     * @return void
     */
    public function testRestrictingPrefixGetsItsOwnEntry(): void
    {
        $decorated = $this->perimeter([Post::class => OwnPostResource::class])->decorate(['posts.tags'], new User);

        self::assertSame(['posts', 'posts.tags'], array_keys($decorated));
        self::assertStringEndsWith(self::OWN_POSTS, $this->sqlAfter($decorated['posts'], (new User)->posts()));
        self::assertStringNotContainsString('user_id', $this->sqlAfter($decorated['posts.tags'], (new Post)->tags()));
    }

    /**
     * Test that an existing entry is wrapped in place, running its constraint
     * first, and no path appears twice.
     *
     * @return void
     */
    public function testExistingEntryIsWrappedInPlace(): void
    {
        $existing = static function (mixed $relation): void {

            assert($relation instanceof HasMany);

            $relation->where('title', 'Launch');
        };

        $decorated = $this->perimeter([Post::class => OwnPostResource::class])->decorate(['posts.tags', 'posts' => $existing], new User);

        self::assertSame(['posts', 'posts.tags'], array_keys($decorated));
        self::assertStringEndsWith('"title" = ? and ' . self::OWN_POSTS, $this->sqlAfter($decorated['posts'], (new User)->posts()));
    }

    /**
     * Test that a path whose own resource does not restrict is wrapped when an
     * aggregate it loads counts restricted rows, handing the constraint it was
     * given the decorator that governs those aggregates.
     *
     * @return void
     */
    public function testPathLoadingARestrictedAggregateHandsItsConstraintTheDecorator(): void
    {
        $existing = static function (mixed $relation, mixed $decorate = null): void {

            assert($relation instanceof EloquentRelation && $decorate instanceof \Closure);

            foreach ($decorate([['relation' => 'posts as posts_count', 'column' => '*', 'function' => 'count']]) as $entry) {
                $relation->getQuery()->withAggregate($entry['relation'], $entry['column'], $entry['function']);
            }
        };

        $perimeter = $this->perimeter([Post::class => OwnPostResource::class]);
        $with      = $perimeter->decorate(['tags' => $existing], new Post, ['tags' => PostTagResource::class]);

        self::assertStringContainsString(self::OWN_POSTS . ') as "posts_count"', $this->sqlAfter($with['tags'], (new Post)->tags()));
        self::assertSame(['tags' => $existing], $perimeter->decorate(['tags' => $existing], new Post, ['tags' => TagResource::class]));
    }

    /**
     * Test that a constraint run on something other than an Eloquent query is
     * left to the existing constraint alone.
     *
     * @return void
     */
    public function testConstraintOnAPlainQueryRunsOnlyTheExistingOne(): void
    {
        $query = Post::query()->toBase();

        $this->perimeter()->wrap(static fn (mixed $query): mixed => $query instanceof QueryBuilder ? $query->where('title', 'Launch') : null, OwnPostResource::class)($query);

        self::assertSame('select * from "posts" where "title" = ?', $query->toSql());
    }

    /**
     * Test that the resource declared over a path governs it rather than the
     * resource mapped to its model.
     *
     * @return void
     */
    public function testDeclaredResourceGovernsThePathOverTheMappedOne(): void
    {
        $perimeter = $this->perimeter([Post::class => OwnPostResource::class]);

        $declared = $perimeter->decorate(['posts'], new User, ['posts' => PublishedPostResource::class]);
        $mapped   = $perimeter->decorate(['publishedPosts'], new User);

        self::assertStringEndsWith('and ' . self::PUBLISHED_POSTS, $this->sqlAfter($declared['posts'], (new User)->posts()));
        self::assertStringEndsWith('and ' . self::OWN_POSTS, $this->sqlAfter($mapped['publishedPosts'], (new User)->publishedPosts()));
    }

    /**
     * Test that a relation method without a relation return type resolves its
     * resource from the model it loads.
     *
     * @return void
     */
    public function testUntypedRelationResolvesFromTheLoadedModel(): void
    {
        $model = new #[Table('users')] class extends Model {
            /**
             * Return the user's posts, without a relation return type.
             *
             * @return mixed
             */
            public function posts(): mixed
            {
                return $this->hasMany(Post::class, 'user_id');
            }
        };

        $decorated = $this->perimeter([Post::class => OwnPostResource::class])->decorate(['posts'], $model);

        $relation = $model->posts();

        assert($relation instanceof EloquentRelation);

        self::assertStringEndsWith('and ' . self::OWN_POSTS, $this->sqlAfter($decorated['posts'], $relation));
    }

    /**
     * Test that a polymorphic relation adds the rule to each restricting type
     * after the type's existing constraint, and leaves every other type alone.
     *
     * @return void
     */
    public function testPolymorphicRelationConstrainsOnlyRestrictingTypes(): void
    {
        $userConstraint = static function (Builder $query): void {
            $query->where('name', 'Alice');
        };

        $decorated = $this->perimeter([User::class => UserResource::class, Post::class => OwnPostResource::class])->decorate([
            'noteable' => static function (mixed $relation) use ($userConstraint): void {

                assert($relation instanceof MorphTo);

                $relation->constrain([Post::class => static fn (Builder $query): Builder => $query->where('title', 'Launch'), User::class => $userConstraint]);
            },
        ], new Note);

        $relation = (new Note)->noteable();

        $this->sqlAfter($decorated['noteable'], $relation);

        $constraints = $this->morphState($relation, 'morphableConstraints');

        self::assertSame($userConstraint, $constraints[User::class]);
        self::assertSame('select * from "posts" where "title" = ? and ' . self::OWN_POSTS, $this->builderAfter($constraints[Post::class], Post::query()));
    }

    /**
     * Test that a per-type constraint the relation method declares runs before
     * the rule.
     *
     * @return void
     */
    public function testPolymorphicConstraintFromTheRelationMethodRunsFirst(): void
    {
        $relation = $this->morphingNoteable();

        $this->perimeter([Post::class => OwnPostResource::class])->wrap(null)($relation);

        $constraints = $this->morphState($relation, 'morphableConstraints');

        self::assertSame('select * from "posts" where "body" = ? and ' . self::OWN_POSTS, $this->builderAfter($constraints[Post::class], Post::query()));
    }

    /**
     * Test that the relations and counts a polymorphic relation loads per type
     * are decorated for every type, so a restricting relation beneath an
     * unrestricted type is constrained.
     *
     * @return void
     */
    public function testPolymorphicPerTypeLoadsAndCountsAreDecorated(): void
    {
        $relation = $this->morphingNoteable();

        $this->perimeter([Post::class => OwnPostResource::class])->wrap(null)($relation);

        $loads  = $this->morphState($relation, 'morphableEagerLoads');
        $counts = $this->morphState($relation, 'morphableEagerLoadCounts');

        self::assertStringEndsWith('and ' . self::OWN_POSTS, $this->sqlAfter($loads[User::class]['posts'], (new User)->posts()));
        self::assertSame('select * from "posts" where ' . self::OWN_POSTS, $this->builderAfter($counts[User::class]['posts'], Post::query()));
    }

    /**
     * Test that a generic load beneath a polymorphic relation, already
     * decorated, is not decorated again for a type that also loads it.
     *
     * @return void
     */
    public function testPolymorphicGenericLoadIsNotDecoratedTwice(): void
    {
        $perimeter = $this->perimeter([Post::class => OwnPostResource::class]);
        $relation  = $this->morphingNoteable();

        $relation->getQuery()->with(['posts' => $perimeter->wrap(null)]);

        $perimeter->wrap(null)($relation);

        $sql = $this->sqlAfter($this->morphState($relation, 'morphableEagerLoads')[User::class]['posts'], (new User)->posts());

        self::assertSame(1, substr_count($sql, self::OWN_POSTS));
    }

    /**
     * Test that the per-type relations and counts given in every accepted form
     * are each decorated, and an entry naming no relation is left out.
     *
     * @return void
     */
    public function testPolymorphicPerTypeListsAreDecoratedWhole(): void
    {
        $relation = (new Note)->noteable()
            ->morphWith([User::class => 'posts', Tag::class => ['posts', 'articles']])
            ->morphWithCount([User::class => [['bogus'], 'posts', 'publishedPosts']]);

        $this->perimeter([Post::class => OwnPostResource::class])->wrap(null)($relation);

        $loads  = $this->morphState($relation, 'morphableEagerLoads');
        $counts = $this->morphState($relation, 'morphableEagerLoadCounts');

        self::assertStringEndsWith(self::OWN_POSTS, $this->sqlAfter($loads[User::class]['posts'] ?? null, (new User)->posts()));
        self::assertSame(['posts', 'articles'], array_keys((array) $loads[Tag::class]));
        self::assertSame(['posts', 'publishedPosts'], array_keys((array) $counts[User::class]));
    }

    /**
     * Test that a per-type relation replacing a generic one is decorated, as
     * the generic decoration no longer runs.
     *
     * @return void
     */
    public function testPolymorphicPerTypeRelationReplacingAGenericOneIsDecorated(): void
    {
        $perimeter = $this->perimeter([Post::class => OwnPostResource::class]);
        $relation  = (new Note)->noteable()->morphWith([User::class => ['posts' => static function (): void {}]]);

        $relation->getQuery()->with(['posts' => $perimeter->wrap(null)]);

        $perimeter->wrap(null)($relation);

        self::assertStringEndsWith(self::OWN_POSTS, $this->sqlAfter($this->morphState($relation, 'morphableEagerLoads')[User::class]['posts'] ?? null, (new User)->posts()));
    }

    /**
     * Test that a polymorphic relation leaves its own query to the types it
     * loads.
     *
     * @return void
     */
    public function testPolymorphicRelationLeavesItsOwnQueryToItsTypes(): void
    {
        $relation = (new Note)->noteable();
        $wheres   = $relation->getQuery()->getQuery()->wheres;

        $this->perimeter([Note::class => OwnPostResource::class, Post::class => OwnPostResource::class])->wrap(null)($relation);

        self::assertSame($wheres, $relation->getQuery()->getQuery()->wheres);
    }

    /**
     * Test that the polymorphic relation state the perimeter rewrites is still
     * where it reads it.
     *
     * @return void
     */
    public function testPolymorphicRelationStateIsWhereItIsRead(): void
    {
        foreach (['morphableConstraints', 'morphableEagerLoads', 'morphableEagerLoadCounts'] as $property) {
            self::assertTrue(property_exists(MorphTo::class, $property), $property);
        }
    }

    /**
     * Test that aggregates follow the resource the owner declares over the
     * relation, else the mapped one, and stay untouched when neither restricts.
     *
     * @return void
     */
    public function testAggregatesFollowTheDeclaredResourceElseTheMappedOne(): void
    {
        $perimeter = $this->perimeter([Post::class => OwnPostResource::class]);
        $launch    = static function (Builder $query): void {
            $query->where('title', 'Launch');
        };
        $entries = [
            ['relation' => 'posts as posts_count', 'column' => '*', 'function' => 'count'],
            ['relation' => ['publishedPosts as published_posts_count' => $launch], 'column' => '*', 'function' => 'count'],
        ];

        $wrapped = $perimeter->wrapAggregates($entries, PublishedPostOwnerResource::class);

        $declared = (array) $wrapped[0]['relation'];
        $mapped   = (array) $wrapped[1]['relation'];
        $own      = 'select * from "posts" where "title" = ? and ' . self::OWN_POSTS;

        self::assertSame(['column' => '*', 'function' => 'count'], array_diff_key($wrapped[0], ['relation' => true]));
        self::assertSame('select * from "posts" where ' . self::PUBLISHED_POSTS, $this->builderAfter($declared['posts as posts_count'] ?? null, Post::query()));
        self::assertSame($own, $this->builderAfter($mapped['publishedPosts as published_posts_count'] ?? null, Post::query()));

        self::assertSame($entries, $this->perimeter([Post::class => PostResource::class])->wrapAggregates($entries, UserResource::class));

        $declaredOnly = $this->perimeter([Post::class => PostResource::class])->wrapAggregates($entries, PostOwnerResource::class);

        self::assertIsArray($declaredOnly[0]['relation']);
        self::assertSame($entries[1], $declaredOnly[1]);
    }

    /**
     * Build a perimeter over the resource map, for the given caller.
     *
     * @param  array<string, string>  $resourceMap
     * @param  \Tests\Fixtures\Models\User|null  $user
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RowPerimeter
     */
    private function perimeter(array $resourceMap = [], ?User $user = null): RowPerimeter
    {
        $request = Request::create('/');

        $request->setUserResolver(static fn (): ?User => $user);

        return new RowPerimeter($request, $resourceMap);
    }

    /**
     * Build a perimeter whose probe resource runs the given rule.
     *
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): mixed  $rule
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RowPerimeter
     */
    private function probe(\Closure $rule): RowPerimeter
    {
        $request = Request::create('/');

        $request->attributes->set('rule', $rule);

        return new RowPerimeter($request, []);
    }

    /**
     * Return a resource whose rule is the one the request carries.
     *
     * @return class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>
     */
    private static function probeResource(): string
    {
        $resource = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'rule_probes';

            /**
             * Return the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(Field::scalar('id'));
            }

            /**
             * Run the rule the request carries.
             *
             * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
             * @param  \Illuminate\Http\Request  $request
             * @return void
             */
            #[\Override]
            public static function restrictRows(Builder $query, Request $request): void
            {
                $rule = $request->attributes->get('rule');

                assert($rule instanceof \Closure);

                $rule($query);
            }
        };

        return $resource::class;
    }

    /**
     * Return a note's polymorphic relation declaring a per-type constraint,
     * relation and count of its own.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<covariant \Illuminate\Database\Eloquent\Model, covariant \Illuminate\Database\Eloquent\Model>
     */
    private function morphingNoteable(): MorphTo
    {
        $note = new #[Table('notes')] class extends Model {
            /**
             * Return the model the note is attached to.
             *
             * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
             */
            public function noteable(): MorphTo
            {
                return $this->morphTo()
                    ->constrain([Post::class => static fn (Builder $query): mixed => $query->where('body', 'Body')])
                    ->morphWith([User::class => ['posts']])
                    ->morphWithCount([User::class => ['posts']]);
            }
        };

        return $note->noteable();
    }

    /**
     * Read a MorphTo's per-type map.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\MorphTo<covariant \Illuminate\Database\Eloquent\Model, covariant \Illuminate\Database\Eloquent\Model>  $relation
     * @param  string  $property
     * @return array<array-key, mixed>
     */
    private function morphState(MorphTo $relation, string $property): array
    {
        $state = (new \ReflectionProperty(MorphTo::class, $property))->getValue($relation);

        assert(is_array($state));

        return $state;
    }

    /**
     * Run the eager-load constraint on the relation and return its SQL.
     *
     * @param  mixed  $constraint
     * @param  mixed  $relation
     * @return string
     */
    private function sqlAfter(mixed $constraint, mixed $relation): string
    {
        assert($constraint instanceof \Closure && $relation instanceof EloquentRelation);

        $constraint($relation);

        return $relation->toSql();
    }

    /**
     * Run the constraint on the builder and return its SQL.
     *
     * @param  mixed  $constraint
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return string
     */
    private function builderAfter(mixed $constraint, Builder $query): string
    {
        assert($constraint instanceof \Closure);

        $constraint($query);

        return $query->toSql();
    }
}
