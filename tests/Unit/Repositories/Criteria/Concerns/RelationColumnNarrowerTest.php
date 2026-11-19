<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationColumnNarrower;
use SineMacula\ApiToolkit\Schema\ChildProjection;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SafetySetDeriver;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\NoteResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the RelationColumnNarrower eager-load decorator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RelationColumnNarrower::class)]
final class RelationColumnNarrowerTest extends TestCase
{
    /**
     * Catalogue the fixture resources and enable narrowing.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('api-toolkit.resources.narrow_columns', true);
        Config::set(ResourceDiscovery::RESOURCES_KEY, [
            ArticleResource::class,
            NoteResource::class,
            OrganizationResource::class,
            PostResource::class,
            TagResource::class,
            UserResource::class,
        ]);

        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();
    }

    /**
     * Clear the schema caches.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Test that with narrowing off the map is returned unchanged.
     *
     * @return void
     */
    public function testNarrowingOffReturnsTheMapUnchanged(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', false);

        self::assertSame(['posts', 'organization'], $this->narrower()->decorate(['posts', 'organization'], new User, UserResource::class, ['name'], ['posts' => $this->posts()]));
    }

    /**
     * Test that a has-many selects its rendered columns, its key and the
     * foreign key it matches on, each qualified.
     *
     * @return void
     */
    public function testAHasManySelectsItsRenderedColumnsAndForeignKey(): void
    {
        $with = $this->narrower()->decorate(['posts'], new User, UserResource::class, ['name'], ['posts' => $this->posts()]);

        self::assertSame(['posts'], array_keys($with));
        self::assertSame(['posts.id', 'posts.title', 'posts.user_id'], $this->columnsAfter($with['posts'], new User, 'posts'));
    }

    /**
     * Test that a planned path the map does not load is not added to it.
     *
     * @return void
     */
    public function testAPlannedPathTheMapDoesNotLoadIsNotAdded(): void
    {
        $plan = ['posts' => $this->posts(), 'notes' => new ChildProjection(NoteResource::class, ['title'], false)];

        self::assertSame([], $this->narrower()->decorate([], new User, UserResource::class, ['name'], $plan));
        self::assertSame(['notes.labels'], $this->narrower()->decorate(['notes.labels'], new User, UserResource::class, ['name'], $plan));
    }

    /**
     * Test that each recognised relation keeps the related-side columns it
     * matches on.
     *
     * @return void
     */
    public function testEachRelationKeepsTheColumnsItMatchesOn(): void
    {
        $slugged = new #[Table('posts')] class extends Model {
            /**
             * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Tests\Fixtures\Models\Organization, $this>
             */
            public function organization(): BelongsTo
            {
                return $this->belongsTo(Organization::class, 'organization_slug', 'slug');
            }
        };

        $cases = [
            [new Post, PostResource::class, 'user', new ChildProjection(UserResource::class, ['name'], false), ['users.name', 'users.id']],
            [$slugged, PostResource::class, 'organization', new ChildProjection(OrganizationResource::class, ['name'], false), ['organizations.name', 'organizations.id', 'organizations.slug']],
            [new User, UserResource::class, 'notes', new ChildProjection(NoteResource::class, ['title'], false), ['notes.title', 'notes.id', 'notes.noteable_id', 'notes.noteable_type']],
            [new Post, PostResource::class, 'tags', new ChildProjection(TagResource::class, ['name'], false), ['tags.name', 'tags.id']],
            [new Organization, OrganizationResource::class, 'posts', new ChildProjection(PostResource::class, ['title'], false), ['posts.title', 'posts.id']],
        ];

        foreach ($cases as [$root, $resource, $name, $projection, $expected]) {

            $with = $this->narrower()->decorate([$name], $root, $resource, ['id'], [$name => $projection]);

            self::assertSame($expected, $this->columnsAfter($with[$name], $root, $name), $name);
        }
    }

    /**
     * Test that a narrowed path keeps the parent-side keys of the paths planned
     * beneath it and of the eager loads its relation declares.
     *
     * @return void
     */
    public function testANarrowedPathKeepsTheKeysOfTheLoadsBeneathIt(): void
    {
        $plan = ['notes' => new ChildProjection(NoteResource::class, ['title'], false)];

        $planned  = $this->narrower()->decorate(['notes', 'notes.labels', 'notes.owner'], new User, UserResource::class, ['name'], $plan);
        $declared = $this->narrower(['notes' => static function (): Relation {

            $notes = (new User)->notes();

            $notes->getQuery()->with('owner');

            return $notes;
        }])->decorate(['notes'], new User, UserResource::class, ['name'], $plan);

        self::assertContains('notes.owner_id', $this->columnsAfter($planned['notes'], new User, 'notes'));
        self::assertContains('notes.owner', $planned);
        self::assertContains('notes.owner_id', $this->columnsAfter($declared['notes'], new User, 'notes'));
    }

    /**
     * Test that the existing constraint runs before the narrowing, and a select
     * it chose is kept.
     *
     * @return void
     */
    public function testTheExistingConstraintRunsFirstAndASelectItChoseIsKept(): void
    {
        $filtering = static function (Relation $relation): void {
            $relation->where('published', true);
        };

        $selecting = static function (Relation $relation): void {
            $relation->select(['posts.id', 'posts.user_id', 'posts.body']);
        };

        $filtered = $this->narrower()->decorate(['posts' => $filtering], new User, UserResource::class, ['name'], ['posts' => $this->posts()]);
        $selected = $this->narrower()->decorate(['posts' => $selecting], new User, UserResource::class, ['name'], ['posts' => $this->posts()]);

        $relation = $this->eagerRelation(new User, 'posts');

        self::assertInstanceOf(\Closure::class, $filtered['posts']);

        $filtered['posts']($relation);

        self::assertSame(['posts.id', 'posts.title', 'posts.user_id'], $relation->getQuery()->getQuery()->columns);
        self::assertSame('published', $relation->getQuery()->getQuery()->wheres[0]['column'] ?? null);
        self::assertSame(['posts.id', 'posts.user_id', 'posts.body'], $this->columnsAfter($selected['posts'], new User, 'posts'));
    }

    /**
     * Test that a one-of-many relation, which selects its table's wildcard, is
     * narrowed in its place.
     *
     * @return void
     */
    public function testAOneOfManyRelationIsNarrowed(): void
    {
        $root = new #[Table('users')] class extends Model {
            /**
             * @return \Illuminate\Database\Eloquent\Relations\HasOne<\Tests\Fixtures\Models\Post, $this>
             */
            public function latestPost(): HasOne
            {
                return $this->hasOne(Post::class, 'user_id')->latestOfMany();
            }
        };

        $with = $this->narrower()->decorate(['latestPost'], $root, UserResource::class, ['name'], ['latestPost' => $this->posts()]);

        self::assertSame(['posts.id', 'posts.title', 'posts.user_id'], $this->columnsAfter($with['latestPost'], $root, 'latestPost'));
    }

    /**
     * Test that a MorphTo, an opaque path and an unrecognised relation class
     * keep their entries.
     *
     * @return void
     */
    public function testMorphToOpaqueAndUnrecognisedPathsAreSkipped(): void
    {
        $opaque  = ['posts' => new ChildProjection(PostResource::class, ['id'], true), 'notes' => new ChildProjection(NoteResource::class, ['title'], false)];
        $morphTo = [
            'notes'                => new ChildProjection(NoteResource::class, ['title'], false),
            'notes.noteable'       => $this->posts(),
            'notes.noteable.owner' => new ChildProjection(UserResource::class, ['name'], false),
        ];
        $morphed      = $this->narrower()->decorate(['notes', 'notes.noteable', 'notes.noteable.owner'], new User, UserResource::class, ['name'], $morphTo);
        $unrecognised = $this->narrower(['posts' => static function (): Relation {

            $relation = self::createStub(Relation::class);
            $relation->method('getRelated')->willReturn(new Post);

            return $relation;
        }]);

        $withOpaque = $this->narrower()->decorate(['posts', 'notes'], new User, UserResource::class, ['name'], $opaque);

        self::assertSame('posts', $withOpaque[0]);
        self::assertInstanceOf(\Closure::class, $withOpaque['notes']);
        self::assertContains('notes.noteable', $morphed);
        self::assertContains('notes.noteable.owner', $morphed);
        self::assertSame(['posts'], $unrecognised->decorate(['posts'], new User, UserResource::class, ['name'], ['posts' => $this->posts()]));
    }

    /**
     * Test that a field its level cannot prove, which may read any loaded
     * relation, leaves every relation beneath that level full, while a compute
     * declaring its columns does not.
     *
     * @return void
     */
    public function testAnUnprovenFieldLeavesEveryRelationBeneathItsLevelFull(): void
    {
        $author   = new ChildProjection(UserResource::class, ['name'], false);
        $articles = ['articles' => new ChildProjection(ArticleResource::class, ['title', 'headline', 'author'], false), 'articles.author' => $author];

        $accessor = $this->narrower()->decorate(['author'], new Article, ArticleResource::class, ['title', 'headline'], ['author' => $author]);
        $nested   = $this->narrower()->decorate(['articles', 'articles.author'], new User, UserResource::class, ['name', 'articles'], $articles);
        $compute  = $this->narrower()->decorate(['posts'], new User, UserResource::class, ['name', 'full_label'], ['posts' => $this->posts()]);
        $declared = $this->narrower()->decorate(['author'], new Article, ArticleResource::class, ['title', 'summary_excerpt'], ['author' => $author]);

        self::assertSame(['author'], $accessor);
        self::assertSame(['articles', 'articles.author'], $nested);
        self::assertSame(['posts'], $compute);
        self::assertInstanceOf(\Closure::class, $declared['author'] ?? null);
    }

    /**
     * Test that a chaperoned relation anywhere in the graph leaves the whole
     * map unchanged.
     *
     * @return void
     */
    public function testAChaperoneAnywhereInTheGraphLeavesTheMapUnchanged(): void
    {
        $narrower = $this->narrower(['notes' => static fn (): Relation => (new User)->notes()->chaperone('noteable')]);

        $plan = ['posts' => $this->posts(), 'notes' => new ChildProjection(NoteResource::class, ['title'], false)];

        self::assertSame(['posts', 'notes'], $narrower->decorate(['posts', 'notes'], new User, UserResource::class, ['name'], $plan));
    }

    /**
     * Test that a chaperone a constraint adds is refused, naming the path and
     * the resource.
     *
     * @return void
     */
    public function testAChaperoneInTheConstraintIsRefused(): void
    {
        $chaperoning = static function (HasMany $posts): void {
            $posts->chaperone('user');
        };

        $with = $this->narrower()->decorate(['posts' => $chaperoning], new User, UserResource::class, ['name'], ['posts' => $this->posts()]);

        self::assertInstanceOf(\Closure::class, $with['posts']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf(
            'The posts relation rendered by %s sets an inverse in its constraint, which column narrowing cannot keep; declare chaperone() on the relation',
            PostResource::class,
        ));

        $with['posts']($this->eagerRelation(new User, 'posts'));
    }

    /**
     * Test that a child field the select cannot prove leaves the path full and
     * logs why.
     *
     * @return void
     */
    public function testAnUnprovenChildFieldLeavesThePathFullAndIsLogged(): void
    {
        Log::shouldReceive('debug')
            ->once()
            ->with('Column narrowing fell back to a full select', [
                'model'    => Article::class,
                'resource' => ArticleResource::class,
                'path'     => 'articles',
                'field'    => 'headline',
            ]);

        $plan = ['articles' => new ChildProjection(ArticleResource::class, ['id', 'headline'], false), 'posts' => $this->posts()];
        $with = $this->narrower()->decorate(['articles', 'posts'], new User, UserResource::class, ['name'], $plan);

        self::assertSame('articles', $with[0]);
        self::assertInstanceOf(\Closure::class, $with['posts']);
    }

    /**
     * Test that a load beneath the path that does not resolve leaves the path
     * full and logs the relation.
     *
     * @return void
     */
    public function testAnUnresolvableLoadBeneathThePathLeavesItFullAndIsLogged(): void
    {
        Log::shouldReceive('debug')
            ->once()
            ->with('Column narrowing fell back to a full select', [
                'model'    => Post::class,
                'resource' => PostResource::class,
                'path'     => 'posts',
                'relation' => 'missing',
            ]);

        self::assertSame(['posts', 'posts.missing'], $this->narrower()->decorate(['posts', 'posts.missing'], new User, UserResource::class, ['name'], ['posts' => $this->posts()]));
    }

    /**
     * Test that a narrowing closure captures only names, columns and the
     * constraint it wraps.
     *
     * @return void
     */
    public function testTheClosuresCaptureNoObject(): void
    {
        $with = $this->narrower()->decorate(['posts'], new User, UserResource::class, ['name'], ['posts' => $this->posts()]);

        self::assertInstanceOf(\Closure::class, $with['posts']);

        $captured = (new \ReflectionFunction($with['posts']))->getStaticVariables();

        self::assertSame(['constraint' => null, 'path' => 'posts', 'resource' => PostResource::class, 'columns' => ['posts.id', 'posts.title', 'posts.user_id']], $captured);
        self::assertNull((new \ReflectionFunction($with['posts']))->getClosureThis());
    }

    /**
     * Build the projection of posts rendering their key and title.
     *
     * @return \SineMacula\ApiToolkit\Schema\ChildProjection
     */
    private function posts(): ChildProjection
    {
        return new ChildProjection(PostResource::class, ['id', 'title'], false);
    }

    /**
     * Run the narrowed entry on a fresh eager relation and return its columns.
     *
     * @param  mixed  $entry
     * @param  \Illuminate\Database\Eloquent\Model  $root
     * @param  string  $name
     * @return array<int, mixed>
     */
    private function columnsAfter(mixed $entry, Model $root, string $name): array
    {
        self::assertInstanceOf(\Closure::class, $entry);

        $eager = $this->eagerRelation($root, $name);

        $entry($eager);

        return $eager->getQuery()->getQuery()->columns ?? [];
    }

    /**
     * Build the relation as an eager load does, from an unsaved parent.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $name
     * @return \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>
     */
    private function eagerRelation(Model $model, string $name): Relation
    {
        $relation = Relation::noConstraints(static fn (): mixed => $model->{$name}()); // @phpstan-ignore method.dynamicName

        assert($relation instanceof Relation);

        return $relation;
    }

    /**
     * Build a narrower over the migrated schema, resolving the named relations
     * through their overrides.
     *
     * @param  array<string, \Closure(): mixed>  $overrides
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationColumnNarrower
     */
    private function narrower(array $overrides = []): RelationColumnNarrower
    {
        assert($this->app !== null);

        $real = $this->app->make(SchemaIntrospectionProvider::class);

        if ($overrides === []) {
            return new RelationColumnNarrower(new SafetySetDeriver($real), $real);
        }

        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumns')->willReturnCallback($real->getColumns(...));
        $introspector->method('getDeletedAtColumn')->willReturnCallback($real->getDeletedAtColumn(...));
        $introspector->method('parentKeysFor')->willReturnCallback($real->parentKeysFor(...));
        $introspector->method('relatedKeysFor')->willReturnCallback($real->relatedKeysFor(...));
        $introspector->method('resolveRelation')->willReturnCallback(static function (string $key, Model $model) use ($overrides, $real): ?Relation {

            $relation = isset($overrides[$key]) ? $overrides[$key]() : $real->resolveRelation($key, $model);

            assert($relation === null || $relation instanceof Relation);

            return $relation;
        });

        return new RelationColumnNarrower(new SafetySetDeriver($introspector), $introspector);
    }
}
