<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Resources\Concerns\RelationWindow;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the RelationWindow constraint guard and per-parent bound.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RelationWindow::class)]
final class RelationWindowTest extends TestCase
{
    /**
     * Test that the constraint receives the relation itself.
     *
     * @return void
     */
    public function testConstraintReceivesTheRelation(): void
    {
        $received = null;
        $relation = $this->eagerRelation(new User, 'posts');

        RelationWindow::constrain($relation, static function ($query) use (&$received): void {
            $received = $query;
        }, 'Owner::posts');

        self::assertInstanceOf(HasMany::class, $received);
        self::assertSame($relation, $received);
    }

    /**
     * Test that a constraint can reach the pivot clauses of a many-to-many.
     *
     * @return void
     */
    public function testConstraintReachesPivotClauses(): void
    {
        $relation = $this->eagerRelation(new Post, 'tags');

        RelationWindow::constrain($relation, static function ($query): void {
            assert($query instanceof BelongsToMany);
            $query->wherePivot('tag_id', 7);
        }, 'Owner::tags');

        self::assertContains('post_tag.tag_id', array_column($relation->getQuery()->getQuery()->wheres, 'column'));
    }

    /**
     * Provide constraints that bound the relation they are given.
     *
     * @return iterable<string, array{0: \Closure(mixed): void, 1: string}>
     */
    public static function boundingConstraintProvider(): iterable
    {
        $limit = static function ($query): void {
            $query->limit(5);
        };

        yield 'limit on a has-many' => [$limit, 'posts'];
        yield 'take on a has-many' => [static function ($query): void {
            $query->take(5);
        }, 'posts'];
        yield 'skip on a has-many' => [static function ($query): void {
            $query->skip(5);
        }, 'posts'];
        yield 'limit on a morph-to' => [$limit, 'subject'];
        yield 'limit on a union' => [static function ($query): void {
            $query->getQuery()->union(DB::table('posts'))->limit(5);
        }, 'posts'];
        yield 'offset on a union' => [static function ($query): void {
            $query->getQuery()->union(DB::table('posts'))->offset(5);
        }, 'posts'];
    }

    /**
     * Test that a constraint bounding the relation is refused, naming its
     * owner.
     *
     * @param  \Closure(mixed): void  $constraint
     * @param  string  $relationName
     * @return void
     */
    #[DataProvider('boundingConstraintProvider')]
    public function testConstraintThatBoundsTheRelationIsRefused(\Closure $constraint, string $relationName): void
    {
        $relation = $relationName === 'subject'
            ? $this->eagerRelation(self::morphingModel(), 'subject')
            : $this->eagerRelation(new User, $relationName);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The constraint on Owner::field may not limit or offset the relation; declare limit() on the relation instead');

        RelationWindow::constrain($relation, $constraint, 'Owner::field');
    }

    /**
     * Test that a bound the relation method declares is not blamed on the
     * constraint, and a tighter one is kept.
     *
     * @return void
     */
    public function testBoundDeclaredByTheRelationMethodIsKeptAndNotBlamed(): void
    {
        $relation = $this->eagerRelation(self::cappedModel(), 'cappedPosts');

        RelationWindow::constrain($relation, static function ($query): void {
            $query->where('published', true);
        }, 'Owner::posts');
        RelationWindow::bound($relation, 5);

        self::assertSame(3, $relation->getQuery()->getQuery()->groupLimit['value'] ?? null);
    }

    /**
     * Test that a looser bound the relation method declares is tightened to the
     * limit and its probe row.
     *
     * @return void
     */
    public function testLooserBoundDeclaredByTheRelationMethodIsTightened(): void
    {
        $relation = $this->eagerRelation(self::cappedModel(), 'cappedPosts');

        RelationWindow::bound($relation, 1);

        self::assertSame(2, $relation->getQuery()->getQuery()->groupLimit['value'] ?? null);
    }

    /**
     * Test that a bound applies per parent with a probe row, ordered last by
     * the related key.
     *
     * @return void
     */
    public function testBoundAppliesPerParentWithAProbeRowAndKeyOrder(): void
    {
        $relation = $this->eagerRelation(new User, 'posts');

        RelationWindow::constrain($relation, static function ($query): void {
            $query->latest();
        }, 'Owner::posts');
        RelationWindow::bound($relation, 5);

        self::assertSame(['value' => 6, 'column' => 'posts.user_id'], $relation->getQuery()->getQuery()->groupLimit);
        self::assertSame([
            ['column' => 'created_at', 'direction' => 'desc'],
            ['column' => 'posts.id', 'direction' => 'asc'],
        ], $relation->getQuery()->toBase()->orders);
    }

    /**
     * Test that the key order survives the soft-delete scope being removed.
     *
     * @return void
     */
    public function testKeyOrderSurvivesWithTrashed(): void
    {
        $relation = $this->eagerRelation(new User, 'comments');

        RelationWindow::bound($relation, 2);

        $relation->getQuery()->withTrashed(); // @phpstan-ignore method.notFound

        self::assertSame([['column' => 'comments.id', 'direction' => 'asc']], $relation->getQuery()->toBase()->orders);
        self::assertSame(3, $relation->getQuery()->getQuery()->groupLimit['value'] ?? null);
    }

    /**
     * Test that a to-one relation is never bounded or ordered.
     *
     * @return void
     */
    public function testToOneRelationIsNeitherBoundedNorOrdered(): void
    {
        $relation = $this->eagerRelation(new User, 'organization');

        RelationWindow::bound($relation, 5);

        self::assertNull($relation->getQuery()->getQuery()->groupLimit);
        self::assertNull($relation->getQuery()->getQuery()->limit);
        self::assertSame([], $relation->getQuery()->toBase()->orders ?? []);
    }

    /**
     * Test that a zero limit adds no order and no bound.
     *
     * @return void
     */
    public function testZeroLimitAddsNoOrderAndNoBound(): void
    {
        $relation = $this->eagerRelation(new User, 'posts');

        RelationWindow::bound($relation, 0);

        self::assertNull($relation->getQuery()->getQuery()->groupLimit);
        self::assertNull($relation->getQuery()->getQuery()->limit);
        self::assertSame([], $relation->getQuery()->toBase()->orders ?? []);
    }

    /**
     * Build the relation as an eager load does, from an unsaved parent.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $name
     * @return \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>
     */
    private function eagerRelation(Model $model, string $name): EloquentRelation
    {
        $relation = EloquentRelation::noConstraints(static fn (): mixed => $model->{$name}()); // @phpstan-ignore method.dynamicName

        assert($relation instanceof EloquentRelation);

        return $relation;
    }

    /**
     * Build a model whose relation method declares its own bound of three.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    private static function cappedModel(): Model
    {
        return new #[Table('users')] class extends Model {
            /**
             * Get at most three of the user's posts.
             *
             * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\Post, $this>
             */
            public function cappedPosts(): HasMany
            {
                return $this->hasMany(Post::class, 'user_id')->limit(3);
            }
        };
    }

    /**
     * Build a model with a polymorphic to-one relation.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    private static function morphingModel(): Model
    {
        return new #[Table('comments')] class extends Model {
            /**
             * Get the commented subject.
             *
             * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
             */
            public function subject(): MorphTo
            {
                return $this->morphTo();
            }
        };
    }
}
