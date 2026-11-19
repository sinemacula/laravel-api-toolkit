<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\Concerns\PivotColumns;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the PivotColumns selection and accessor lookup.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PivotColumns::class)]
final class PivotColumnsTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that clearing the cache empties the accessor memo.
     *
     * @return void
     */
    public function testClearCacheEmptiesTheAccessorMemo(): void
    {
        $this->setStaticProperty(PivotColumns::class, 'accessorCache', ['Foo' => ['bar' => 'pivot']]);

        PivotColumns::clearCache();

        self::assertSame([], $this->getStaticProperty(PivotColumns::class, 'accessorCache'));
    }

    /**
     * Test that the columns are selected on a many-to-many relation.
     *
     * @return void
     */
    public function testSelectAddsTheColumnsToAManyToManyRelation(): void
    {
        $relation = self::eagerRelation(new Post, 'tags');

        PivotColumns::select($relation, ['role', 'tagged_at'], 'PostResource::tags');

        self::assertInstanceOf(BelongsToMany::class, $relation);
        self::assertSame(['role', 'tagged_at'], $relation->getPivotColumns());
    }

    /**
     * Test that selecting columns on a relation that is not many-to-many is
     * refused, naming the field.
     *
     * @return void
     */
    public function testSelectRefusesARelationThatIsNotManyToMany(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The UserResource::posts relation declares pivot columns but is not a many-to-many relation');

        PivotColumns::select(self::eagerRelation(new User, 'posts'), ['role'], 'UserResource::posts');
    }

    /**
     * Test that the accessor is the relation's own, memoised per owner class
     * and relation.
     *
     * @return void
     */
    public function testAccessorIsTheRelationsOwnMemoisedPerOwnerAndRelation(): void
    {
        self::assertSame('tagging', PivotColumns::accessor(new Post, 'typedTags'));
        self::assertSame('pivot', PivotColumns::accessor(new Post, 'tags'));
        self::assertSame([Post::class => ['typedTags' => 'tagging', 'tags' => 'pivot']], $this->getStaticProperty(PivotColumns::class, 'accessorCache'));
    }

    /**
     * Test that a memoised accessor is honoured, so the relation is not
     * resolved again.
     *
     * @return void
     */
    public function testAMemoisedAccessorIsHonoured(): void
    {
        $this->setStaticProperty(PivotColumns::class, 'accessorCache', [Post::class => ['tags' => 'membership']]);

        self::assertSame('membership', PivotColumns::accessor(new Post, 'tags'));
    }

    /**
     * Test that a relation that is not many-to-many, one the model does not
     * declare, and an owner that is not a model have no accessor, each miss
     * memoised.
     *
     * @return void
     */
    public function testAccessorIsNullWithoutAManyToManyRelation(): void
    {
        self::assertNull(PivotColumns::accessor(new User, 'posts'));
        self::assertNull(PivotColumns::accessor(new User, 'drafts'));
        self::assertNull(PivotColumns::accessor(new \stdClass, 'tags'));
        self::assertSame(
            [User::class => ['posts' => null, 'drafts' => null], \stdClass::class => ['tags' => null]],
            $this->getStaticProperty(PivotColumns::class, 'accessorCache'),
        );
    }

    /**
     * Test that a many-to-many relation registered at runtime has its own
     * accessor.
     *
     * @return void
     */
    public function testARuntimeManyToManyRelationHasItsOwnAccessor(): void
    {
        $property = new \ReflectionProperty(Model::class, 'relationResolvers');
        $original = $property->getValue();

        try {
            Post::resolveRelationUsing('labels', static fn (Post $post): BelongsToMany => $post->belongsToMany(Tag::class, 'post_tag')->as('labelling'));

            self::assertSame('labelling', PivotColumns::accessor(new Post, 'labels'));
        } finally {
            $property->setValue(null, $original);
        }
    }

    /**
     * Build the relation as an eager load does, from an unsaved parent.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $name
     * @return \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>
     */
    private static function eagerRelation(Model $model, string $name): EloquentRelation
    {
        $relation = EloquentRelation::noConstraints(static fn (): mixed => $model->{$name}()); // @phpstan-ignore method.dynamicName

        assert($relation instanceof EloquentRelation);

        return $relation;
    }
}
