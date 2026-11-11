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

        self::assertSame($existing, $result['comments']);

        $sql = $this->sqlAfter($result['comments']);

        self::assertStringContainsString('status', $sql);
        self::assertStringContainsString('is null', $sql);
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
