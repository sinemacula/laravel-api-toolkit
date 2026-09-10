<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
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
     * Test that a relation whose leaf model does not use SoftDeletes is left
     * with its default scope.
     *
     * @return void
     */
    public function testNonSoftDeleteRelationLeftUntouched(): void
    {
        $result = $this->gate()->decorate(['author'], new Article, TrashedState::WITH);

        self::assertSame(['author'], $result);
    }

    /**
     * Test that a relation whose child resource has not opted in is left with
     * its default scope, even when the request asks for trashed rows.
     *
     * @return void
     */
    public function testClosedChildGateLeftUntouched(): void
    {
        $result = $this->gate()->decorate(['comments'], new User, TrashedState::WITH);

        self::assertSame(['comments'], $result);
    }

    /**
     * Test that a soft-deleting relation whose leaf model is bound to no
     * resource is left with its default scope, since the gate has nowhere to
     * read an opt-in from.
     *
     * @return void
     */
    public function testUnmappedLeafModelLeftUntouched(): void
    {
        $result = $this->gateFor([User::class => UserResource::class])->decorate(['articles'], new User, TrashedState::WITH);

        self::assertSame(['articles'], $result);
    }

    /**
     * Test that a soft-deleting relation whose leaf model is bound to a class
     * that is not an API resource is left with its default scope, rather than
     * having an opt-in read off a class that cannot answer for one.
     *
     * @return void
     */
    public function testLeafModelBoundToANonResourceLeftUntouched(): void
    {
        $result = $this->gateFor([Article::class => \stdClass::class])->decorate(['articles'], new User, TrashedState::WITH);

        self::assertSame(['articles'], $result);
    }

    /**
     * Test that an opted-in relation is wrapped in a constraint that widens the
     * scope to include trashed rows.
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
     * Test that an opted-in relation under the Only state is wrapped in a
     * constraint that isolates trashed rows.
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
     * Test that an existing relation constraint is preserved and composed with
     * the gated scope when the gate is open.
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
     * Test that a nested multi-segment relation path resolves its leaf gate, so
     * an opted-in leaf reached through several segments still widens the scope
     * to include trashed rows.
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
     * Test that a nested multi-segment relation path whose leaf resource has
     * not opted in is left with its default live-only scope, even under a
     * trashed request.
     *
     * @return void
     */
    public function testNestedPathClosedLeafGateLeftUntouched(): void
    {
        $result = $this->gate()->decorate(['author.comments'], new Article, TrashedState::WITH);

        self::assertSame(['author.comments'], $result);
    }

    /**
     * Test that a closed leaf gate wins over a user-supplied constraint under a
     * trashed request: the constraint is preserved verbatim and the scope is
     * never widened.
     *
     * @return void
     */
    public function testClosedGateWithConstraintKeepsScopeNarrow(): void
    {
        $existing = static function (Builder $query): void {
            $query->where('status', 'published');
        };

        $result = $this->gate()->decorate(['comments' => $existing], new User, TrashedState::WITH);

        self::assertSame($existing, $result['comments']);

        $sql = $this->sqlAfter($result['comments']);

        self::assertStringContainsString('status', $sql);
        self::assertStringContainsString('is null', $sql);
    }

    /**
     * Test that a gated constraint handed a query that is not an Eloquent
     * builder leaves it exactly as it found it, rather than reaching for a
     * soft-delete scope that only an Eloquent builder answers to.
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
     * Test that a single trashed request widens both a soft-deleting root that
     * opts in and its opted-in eager-loaded relation together.
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
     * Apply the given constraint closure to a fresh article query and return
     * the resulting SQL.
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
