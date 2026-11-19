<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\TrashedState;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SoftDeleteVisibilityApplier;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Comment;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\CommentResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the RelationTrashedGate.
 *
 * @phpstan-import-type AggregateLoad from \SineMacula\ApiToolkit\Contracts\ApiResourceInterface
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RelationTrashedGate::class)]
#[CoversClass(SoftDeleteVisibilityApplier::class)]
final class RelationTrashedGateTest extends TestCase
{
    /**
     * Test that the None state returns the eager-load map unchanged.
     *
     * @return void
     */
    public function testNoneStateReturnsMapUnchanged(): void
    {
        $with = ['articles', 'comments' => static fn (): null => null];

        $result = $this->gate()->decorate($with, new User, TrashedState::NONE);

        self::assertSame($with, $result);
    }

    /**
     * Test that an unresolvable relation is left with its default scope.
     *
     * @return void
     */
    public function testUnresolvableRelationLeftUntouched(): void
    {
        $result = $this->gate()->decorate(['ghost'], new User, TrashedState::WITH);

        self::assertSame(['ghost'], $result);
    }

    /**
     * Test that a relation to a non-soft-deleting model keeps its scope.
     *
     * @return void
     */
    public function testNonSoftDeleteRelationLeftUntouched(): void
    {
        $result = $this->gate()->decorate(['author'], new Article, TrashedState::WITH);

        self::assertSame(['author'], $result);
    }

    /**
     * Test that a relation whose resource has not opted in keeps its scope.
     *
     * @return void
     */
    public function testClosedChildGateLeftUntouched(): void
    {
        $result = $this->gate()->decorate(['comments'], new User, TrashedState::WITH);

        self::assertSame(['comments'], $result);
    }

    /**
     * Test that a relation to a model bound to no resource keeps its scope.
     *
     * @return void
     */
    public function testUnmappedLeafModelLeftUntouched(): void
    {
        $result = $this->gateFor([User::class => UserResource::class])->decorate(['articles'], new User, TrashedState::WITH);

        self::assertSame(['articles'], $result);
    }

    /**
     * Test that a relation bound to a non-resource class keeps its scope.
     *
     * @return void
     */
    public function testLeafModelBoundToANonResourceLeftUntouched(): void
    {
        $result = $this->gateFor([Article::class => \stdClass::class])->decorate(['articles'], new User, TrashedState::WITH);

        self::assertSame(['articles'], $result);
    }

    /**
     * Test that an opted-in relation widens the scope to include trashed rows.
     *
     * @return void
     */
    public function testOpenGateWrapsWithTrashed(): void
    {
        $result = $this->gate()->decorate(['articles'], new User, TrashedState::WITH);

        self::assertArrayHasKey('articles', $result);
        self::assertInstanceOf(\Closure::class, $result['articles']);
        self::assertStringNotContainsString('deleted_at', $this->sqlAfter($result['articles']));
    }

    /**
     * Test that an opted-in relation under Only isolates trashed rows.
     *
     * @return void
     */
    public function testOpenGateWrapsOnlyTrashed(): void
    {
        $result = $this->gate()->decorate(['articles'], new User, TrashedState::ONLY);

        self::assertInstanceOf(\Closure::class, $result['articles']);
        self::assertStringContainsString('is not null', $this->sqlAfter($result['articles']));
    }

    /**
     * Test that an opted-in relation under Only keeps an OR-joined constraint
     * apart from the trashed condition.
     *
     * @return void
     */
    public function testOnlyTrashedStaysOutsideAnOrJoinedConstraint(): void
    {
        $existing = static function (Builder $query): void {
            $query->where('status', 'published')->orWhere('views', '>', 5);
        };

        $result = $this->gate()->decorate(['articles' => $existing], new User, TrashedState::ONLY);

        self::assertSame('select * from "articles" where ("status" = ? or "views" > ?) and "articles"."deleted_at" is not null', $this->sqlAfter($result['articles']));
    }

    /**
     * Test that an existing relation constraint is composed with the gate.
     *
     * @return void
     */
    public function testExistingConstraintPreservedWhenGateOpen(): void
    {
        $existing = static function (Builder $query): void {
            $query->where('status', 'published');
        };

        $result = $this->gate()->decorate(['articles' => $existing], new User, TrashedState::WITH);

        $sql = $this->sqlAfter($result['articles']);

        self::assertStringContainsString('status', $sql);
        self::assertStringNotContainsString('deleted_at', $sql);
    }

    /**
     * Test that a nested relation path resolves the gate of its leaf.
     *
     * @return void
     */
    public function testNestedPathOpenLeafGateWrapsWithTrashed(): void
    {
        $result = $this->gate()->decorate(['author.articles'], new Article, TrashedState::WITH);

        self::assertArrayHasKey('author.articles', $result);
        self::assertInstanceOf(\Closure::class, $result['author.articles']);
        self::assertStringNotContainsString('deleted_at', $this->sqlAfter($result['author.articles']));
    }

    /**
     * Test that a nested relation path with a closed leaf keeps its scope.
     *
     * @return void
     */
    public function testNestedPathClosedLeafGateLeftUntouched(): void
    {
        $result = $this->gate()->decorate(['author.comments'], new Article, TrashedState::WITH);

        self::assertSame(['author.comments'], $result);
    }

    /**
     * Test that a closed gate preserves a user constraint and never widens.
     *
     * @return void
     */
    public function testClosedGateWithConstraintKeepsScopeNarrow(): void
    {
        $existing = static function (Builder $query): void {
            $query->where('status', 'published');
        };

        $result = $this->gate()->decorate(['comments' => $existing], new User, TrashedState::WITH);

        $sql = $this->sqlAfter($result['comments']);

        self::assertStringContainsString('status', $sql);
        self::assertStringContainsString('is null', $sql);
    }

    /**
     * Test that a constraint is handed the aggregate decorator even where its
     * own relation stays live, so the aggregates it loads are gated.
     *
     * @return void
     */
    public function testConstraintOfALiveRelationIsHandedTheAggregateDecorator(): void
    {
        $existing = static function (Builder $query, \Closure $decorate): void {
            foreach ($decorate([self::countEntry('siblingArticles as siblings_count')]) as $entry) {
                $query->withAggregate($entry['relation'], $entry['column'], $entry['function']);
            }
        };

        $result = $this->gate()->decorate(['comments' => $existing], new User, TrashedState::WITH);

        $sql = $this->sqlAfter($result['comments']);

        self::assertStringContainsString('as "siblings_count"', $sql);
        self::assertSame(1, substr_count($sql, 'deleted_at'));
    }

    /**
     * Test that the None state returns the aggregate entries unchanged.
     *
     * @return void
     */
    public function testNoneStateReturnsAggregatesUnchanged(): void
    {
        $entries = [self::countEntry('articles as articles_count'), self::countEntry('comments as comments_count')];

        self::assertSame($entries, $this->gate()->decorateAggregates($entries, TrashedState::NONE));
    }

    /**
     * Provide aggregate relations under each state with the subquery each
     * compiles to.
     *
     * @return iterable<string, array{AggregateLoad, \SineMacula\ApiToolkit\Enums\TrashedState, string}>
     */
    public static function aggregateEntryProvider(): iterable
    {
        $articles  = '"articles" where "users"."id" = "articles"."user_id"';
        $comments  = '"comments" where "users"."id" = "comments"."user_id"';
        $published = static function (Builder $query): void {
            $query->where('status', 'published');
        };
        $either = static function (Builder $query): void {
            $query->where('status', 'published')->orWhere('views', '>', 5);
        };

        yield 'opted in, with trashed' => [self::countEntry('articles as total'), TrashedState::WITH, $articles . ') as "total"'];
        yield 'opted in, only trashed' => [self::countEntry('articles as total'), TrashedState::ONLY, $articles . ' and "articles"."deleted_at" is not null) as "total"'];
        yield 'opted in, constrained' => [self::countEntry(['articles as published' => $published]), TrashedState::WITH, $articles . ' and "status" = ?) as "published"'];
        yield 'opted in, or-joined' => [
            self::countEntry(['articles as either' => $either]),
            TrashedState::ONLY,
            $articles . ' and ("status" = ? or "views" > ?) and "articles"."deleted_at" is not null) as "either"',
        ];
        yield 'closed, with trashed' => [self::countEntry('comments as total'), TrashedState::WITH, $comments . ' and "comments"."deleted_at" is null) as "total"'];
    }

    /**
     * Test that an aggregate subquery follows the state only where its relation
     * opts in, keeping its alias and constraint.
     *
     * @param  AggregateLoad  $entry
     * @param  \SineMacula\ApiToolkit\Enums\TrashedState  $state
     * @param  string  $subquery
     * @return void
     */
    #[DataProvider('aggregateEntryProvider')]
    public function testAggregateSubqueryFollowsTheStateWhereItsRelationOptsIn(array $entry, TrashedState $state, string $subquery): void
    {
        [$entry] = $this->gate()->decorateAggregates([$entry], $state);

        $query = User::query()->withAggregate($entry['relation'], $entry['column'], $entry['function']);

        self::assertSame('select "users".*, (select count(*) from ' . $subquery . ' from "users"', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that Only still narrows a relation declared with its trashed rows,
     * whose removed scope Eloquent strips again when it merges the relation.
     *
     * @return void
     */
    public function testOnlyTrashedNarrowsARelationDeclaredWithTrashedRows(): void
    {
        $user = new #[Table('users')] class extends Model {
            /**
             * Return every article of the user, trashed ones included.
             *
             * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Article, $this>
             */
            public function everyArticle(): HasMany
            {
                return $this->hasMany(Article::class, 'user_id')->withTrashed(); // @phpstan-ignore method.notFound
            }
        };

        $gate     = $this->gate();
        [$entry]  = $gate->decorateAggregates([self::countEntry('everyArticle as total')], TrashedState::ONLY);
        $subquery = 'from "articles" where "users"."id" = "articles"."user_id" and "articles"."deleted_at" is not null)';

        $counted  = $user->newQuery()->withAggregate($entry['relation'], $entry['column'], $entry['function']);
        $filtered = $user->newQuery()->whereHas('everyArticle', static fn (Builder $query) => $gate->constrain($query, TrashedState::ONLY));

        self::assertSame('select "users".*, (select count(*) ' . $subquery . ' as "total" from "users"', $counted->toSql());
        self::assertSame('select * from "users" where exists (select * ' . $subquery, $filtered->toSql());
    }

    /**
     * Provide related models under each state with the SQL each compiles to.
     *
     * @return iterable<string, array{class-string<\Illuminate\Database\Eloquent\Model>, \SineMacula\ApiToolkit\Enums\TrashedState, string}>
     */
    public static function constrainedSubqueryProvider(): iterable
    {
        yield 'opted in, live only' => [Article::class, TrashedState::NONE, 'select * from "articles" where "articles"."deleted_at" is null'];
        yield 'opted in, with trashed' => [Article::class, TrashedState::WITH, 'select * from "articles"'];
        yield 'opted in, only trashed' => [Article::class, TrashedState::ONLY, 'select * from "articles" where "articles"."deleted_at" is not null'];
        yield 'closed, with trashed' => [Comment::class, TrashedState::WITH, 'select * from "comments" where "comments"."deleted_at" is null'];
    }

    /**
     * Test that a relation subquery takes the state only where its related
     * model opts in.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  \SineMacula\ApiToolkit\Enums\TrashedState  $state
     * @param  string  $sql
     * @return void
     */
    #[DataProvider('constrainedSubqueryProvider')]
    public function testConstrainAppliesTheStateWhereTheRelatedModelOptsIn(string $model, TrashedState $state, string $sql): void
    {
        $query = $model::query();

        $this->gate()->constrain($query, $state);

        self::assertSame($sql, $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that a gated constraint leaves a non-Eloquent query untouched.
     *
     * @return void
     */
    public function testGatedConstraintLeavesANonEloquentQueryUntouched(): void
    {
        $result     = $this->gate()->decorate(['articles'], new User, TrashedState::WITH);
        $constraint = $result['articles'];

        assert($constraint instanceof \Closure);

        $query = DB::table('articles');

        $constraint($query);

        self::assertSame('select * from "articles"', $query->toSql());
        self::assertSame([], $query->getBindings());
    }

    /**
     * Test that a gated constraint runs the given constraint, then widens the
     * related rows where the related resource opts in.
     *
     * @return void
     */
    public function testGatedConstraintRunsTheConstraintThenTheGatedScope(): void
    {
        $constraint = static function (Builder $query): void {
            $query->where('status', 'live');
        };

        $live    = $this->sqlAfter($this->gate()->gatedConstraint($constraint, TrashedState::NONE));
        $widened = $this->sqlAfter($this->gate()->gatedConstraint($constraint, TrashedState::WITH));

        self::assertSame('select * from "articles" where "status" = ? and "articles"."deleted_at" is null', $live);
        self::assertSame('select * from "articles" where "status" = ?', $widened);
        self::assertSame('select * from "articles"', $this->sqlAfter($this->gate()->gatedConstraint(null, TrashedState::WITH)));
    }

    /**
     * Test that one trashed request widens both the root and its relation.
     *
     * @return void
     */
    public function testRootAndRelationWidenTogether(): void
    {
        ApiQuery::parse(Request::create('/test', 'GET', ['trashed' => 'with']));

        $root = Article::query();

        (new SoftDeleteVisibilityApplier)->apply($root, ArticleResource::class, Request::create('/test', 'GET'));

        $result = $this->gate()->decorate(['author.articles'], new Article, TrashedState::WITH);

        self::assertStringNotContainsString('deleted_at', $root->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertInstanceOf(\Closure::class, $result['author.articles']);
        self::assertStringNotContainsString('deleted_at', $this->sqlAfter($result['author.articles']));
    }

    /**
     * Build a count aggregate entry over the given relation.
     *
     * @param  AggregateLoad['relation']  $relation
     * @return AggregateLoad
     */
    private static function countEntry(array|string $relation): array
    {
        return ['relation' => $relation, 'column' => '*', 'function' => 'count'];
    }

    /**
     * Build a relation trashed gate wired to the fixture resource map.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate
     */
    private function gate(): RelationTrashedGate
    {
        return $this->gateFor([
            Article::class => ArticleResource::class,
            Comment::class => CommentResource::class,
            User::class    => UserResource::class,
        ]);
    }

    /**
     * Build a relation trashed gate wired to the given resource map.
     *
     * @param  array<string, mixed>  $resourceMap
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate
     */
    private function gateFor(array $resourceMap): RelationTrashedGate
    {
        assert($this->app !== null);

        return new RelationTrashedGate(
            $this->app->make(SchemaIntrospectionProvider::class),
            new Request,
            $resourceMap,
        );
    }

    /**
     * Apply the constraint to a fresh article query and return the SQL.
     *
     * @param  mixed  $constraint
     * @return string
     */
    private function sqlAfter(mixed $constraint): string
    {
        assert($constraint instanceof \Closure);

        $query = Article::query();

        $constraint($query);

        return $query->toSql(); // @phpstan-ignore staticMethod.dynamicCall
    }
}
