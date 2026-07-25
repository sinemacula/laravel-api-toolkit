<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\TrashedState;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate;
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
     * Build a relation trashed gate wired to the fixture resource map.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate
     */
    private function gate(): RelationTrashedGate
    {
        assert($this->app !== null);

        return new RelationTrashedGate(
            $this->app->make(SchemaIntrospectionProvider::class),
            new Request,
            [
                Article::class => ArticleResource::class,
                Comment::class => CommentResource::class,
                User::class    => UserResource::class,
            ],
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
