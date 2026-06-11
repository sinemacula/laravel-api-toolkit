<?php

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Repositories\Concerns\AttributeSetter;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the AttributeSetter concern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1448")
 *
 * @internal
 */
#[CoversClass(AttributeSetter::class)]
class AttributeSetterTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /** @var string */
    private const string ALICE_EMAIL = 'alice@example.com';

    /** @var \PHPUnit\Framework\MockObject\Stub&\SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider */
    private SchemaIntrospectionProvider&Stub $schemaIntrospector;

    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\AttributeSetter */
    private AttributeSetter $attributeSetter;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaIntrospector = static::createStub(SchemaIntrospectionProvider::class);
        $this->attributeSetter    = new AttributeSetter($this->schemaIntrospector);
    }

    /**
     * Test that persist delegates scalar string attributes to the
     * model's setAttribute method.
     *
     * @return void
     */
    public function testPersistDelegatesScalarToModelSetAttribute(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->attributeSetter, 'casts', ['name' => 'string']);

        $result = $this->attributeSetter->persist($user, ['name' => 'Bob'], User::class);

        static::assertTrue($result);
        static::assertSame('Bob', $user->fresh()?->name);
    }

    /**
     * Test that persist delegates integer attributes to the model's
     * setAttribute method, relying on Laravel for type coercion.
     *
     * @return void
     */
    public function testPersistSetsIntegerViaNativeSetAttribute(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->attributeSetter, 'casts', ['organization_id' => 'integer']);

        $result = $this->attributeSetter->persist($user, ['organization_id' => '5'], User::class);

        static::assertTrue($result);
        static::assertSame(5, $user->fresh()?->organization_id);
    }

    /**
     * Test that persist delegates boolean attributes to the model's
     * setAttribute method.
     *
     * @return void
     */
    public function testPersistSetsBooleanViaNativeSetAttribute(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Test', 'body' => 'Body']);

        $this->setProperty($this->attributeSetter, 'casts', ['published' => 'boolean']);

        $result = $this->attributeSetter->persist($post, ['published' => true], Post::class);

        static::assertTrue($result);
        static::assertTrue($post->fresh()?->published === true);
    }

    /**
     * Test that persist delegates array attributes to the model's
     * setAttribute method.
     *
     * @return void
     */
    public function testPersistSetsArrayViaNativeSetAttribute(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->attributeSetter, 'casts', ['name' => 'array']);

        $result = $this->attributeSetter->persist($user, ['name' => ['a', 'b']], User::class);

        static::assertTrue($result);
    }

    /**
     * Test that persist delegates enum attributes to the model's
     * setAttribute method.
     *
     * @return void
     */
    public function testPersistSetsEnumViaNativeSetAttribute(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL, 'status' => 'active']);

        $this->setProperty($this->attributeSetter, 'casts', ['status' => 'enum']);

        $result = $this->attributeSetter->persist($user, ['status' => UserStatus::BANNED], User::class);

        static::assertTrue($result);
        // @phpstan-ignore staticMethod.impossibleType
        static::assertSame(UserStatus::BANNED, $user->fresh()?->status);
    }

    /**
     * Test that persist sets a falsy value to null for object cast
     * attributes.
     *
     * @return void
     */
    public function testPersistSetsObjectWithFalsyToNull(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->attributeSetter, 'casts', ['name' => 'object']);

        $this->invokeMethod($this->attributeSetter, 'setAttribute', $user, 'name', null, 'object');

        static::assertNull($user->getAttribute('name'));
    }

    /**
     * Test that persist casts truthy values to stdClass for object
     * cast attributes.
     *
     * @return void
     */
    public function testPersistSetsObjectWithTruthyToStdClass(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->invokeMethod($this->attributeSetter, 'setAttribute', $user, 'name', ['key' => 'value'], 'object');

        static::assertInstanceOf(\stdClass::class, $user->getAttribute('name'));
    }

    /**
     * Test that persist associates a BelongsTo relation via the
     * associate method.
     *
     * @return void
     */
    public function testPersistAssociatesRelation(): void
    {
        $org  = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->attributeSetter, 'casts', ['organization' => 'associate']);

        $result = $this->attributeSetter->persist($user, ['organization' => $org->id], User::class);

        static::assertTrue($result);
        static::assertSame($org->id, $user->organization_id);
    }

    /**
     * Test that persist syncs a BelongsToMany relation after
     * saving the model.
     *
     * @return void
     */
    public function testPersistSyncsRelationAfterSave(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'T', 'body' => 'B']);
        $tag  = Tag::create(['name' => 'php']);

        $this->setProperty($this->attributeSetter, 'casts', ['tags' => 'sync']);

        $result = $this->attributeSetter->persist($post, ['tags' => [$tag->getKey()]], Post::class);

        static::assertTrue($result);

        $freshPost = $post->fresh();

        static::assertNotNull($freshPost);
        static::assertCount(1, $freshPost->tags()->get());
    }

    /**
     * Test that persist syncs a relation when passed a Collection
     * of models, plucking IDs automatically.
     *
     * @return void
     */
    public function testPersistSyncWithCollectionOfModels(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'T', 'body' => 'B']);
        $tag  = Tag::create(['name' => 'laravel']);

        $this->setProperty($this->attributeSetter, 'casts', ['tags' => 'sync']);

        $result = $this->attributeSetter->persist($post, ['tags' => collect([$tag])], Post::class);

        static::assertTrue($result);
        static::assertCount(1, $post->fresh()?->tags()->get() ?? collect([]));
    }

    /**
     * Test that persist syncs a relation when passed a plain array
     * of integer IDs.
     *
     * @return void
     */
    public function testPersistSyncWithArrayOfIds(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'T', 'body' => 'B']);
        $tag  = Tag::create(['name' => 'testing']);

        $this->setProperty($this->attributeSetter, 'casts', ['tags' => 'sync']);

        $result = $this->attributeSetter->persist($post, ['tags' => [$tag->getKey()]], Post::class);

        static::assertTrue($result);
        static::assertCount(1, $post->fresh()?->tags()->get() ?? collect([]));
    }

    /**
     * Test that persist syncs a relation when passed a single
     * Model instance, using its ID via the ArrayAccess path.
     *
     * @return void
     */
    public function testPersistSyncWithSingleModel(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'T', 'body' => 'B']);
        $tag  = Tag::create(['name' => 'single']);

        $this->setProperty($this->attributeSetter, 'casts', ['tags' => 'sync']);

        $result = $this->attributeSetter->persist($post, ['tags' => $tag], Post::class);

        static::assertTrue($result);
        static::assertCount(1, $post->fresh()?->tags()->get() ?? collect([]));
    }

    /**
     * Test that persist syncing a Collection detaches relations that
     * are not present in the new value.
     *
     * @return void
     */
    public function testPersistSyncWithCollectionDetachesExistingRelations(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'T', 'body' => 'B']);
        $old  = Tag::create(['name' => 'old']);
        $new  = Tag::create(['name' => 'new']);

        $post->tags()->attach($old->getKey());

        $this->setProperty($this->attributeSetter, 'casts', ['tags' => 'sync']);

        $result = $this->attributeSetter->persist($post, ['tags' => collect([$new])], Post::class);

        static::assertTrue($result);

        $tagNames = $post->fresh()?->tags()->pluck('name')->all();

        static::assertSame(['new'], $tagNames);
    }

    /**
     * Test that persist syncing an array of IDs detaches relations
     * that are not present in the new value.
     *
     * @return void
     */
    public function testPersistSyncWithArrayOfIdsDetachesExistingRelations(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'T', 'body' => 'B']);
        $old  = Tag::create(['name' => 'stale']);
        $new  = Tag::create(['name' => 'fresh']);

        $post->tags()->attach($old->getKey());

        $this->setProperty($this->attributeSetter, 'casts', ['tags' => 'sync']);

        $result = $this->attributeSetter->persist($post, ['tags' => [$new->getKey()]], Post::class);

        static::assertTrue($result);

        $tagNames = $post->fresh()?->tags()->pluck('name')->all();

        static::assertSame(['fresh'], $tagNames);
    }

    /**
     * Test that persist skips attributes whose cast resolves to
     * null.
     *
     * @return void
     */
    public function testPersistSkipsAttributeWithNullCast(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->schemaIntrospector->method('resolveRelation')->willReturn(null);

        $result = $this->attributeSetter->persist($user, ['unknown_field' => 'value'], User::class);

        static::assertTrue($result);
        static::assertSame('Alice', $user->fresh()?->name);
    }

    /**
     * Test that persist returns the boolean result of the model's
     * save method.
     *
     * @return void
     */
    public function testPersistReturnsSaveResult(): void
    {
        $model = static::createStub(Model::class);
        $model->method('save')->willReturn(false);
        $model->method('getCasts')->willReturn([]);

        $result = $this->attributeSetter->persist($model, [], 'App\Models\Stub');

        static::assertFalse($result);
    }

    /**
     * Test that resolveAttributeCasts loads the cast map from cache when
     * a cached value is available.
     *
     * @return void
     */
    public function testResolveAttributeCastsFromCache(): void
    {
        $cachedCasts = ['name' => 'string', 'active' => 'boolean'];
        $cacheKey    = CacheKeys::REPOSITORY_MODEL_CASTS->resolveKey([User::class]);

        Cache::memo()->rememberForever($cacheKey, fn () => $cachedCasts);

        $model = $this->createMock(Model::class);
        $model->expects(static::never())->method('getCasts');

        $this->attributeSetter->resolveAttributeCasts($model, User::class);

        $casts = $this->getProperty($this->attributeSetter, 'casts');

        static::assertSame($cachedCasts, $casts);
    }

    /**
     * Test that resolveAttributeCasts resolves casts from the model when
     * the cache is empty and stores them to cache.
     *
     * @return void
     */
    public function testResolveAttributeCastsFromModelWhenCacheEmpty(): void
    {
        $model = new User;

        Config::set('api-toolkit.repositories.cast_map', [
            'enum' => [UserStatus::class],
        ]);

        $this->attributeSetter->resolveAttributeCasts($model, User::class);

        $casts = $this->getProperty($this->attributeSetter, 'casts');

        static::assertIsArray($casts);
        static::assertArrayHasKey('status', $casts);

        $cached = Cache::memo()->get(CacheKeys::REPOSITORY_MODEL_CASTS->resolveKey([User::class]));

        static::assertSame($casts, $cached);
    }

    /**
     * Test that persist stores the resolved casts in the memo cache
     * under the model class key.
     *
     * @return void
     */
    public function testPersistStoresResolvedCastsInCache(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->attributeSetter, 'casts', ['name' => 'string']);

        $this->attributeSetter->persist($user, ['name' => 'Bob'], User::class);

        $cached = Cache::memo()->get(CacheKeys::REPOSITORY_MODEL_CASTS->resolveKey([User::class]));

        static::assertSame(['name' => 'string'], $cached);
    }

    /**
     * Test that resolveCastForAttribute returns null when both the cast
     * and the model are null.
     *
     * @return void
     */
    public function testResolveCastForAttributeWithNullCastAndModelReturnsNull(): void
    {
        $result = $this->invokeMethod($this->attributeSetter, 'resolveCastForAttribute', 'field', null, null);

        static::assertNull($result);
    }

    /**
     * Test that resolveCastForAttribute resolves the native cast key
     * from the configured cast map.
     *
     * @return void
     */
    public function testResolveCastForAttributeResolvesNativeCastFromMap(): void
    {
        Config::set('api-toolkit.repositories.cast_map', [
            'boolean' => ['bool', 'boolean'],
            'integer' => ['integer', 'int'],
        ]);

        $result = $this->invokeMethod($this->attributeSetter, 'resolveCastForAttribute', 'field', 'integer', null);

        static::assertSame('integer', $result);
    }

    /**
     * Test that resolveCastForRelation returns 'associate' for a
     * BelongsTo relation.
     *
     * @return void
     */
    public function testResolveCastForRelationReturnsCastForBelongsTo(): void
    {
        $model    = new User;
        $relation = $model->organization();

        $this->schemaIntrospector->method('resolveRelation')
            ->willReturnCallback(static fn (string $attribute, $subject) => $attribute === 'organization' && $subject === $model ? $relation : null);

        $result = $this->invokeMethod($this->attributeSetter, 'resolveCastForRelation', 'organization', $model);

        static::assertSame('associate', $result);
    }

    /**
     * Test that resolveCastForRelation returns 'associate' for a
     * MorphTo relation.
     *
     * @return void
     */
    public function testResolveCastForRelationReturnsCastForMorphTo(): void
    {
        $model    = new User;
        $relation = static::createStub(MorphTo::class);

        $this->schemaIntrospector->method('resolveRelation')
            ->willReturnCallback(static fn (string $attribute, $subject) => $attribute === 'commentable' && $subject === $model ? $relation : null);

        $result = $this->invokeMethod($this->attributeSetter, 'resolveCastForRelation', 'commentable', $model);

        static::assertSame('associate', $result);
    }

    /**
     * Test that resolveCastForRelation returns 'sync' for a
     * BelongsToMany relation.
     *
     * @return void
     */
    public function testResolveCastForRelationReturnsCastForBelongsToMany(): void
    {
        $model    = new Post;
        $relation = $model->tags();

        $this->schemaIntrospector->method('resolveRelation')
            ->willReturnCallback(static fn (string $attribute, $subject) => $attribute === 'tags' && $subject === $model ? $relation : null);

        $result = $this->invokeMethod($this->attributeSetter, 'resolveCastForRelation', 'tags', $model);

        static::assertSame('sync', $result);
    }

    /**
     * Test that resolveCastForRelation returns null when the attribute
     * is not a recognized relation.
     *
     * @return void
     */
    public function testResolveCastForRelationReturnsNullForNonRelation(): void
    {
        $model = new User;

        $this->schemaIntrospector->method('resolveRelation')
            ->willReturn(null);

        $result = $this->invokeMethod($this->attributeSetter, 'resolveCastForRelation', 'nonexistent', $model);

        static::assertNull($result);
    }

    /**
     * Test that resolveCastForRelation returns null for a relation type
     * that does not support associate or sync semantics.
     *
     * @return void
     */
    public function testResolveCastForRelationReturnsNullForUnsupportedRelationType(): void
    {
        $model    = new User;
        $relation = $model->posts();

        $this->schemaIntrospector->method('resolveRelation')
            ->willReturnCallback(static fn (string $attribute, $subject) => $attribute === 'posts' && $subject === $model ? $relation : null);

        $result = $this->invokeMethod($this->attributeSetter, 'resolveCastForRelation', 'posts', $model);

        static::assertNull($result);
    }

    /**
     * Test that resolveCastForRelation returns 'sync' for a MorphToMany
     * relation.
     *
     * @return void
     */
    public function testResolveCastForRelationReturnsCastForMorphToMany(): void
    {
        $model    = new Tag;
        $relation = $model->articles();

        $this->schemaIntrospector->method('resolveRelation')
            ->willReturnCallback(static fn (string $attribute, $subject) => $attribute === 'articles' && $subject === $model ? $relation : null);

        $result = $this->invokeMethod($this->attributeSetter, 'resolveCastForRelation', 'articles', $model);

        static::assertSame('sync', $result);
    }

    /**
     * Test that castMatchesLaravelCast returns true for an exact string
     * match.
     *
     * @return void
     */
    public function testCastMatchesLaravelCastExactMatch(): void
    {
        $result = $this->invokeMethod($this->attributeSetter, 'castMatchesLaravelCast', 'string', 'string');

        static::assertTrue($result);
    }

    /**
     * Test that castMatchesLaravelCast returns true for a wildcard
     * pattern match.
     *
     * @return void
     */
    public function testCastMatchesLaravelCastWildcardMatch(): void
    {
        $result = $this->invokeMethod($this->attributeSetter, 'castMatchesLaravelCast', 'decimal:2', 'decimal*');

        static::assertTrue($result);
    }

    /**
     * Test that castMatchesLaravelCast returns true when the Laravel
     * cast is a class and the base cast matches exactly.
     *
     * @return void
     */
    public function testCastMatchesLaravelCastClassMatch(): void
    {
        $result = $this->invokeMethod($this->attributeSetter, 'castMatchesLaravelCast', UserStatus::class, UserStatus::class);

        static::assertTrue($result);
    }

    /**
     * Test that castMatchesLaravelCast matches a class-based cast that
     * carries parameters.
     *
     * @return void
     */
    public function testCastMatchesLaravelCastClassMatchWithParameters(): void
    {
        $result = $this->invokeMethod($this->attributeSetter, 'castMatchesLaravelCast', UserStatus::class . ':foo', UserStatus::class);

        static::assertTrue($result);
    }

    /**
     * Test that castMatchesLaravelCast returns false when the Laravel
     * cast is an existing class that does not match the base cast.
     *
     * @return void
     */
    public function testCastMatchesLaravelCastClassMismatchReturnsFalse(): void
    {
        $result = $this->invokeMethod($this->attributeSetter, 'castMatchesLaravelCast', 'string', UserStatus::class);

        static::assertFalse($result);
    }

    /**
     * Test that castMatchesLaravelCast returns false when the casts do
     * not match.
     *
     * @return void
     */
    public function testCastMatchesLaravelCastNoMatch(): void
    {
        $result = $this->invokeMethod($this->attributeSetter, 'castMatchesLaravelCast', 'string', 'integer');

        static::assertFalse($result);
    }

    /**
     * Test that flush clears the cached casts, forcing re-resolution
     * on the next call.
     *
     * @return void
     */
    public function testFlushClearsCasts(): void
    {
        $model = new User;

        Config::set('api-toolkit.repositories.cast_map', [
            'enum' => [UserStatus::class],
        ]);

        $this->attributeSetter->resolveAttributeCasts($model, User::class);

        $castsBeforeFlush = $this->getProperty($this->attributeSetter, 'casts');

        static::assertNotEmpty($castsBeforeFlush);

        $this->attributeSetter->flush();

        $castsAfterFlush = $this->getProperty($this->attributeSetter, 'casts');

        static::assertSame([], $castsAfterFlush);
    }

    /**
     * Test that flush on a fresh AttributeSetter with no prior cast
     * resolution does not throw an exception.
     *
     * @return void
     */
    public function testFlushOnEmptyCastsIsHarmless(): void
    {
        $this->attributeSetter->flush();

        $casts = $this->getProperty($this->attributeSetter, 'casts');

        static::assertSame([], $casts);
    }

    /**
     * Test that resolveCastForAttribute returns 'enum' for a cast
     * string that is a valid enum class.
     *
     * @return void
     */
    public function testResolveCastForAttributeReturnsEnumForEnumClass(): void
    {
        Config::set('api-toolkit.repositories.cast_map', []);

        $result = $this->invokeMethod($this->attributeSetter, 'resolveCastForAttribute', 'status', UserStatus::class, null);

        static::assertSame('enum', $result);
    }

    /**
     * Test that resolveCastForAttribute falls back to 'string' for an
     * unrecognized cast that is not an enum.
     *
     * @return void
     */
    public function testResolveCastForAttributeFallsBackToString(): void
    {
        Config::set('api-toolkit.repositories.cast_map', []);

        $result = $this->invokeMethod($this->attributeSetter, 'resolveCastForAttribute', 'field', 'custom_type', null);

        static::assertSame('string', $result);
    }
}
