<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\RelationEdge;
use SineMacula\ApiToolkit\Schema\RelationTraversal;
use Tests\Fixtures\Models\Note;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\PublicUserResource;
use Tests\Fixtures\Resources\QueryableTagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the relation traversal resolving which resource governs a level.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RelationTraversal::class)]
#[CoversClass(RelationEdge::class)]
final class RelationTraversalTest extends TestCase
{
    /**
     * Test that a relation is governed by the resource declared over it, not
     * the related model's mapped resource.
     *
     * @return void
     */
    public function testDeclaredResourceGovernsOverTheMappedOne(): void
    {
        $schema = new CompiledSchema([], [], [], [], ['user'], [], ['user' => PublicUserResource::class]);

        self::assertSame(PublicUserResource::class, $this->traversal()->governingResource($schema, 'user', new User, false));
    }

    /**
     * Test that a relation declaring no resource is governed by the related
     * model's mapped resource.
     *
     * @return void
     */
    public function testMappedResourceGovernsAnAccessorOnlyRelation(): void
    {
        $schema = new CompiledSchema([], [], [], [], ['user']);

        self::assertSame(UserResource::class, $this->traversal()->governingResource($schema, 'user', new User, false));
    }

    /**
     * Test that a polymorphic descent is governed by each concrete type's
     * mapped resource, whatever the relation declares.
     *
     * @return void
     */
    public function testPolymorphicDescentIsGovernedPerType(): void
    {
        $schema    = new CompiledSchema([], [], [], [], ['noteable'], [], ['noteable' => PublicUserResource::class]);
        $traversal = $this->traversal();

        self::assertSame(UserResource::class, $traversal->governingResource($schema, 'noteable', new User, true));
        self::assertSame(PostResource::class, $traversal->governingResource($schema, 'noteable', new Post, true));
        self::assertNull($traversal->governingResource($schema, 'noteable', new Note, true));
    }

    /**
     * Test that no resource governs an unmapped level, nor one whose declared
     * or mapped class is not a resource.
     *
     * @return void
     */
    public function testNothingGovernsAnUnmappedOrNonResourceLevel(): void
    {
        $schema    = new CompiledSchema([], [], [], [], ['tags', 'user'], [], ['user' => \stdClass::class]);
        $traversal = new RelationTraversal(app(SchemaIntrospectionProvider::class), [Tag::class => \stdClass::class, User::class => UserResource::class]);

        self::assertNull($traversal->governingResource($schema, 'tags', new Tag, false));
        self::assertNull($traversal->governingResource($schema, 'tags', new Post, false));
        self::assertNull($traversal->governingResource($schema, 'user', new User, false));
    }

    /**
     * Test that each traversable relation resolves to its related model, its
     * polymorphism and the resource governing it, skipping one the model does
     * not define.
     *
     * @return void
     */
    public function testEdgesResolveEachTraversableRelationTheModelDefines(): void
    {
        $notes = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'traversal_notes';

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Relation::to('owner', PublicUserResource::class)->traversable(),
                    Relation::to('ghost', 'id', 'ghost_key')->traversable(),
                    Relation::to('noteable', 'id', 'noteable_key')->traversable(),
                    Relation::to('slug', 'id', 'slug_key')->traversable(),
                    Relation::to('labels', 'name', 'label_names')->traversable(),
                );
            }
        };

        self::assertEquals([
            new RelationEdge('owner', User::class, false, PublicUserResource::class),
            new RelationEdge('noteable', Note::class, true, null),
            new RelationEdge('labels', Tag::class, false, QueryableTagResource::class),
        ], $this->traversal()->edges($notes::class, Note::class));
    }

    /**
     * Test that a class that is no model reaches nothing.
     *
     * @return void
     */
    public function testAClassThatIsNoModelHasNoEdges(): void
    {
        self::assertSame([], $this->traversal()->edges(UserResource::class, \stdClass::class));
    }

    /**
     * Build a traversal over the fixture resource map.
     *
     * @return \SineMacula\ApiToolkit\Schema\RelationTraversal
     */
    private function traversal(): RelationTraversal
    {
        return new RelationTraversal(app(SchemaIntrospectionProvider::class), [
            User::class => UserResource::class,
            Post::class => PostResource::class,
            Tag::class  => QueryableTagResource::class,
        ]);
    }
}
