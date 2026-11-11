<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Repositories\Concerns\AttributeSetter;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Enums\CastablePlatform;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\Country;
use Tests\Fixtures\Models\Device;
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
final class AttributeSetterTest extends TestCase
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

        assert($this->app !== null);

        $this->schemaIntrospector = self::createStub(SchemaIntrospectionProvider::class);
        $this->attributeSetter    = new AttributeSetter($this->schemaIntrospector, $this->app->make(MetadataCacheWriter::class));
    }

    /**
     * Test that persist delegates string attributes to setAttribute.
     *
     * @return void
     */
    public function testPersistDelegatesScalarToModelSetAttribute(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->attributeSetter, 'casts', ['name' => 'string']);

        $result = $this->attributeSetter->persist($user, ['name' => 'Bob'], User::class);

        self::assertTrue($result);
        self::assertSame('Bob', $user->fresh()?->name);
    }

    /**
     * Test that persist delegates integer attributes to setAttribute.
     *
     * @return void
     */
    public function testPersistSetsIntegerViaNativeSetAttribute(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->attributeSetter, 'casts', ['organization_id' => 'integer']);

        $result = $this->attributeSetter->persist($user, ['organization_id' => '5'], User::class);

        self::assertTrue($result);
        self::assertSame(5, $user->fresh()?->organization_id);
    }

    /**
     * Test that persist delegates boolean attributes to setAttribute.
     *
     * @return void
     */
    public function testPersistSetsBooleanViaNativeSetAttribute(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Test', 'body' => 'Body']);

        $this->setProperty($this->attributeSetter, 'casts', ['published' => 'boolean']);

        $result = $this->attributeSetter->persist($post, ['published' => true], Post::class);

        self::assertTrue($result);
        self::assertTrue($post->fresh()?->published === true);
    }

    /**
     * Test that persist delegates array attributes to setAttribute.
     *
     * @return void
     */
    public function testPersistSetsArrayViaNativeSetAttribute(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->attributeSetter, 'casts', ['name' => 'array']);

        $result = $this->attributeSetter->persist($user, ['name' => ['a', 'b']], User::class);

        self::assertTrue($result);
    }

    /**
     * Test that persist delegates enum attributes to setAttribute.
     *
     * @return void
     */
    public function testPersistSetsEnumViaNativeSetAttribute(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL, 'status' => 'active']);

        $this->setProperty($this->attributeSetter, 'casts', ['status' => 'enum']);

        $result = $this->attributeSetter->persist($user, ['status' => UserStatus::BANNED], User::class);

        self::assertTrue($result);
        // @phpstan-ignore staticMethod.impossibleType
        self::assertSame(UserStatus::BANNED, $user->fresh()?->status);
    }

    /**
     * Test that persist sets a falsy value to null for object cast attributes.
     *
     * @return void
     */
    public function testPersistSetsObjectWithFalsyToNull(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->attributeSetter, 'casts', ['name' => 'object']);

        $this->invokeMethod($this->attributeSetter, 'setAttribute', $user, 'name', null, 'object');

        self::assertNull($user->getAttribute('name'));
    }

    /**
     * Test that persist casts truthy values to stdClass for object casts.
     *
     * @return void
     */
    public function testPersistSetsObjectWithTruthyToStdClass(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->invokeMethod($this->attributeSetter, 'setAttribute', $user, 'name', ['key' => 'value'], 'object');

        self::assertInstanceOf(\stdClass::class, $user->getAttribute('name'));
    }

    /**
     * Test that persist associates a BelongsTo relation given its key.
     *
     * @return void
     */
    public function testPersistAssociatesRelation(): void
    {
        $org  = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->attributeSetter, 'casts', ['organization' => 'associate']);

        $result = $this->attributeSetter->persist($user, ['organization' => $org->id], User::class);

        self::assertTrue($result);
        self::assertSame($org->id, $user->organization_id);
    }

    /**
     * Test that persist associates a BelongsTo relation given the model.
     *
     * @return void
     */
    public function testPersistAssociatesRelationGivenTheModel(): void
    {
        $org  = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->attributeSetter, 'casts', ['organization' => 'associate']);

        self::assertTrue($this->attributeSetter->persist($user, ['organization' => $org], User::class));
        self::assertSame($org->id, $user->organization_id);
    }

    /**
     * Test that persist syncs a BelongsToMany relation after saving the model.
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

        self::assertTrue($result);

        $freshPost = $post->fresh();

        self::assertNotNull($freshPost);
        self::assertCount(1, $freshPost->tags()->get());
    }

    /**
     * Test that persist syncs a relation given a Collection of models.
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

        self::assertTrue($result);
        self::assertCount(1, $post->fresh()?->tags()->get() ?? collect([]));
    }

    /**
     * Test that syncing a collection plucks the related model's key name.
     *
     * @return void
     */
    public function testPersistSyncWithCollectionUsesRelatedModelKeyForNonIdPrimaryKey(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'T', 'body' => 'B']);
        $us   = Country::create(['code' => 'US', 'name' => 'United States']);
        $gb   = Country::create(['code' => 'GB', 'name' => 'United Kingdom']);

        $this->setProperty($this->attributeSetter, 'casts', ['countries' => 'sync']);

        $result = $this->attributeSetter->persist($post, ['countries' => collect([$us, $gb])], Post::class);

        self::assertTrue($result);

        $synced = $post->fresh()?->countries()->pluck('code')->all() ?? [];

        sort($synced);

        self::assertSame(['GB', 'US'], $synced);
    }

    /**
     * Test that persist syncs a relation given an array of integer IDs.
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

        self::assertTrue($result);
        self::assertCount(1, $post->fresh()?->tags()->get() ?? collect([]));
    }

    /**
     * Test that persist syncs a relation given a single Model instance.
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

        self::assertTrue($result);
        self::assertCount(1, $post->fresh()?->tags()->get() ?? collect([]));
    }

    /**
     * Test that syncing a Collection detaches relations absent from the value.
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

        self::assertTrue($result);

        $tagNames = $post->fresh()?->tags()->pluck('name')->all();

        self::assertSame(['new'], $tagNames);
    }

    /**
     * Test that syncing an ID array detaches relations absent from the value.
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

        self::assertTrue($result);

        $tagNames = $post->fresh()?->tags()->pluck('name')->all();

        self::assertSame(['fresh'], $tagNames);
    }

    /**
     * Test that persist skips attributes whose cast resolves to null.
     *
     * @return void
     */
    public function testPersistSkipsAttributeWithNullCast(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->schemaIntrospector->method('resolveRelation')->willReturn(null);

        $result = $this->attributeSetter->persist($user, ['unknown_field' => 'value'], User::class);

        self::assertTrue($result);
        self::assertSame('Alice', $user->fresh()?->name);
    }

    /**
     * Test that persist continues past an attribute whose cast is null.
     *
     * @return void
     */
    public function testPersistContinuesToLaterAttributesAfterNullCast(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->schemaIntrospector->method('resolveRelation')->willReturn(null);

        $this->setProperty($this->attributeSetter, 'casts', ['name' => 'string']);

        $result = $this->attributeSetter->persist($user, ['unknown_field' => 'value', 'name' => 'Bob'], User::class);

        self::assertTrue($result);
        self::assertSame('Bob', $user->fresh()?->name);
    }

    /**
     * Test that persist returns the boolean result of the model's save method.
     *
     * @return void
     */
    public function testPersistReturnsSaveResult(): void
    {
        $model = self::createStub(Model::class);
        $model->method('save')->willReturn(false);
        $model->method('getCasts')->willReturn([]);

        $result = $this->attributeSetter->persist($model, [], 'App\Models\Stub');

        self::assertFalse($result);
    }

    /**
     * Test that resolveAttributeCasts loads a cached cast map.
     *
     * @return void
     */
    public function testResolveAttributeCastsFromCache(): void
    {
        $cachedCasts = ['name' => 'string', 'active' => 'boolean'];
        $cacheKey    = $this->metadataStorageKey(CacheKeys::REPOSITORY_MODEL_CASTS->resolveKey([User::class]));

        Cache::memo()->rememberForever($cacheKey, fn () => $cachedCasts);

        $model = $this->createMock(Model::class);
        $model->expects(self::never())->method('getCasts');

        $this->attributeSetter->resolveAttributeCasts($model, User::class);

        $casts = $this->getProperty($this->attributeSetter, 'casts');

        self::assertSame($cachedCasts, $casts);
    }

    /**
     * Test that resolveAttributeCasts resolves and caches casts on a miss.
     *
     * @return void
     */
    public function testResolveAttributeCastsFromModelWhenCacheEmpty(): void
    {
        $model = new User;

        $this->attributeSetter->resolveAttributeCasts($model, User::class);

        $casts = $this->getProperty($this->attributeSetter, 'casts');

        self::assertIsArray($casts);
        self::assertArrayHasKey('status', $casts);

        $cached = Cache::memo()->get($this->metadataStorageKey(CacheKeys::REPOSITORY_MODEL_CASTS->resolveKey([User::class])));

        self::assertSame($casts, $cached);
    }

    /**
     * Test that persist memoises the resolved casts under the model class key.
     *
     * @return void
     */
    public function testPersistStoresResolvedCastsInCache(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->attributeSetter, 'casts', ['name' => 'string']);

        $this->attributeSetter->persist($user, ['name' => 'Bob'], User::class);

        $cached = Cache::memo()->get($this->metadataStorageKey(CacheKeys::REPOSITORY_MODEL_CASTS->resolveKey([User::class])));

        self::assertSame(['name' => 'string'], $cached);
    }

    /**
     * Test that resolveCastForAttribute returns null with no cast or model.
     *
     * @return void
     */
    public function testResolveCastForAttributeWithNullCastAndModelReturnsNull(): void
    {
        $result = $this->invokeMethod($this->attributeSetter, 'resolveCastForAttribute', 'field', null, null);

        self::assertNull($result);
    }

    /**
     * Test that resolveCastForRelation returns 'associate' for BelongsTo.
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

        self::assertSame('associate', $result);
    }

    /**
     * Test that resolveCastForRelation returns 'associate' for MorphTo.
     *
     * @return void
     */
    public function testResolveCastForRelationReturnsCastForMorphTo(): void
    {
        $model    = new User;
        $relation = self::createStub(MorphTo::class);

        $this->schemaIntrospector->method('resolveRelation')
            ->willReturnCallback(static fn (string $attribute, $subject) => $attribute === 'commentable' && $subject === $model ? $relation : null);

        $result = $this->invokeMethod($this->attributeSetter, 'resolveCastForRelation', 'commentable', $model);

        self::assertSame('associate', $result);
    }

    /**
     * Test that resolveCastForRelation returns 'sync' for BelongsToMany.
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

        self::assertSame('sync', $result);
    }

    /**
     * Test that resolveCastForRelation returns null for a non-relation.
     *
     * @return void
     */
    public function testResolveCastForRelationReturnsNullForNonRelation(): void
    {
        $model = new User;

        $this->schemaIntrospector->method('resolveRelation')
            ->willReturn(null);

        $result = $this->invokeMethod($this->attributeSetter, 'resolveCastForRelation', 'nonexistent', $model);

        self::assertNull($result);
    }

    /**
     * Test that resolveCastForRelation returns null for other relations.
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

        self::assertNull($result);
    }

    /**
     * Test that resolveCastForRelation returns 'sync' for MorphToMany.
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

        self::assertSame('sync', $result);
    }

    /**
     * Test that resolveCastForAttribute labels object casts as 'object'.
     *
     * @return void
     */
    public function testResolveCastForAttributeLabelsObjectCasts(): void
    {
        self::assertSame('object', $this->invokeMethod($this->attributeSetter, 'resolveCastForAttribute', 'field', 'object', null));
        self::assertSame('object', $this->invokeMethod($this->attributeSetter, 'resolveCastForAttribute', 'field', 'encrypted:object', null));
    }

    /**
     * Test that resolveCastForAttribute falls back to 'string' for other casts.
     *
     * Laravel's own cast converts scalar and enum casts on assignment.
     *
     * @return void
     */
    public function testResolveCastForAttributeFallsBackToString(): void
    {
        self::assertSame('string', $this->invokeMethod($this->attributeSetter, 'resolveCastForAttribute', 'field', 'custom_type', null));
        self::assertSame('string', $this->invokeMethod($this->attributeSetter, 'resolveCastForAttribute', 'field', 'datetime', null));
        self::assertSame('string', $this->invokeMethod($this->attributeSetter, 'resolveCastForAttribute', 'status', UserStatus::class, null));
    }

    /**
     * Test that resolveCastForAttribute labels a pure-enum cast.
     *
     * @return void
     */
    public function testResolveCastForAttributeLabelsPureEnumCasts(): void
    {
        self::assertSame('pure_enum', $this->invokeMethod($this->attributeSetter, 'resolveCastForAttribute', 'platform', Platform::class, null));
        self::assertSame('string', $this->invokeMethod($this->attributeSetter, 'resolveCastForAttribute', 'platform', CastablePlatform::class, null));
    }

    /**
     * Provide values a pure-enum attribute accepts, with the case each stores.
     *
     * @return iterable<string, array{mixed, \Tests\Fixtures\Enums\Platform|null}>
     */
    public static function pureEnumValueProvider(): iterable
    {
        yield 'wire name' => ['mobile_app', Platform::MOBILE_APP];
        yield 'enum case' => [Platform::DESKTOP, Platform::DESKTOP];
        yield 'null' => [null, null];
    }

    /**
     * Test that persist writes a pure-enum attribute from wire name or case.
     *
     * @param  mixed  $value
     * @param  \Tests\Fixtures\Enums\Platform|null  $expected
     * @return void
     */
    #[DataProvider('pureEnumValueProvider')]
    public function testPersistWritesAPureEnumFromItsWireNameOrCase(mixed $value, ?Platform $expected): void
    {
        $device = Device::create(['name' => 'Phone', 'platform' => Platform::WEB]);

        self::assertTrue($this->attributeSetter->persist($device, ['platform' => $value, 'status' => 'inactive'], Device::class));

        $fresh = $device->fresh();

        self::assertSame($expected, $fresh?->platform);
        self::assertSame($expected?->name, $fresh?->getRawOriginal('platform'));
        self::assertSame(UserStatus::INACTIVE, $fresh?->status);
    }

    /**
     * Provide strings that name no case of the pure enum in wire form.
     *
     * @return iterable<string, array{string}>
     */
    public static function unmatchedPureEnumNameProvider(): iterable
    {
        yield 'declared name' => ['MOBILE_APP'];
        yield 'unknown' => ['console'];
    }

    /**
     * Test that persist refuses a string naming no case of a pure enum.
     *
     * @param  string  $value
     * @return void
     */
    #[DataProvider('unmatchedPureEnumNameProvider')]
    public function testPersistRefusesAStringNamingNoPureEnumCase(string $value): void
    {
        $device = Device::create(['name' => 'Phone', 'platform' => Platform::WEB]);

        try {
            $this->attributeSetter->persist($device, ['platform' => $value], Device::class);

            self::fail('A string naming no case must be refused.');
        } catch (\ValueError $exception) {
            self::assertSame(sprintf('"%s" is not a valid wire name for enum %s', $value, Platform::class), $exception->getMessage());
        }

        self::assertSame('WEB', $device->fresh()?->getRawOriginal('platform'));
    }

    /**
     * Test that an associate cast on a non-BelongsTo attribute throws.
     *
     * @return void
     */
    public function testSetAssociateAttributeThrowsWhenRelationIsNotBelongsTo(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not resolve to a BelongsTo relation');

        $this->invokeMethod($this->attributeSetter, 'setAttribute', $user, 'posts', 1, 'associate');
    }

    /**
     * Test that a sync cast on a non-BelongsToMany attribute throws.
     *
     * @return void
     */
    public function testSetSyncAttributeThrowsWhenRelationIsNotBelongsToMany(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not resolve to a BelongsToMany relation');

        $this->invokeMethod($this->attributeSetter, 'setAttribute', $user, 'organization', 1, 'sync');
    }

    /**
     * Test that an associate cast refuses a value with no model or key.
     *
     * @return void
     */
    public function testSetAssociateAttributeThrowsWhenValueIsNeitherAModelNorAKey(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Attribute "organization" on %s must be given a model or its key', User::class));

        $this->invokeMethod($this->attributeSetter, 'setAttribute', $user, 'organization', [1], 'associate');
    }

    /**
     * Test that a sync cast given neither models nor keys refuses the value.
     *
     * @return void
     */
    public function testSetSyncAttributeThrowsWhenValueIsNeitherModelsNorKeys(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'T', 'body' => 'B']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Attribute "tags" on %s must be given models or their keys', Post::class));

        $this->invokeMethod($this->attributeSetter, 'setAttribute', $post, 'tags', 1.5, 'sync');
    }

    /**
     * Test that persist stores the cast map under REPOSITORY_MODEL_CASTS.
     *
     * @return void
     */
    public function testStoreCastsStoresUnderTheModelCastsKey(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);

        $this->setProperty($this->attributeSetter, 'casts', ['name' => 'string']);

        $this->attributeSetter->persist($user, ['name' => 'Bob'], User::class);

        $expectedKey = $this->metadataStorageKey(CacheKeys::REPOSITORY_MODEL_CASTS->resolveKey([User::class]));

        self::assertIsArray(Cache::memo()->get($expectedKey));
    }

    /**
     * Test that a stored cast map is served without asking the model again.
     *
     * @return void
     */
    public function testResolveAttributeCastsServesACastMapAnEarlierProcessStored(): void
    {
        $cacheKey = $this->metadataStorageKey(CacheKeys::REPOSITORY_MODEL_CASTS->resolveKey([User::class]));

        Cache::memo()->rememberForever($cacheKey, fn (): array => ['name' => 'string']);

        $model = $this->createMock(Model::class);
        $model->expects(self::never())->method('getCasts');

        $this->attributeSetter->resolveAttributeCasts($model, User::class);
    }
}
