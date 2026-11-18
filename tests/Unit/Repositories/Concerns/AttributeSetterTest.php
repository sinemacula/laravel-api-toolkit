<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Repositories\Concerns\AttributeSetter;
use Tests\Concerns\CreatesDocumentTables;
use Tests\Concerns\RecordsStatements;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\Country;
use Tests\Fixtures\Models\Device;
use Tests\Fixtures\Models\Document;
use Tests\Fixtures\Models\Note;
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
#[Group('database')]
final class AttributeSetterTest extends TestCase
{
    use CreatesDocumentTables, RecordsStatements;

    /** @var string */
    private const string ALICE_EMAIL = 'alice@example.com';

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

        $this->attributeSetter = new AttributeSetter($this->app->make(SchemaIntrospectionProvider::class));
    }

    /**
     * Test that persist writes uncast columns on an existing model.
     *
     * @return void
     */
    public function testPersistWritesUncastColumnsOnUpdate(): void
    {
        $user = $this->createAlice();

        self::assertTrue($this->attributeSetter->persist($user, ['name' => 'Bob', 'email' => 'bob@example.com']));

        $fresh = $user->fresh();

        self::assertNotNull($fresh);
        self::assertSame('Bob', $fresh->name);
        self::assertSame('bob@example.com', $fresh->email);
    }

    /**
     * Test that persist writes uncast columns when creating a model.
     *
     * @return void
     */
    public function testPersistWritesUncastColumnsOnCreate(): void
    {
        $user = new User;

        self::assertTrue($this->attributeSetter->persist($user, ['name' => 'Carol', 'email' => 'carol@example.com']));
        self::assertTrue($user->exists);

        $fresh = $user->fresh();

        self::assertNotNull($fresh);
        self::assertSame('Carol', $fresh->name);
        self::assertSame('carol@example.com', $fresh->email);
    }

    /**
     * Provide cast attributes, with the value each reads back as.
     *
     * @return iterable<string, array{string, mixed, mixed}>
     */
    public static function castRoundTripProvider(): iterable
    {
        yield 'integer' => ['priority', '5', 5];
        yield 'boolean' => ['pinned', 1, true];
        yield 'array' => ['options', ['theme' => 'dark'], ['theme' => 'dark']];
        yield 'backed enum by value' => ['status', 'banned', UserStatus::BANNED];
        yield 'backed enum by case' => ['status', UserStatus::INACTIVE, UserStatus::INACTIVE];
    }

    /**
     * Test that persist writes a cast attribute through the model's cast.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  mixed  $expected
     * @return void
     */
    #[DataProvider('castRoundTripProvider')]
    public function testPersistWritesACastAttributeThroughItsCast(string $attribute, mixed $value, mixed $expected): void
    {
        $note = $this->createNote();

        self::assertTrue($this->attributeSetter->persist($note, [$attribute => $value]));
        self::assertSame($expected, $note->fresh()?->getAttribute($attribute));
    }

    /**
     * Provide values for an object cast, with the JSON each is stored as.
     *
     * @return iterable<string, array{mixed, string|null}>
     */
    public static function objectCastProvider(): iterable
    {
        yield 'array' => [['a' => 1], '{"a":1}'];
        yield 'empty array' => [[], '{}'];
        yield 'null' => [null, null];
        yield 'object' => [(object) ['b' => 2], '{"b":2}'];
        yield 'false' => [false, 'false'];
        yield 'zero' => [0, '0'];
        yield 'empty string' => ['', '""'];
    }

    /**
     * Test that an object cast converts an array and passes anything else on.
     *
     * @param  mixed  $value
     * @param  string|null  $stored
     * @return void
     */
    #[DataProvider('objectCastProvider')]
    public function testPersistWritesAnObjectCastConvertingOnlyArrays(mixed $value, ?string $stored): void
    {
        $note = $this->createNote(['meta' => (object) ['old' => true]]);

        self::assertTrue($this->attributeSetter->persist($note, ['meta' => $value]));
        self::assertSame($stored, $note->fresh()?->getRawOriginal('meta'));
    }

    /**
     * Test that an empty array written to an object cast reads back as an empty
     * object.
     *
     * @return void
     */
    public function testPersistReadsAnEmptyArrayBackAsAnEmptyObject(): void
    {
        $note = $this->createNote();

        $this->attributeSetter->persist($note, ['meta' => []]);

        self::assertEquals(new \stdClass, $note->fresh()?->meta);
    }

    /**
     * Test that an encrypted object cast converts an array before it is
     * encrypted, so an empty array reads back as an empty object.
     *
     * @return void
     */
    public function testPersistConvertsAnArrayForAnEncryptedObjectCast(): void
    {
        Config::set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));

        $note = $this->createNote();

        $note->mergeCasts(['meta' => 'encrypted:object']);

        self::assertTrue($this->attributeSetter->persist($note, ['meta' => []]));

        $fresh = Note::query()->findOrFail($note->id)->mergeCasts(['meta' => 'encrypted:object']);

        self::assertEquals(new \stdClass, $fresh->meta);
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

        self::assertTrue($this->attributeSetter->persist($device, ['platform' => $value, 'status' => 'inactive']));

        $fresh = $device->fresh();

        self::assertNotNull($fresh);
        self::assertSame($expected, $fresh->platform);
        self::assertSame($expected?->name, $fresh->getRawOriginal('platform'));
        self::assertSame(UserStatus::INACTIVE, $fresh->status);
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
            $this->attributeSetter->persist($device, ['platform' => $value]);

            self::fail('A string naming no case must be refused.');
        } catch (\ValueError $exception) {
            self::assertSame(sprintf('"%s" is not a valid wire name for enum %s', $value, Platform::class), $exception->getMessage());
        }

        self::assertSame('WEB', $device->fresh()?->getRawOriginal('platform'));
    }

    /**
     * Test that a cast merged onto one instance is honoured for that instance
     * only.
     *
     * @return void
     */
    public function testPersistReadsTheCastsOfTheInstanceItIsGiven(): void
    {
        $merged = $this->createNote();
        $plain  = $this->createNote();

        $merged->mergeCasts(['kind' => Platform::class]);

        $this->attributeSetter->persist($merged, ['kind' => 'mobile_app']);
        $this->attributeSetter->persist($plain, ['kind' => 'mobile_app']);

        self::assertSame('MOBILE_APP', $merged->fresh()?->getRawOriginal('kind'));
        self::assertSame('mobile_app', $plain->fresh()?->getRawOriginal('kind'));
    }

    /**
     * Test that an Attribute set mutator stores the mutated value.
     *
     * @return void
     */
    public function testPersistWritesThroughAnAttributeSetMutator(): void
    {
        $note = $this->createNote();

        self::assertTrue($this->attributeSetter->persist($note, ['slug' => 'Hello World']));
        self::assertSame('hello-world', $note->fresh()?->slug);
    }

    /**
     * Test that a legacy set mutator stores the mutated value, winning over a
     * relation of the same name.
     *
     * @return void
     */
    public function testPersistPrefersALegacySetMutatorOverARelationOfTheSameName(): void
    {
        $alice = $this->createAlice();
        $note  = $this->createNote();

        self::assertTrue($this->attributeSetter->persist($note, ['owner' => self::ALICE_EMAIL]));
        self::assertSame($alice->id, $note->fresh()?->owner_id);
    }

    /**
     * Test that a pure-enum cast converts the wire name before the set mutator
     * of the same key runs.
     *
     * @return void
     */
    public function testPersistConvertsAPureEnumCastBeforeItsSetMutatorRuns(): void
    {
        $note = $this->newMutatedNote();

        self::assertTrue($this->attributeSetter->persist($note, ['kind' => 'mobile_app']));
        self::assertSame('MOBILE_APP', $note->fresh()?->getRawOriginal('kind'));
    }

    /**
     * Test that an Attribute set mutator wins over a dynamic relation of the
     * same name.
     *
     * @return void
     */
    public function testPersistPrefersAnAttributeSetMutatorOverARelationOfTheSameName(): void
    {
        $alice = $this->createAlice();
        $note  = $this->newMutatedNote();

        $note::resolveRelationUsing('primary_contact', static fn (Model $model): BelongsTo => $model->belongsTo(User::class, 'primary_contact_id', 'id', 'primary_contact'));

        self::assertTrue($this->attributeSetter->persist($note, ['primary_contact' => self::ALICE_EMAIL]));
        self::assertSame($alice->id, $note->fresh()?->getAttribute('primary_contact_id'));
    }

    /**
     * Test that a JSON path key updates one key of a JSON column.
     *
     * @return void
     */
    public function testPersistWritesAJsonPathKey(): void
    {
        $note = $this->createNote(['options' => ['theme' => 'light', 'font' => 'serif']]);

        self::assertTrue($this->attributeSetter->persist($note, ['options->theme' => 'dark']));
        self::assertEquals(['theme' => 'dark', 'font' => 'serif'], $note->fresh()?->options);
    }

    /**
     * Test that persist associates a BelongsTo relation given an integer key.
     *
     * @return void
     */
    public function testPersistAssociatesABelongsToByIntegerKey(): void
    {
        $org  = $this->createOrganization();
        $user = $this->createAlice();

        self::assertTrue($this->attributeSetter->persist($user, ['organization' => $org->id]));
        self::assertSame($org->id, $user->fresh()?->organization_id);
    }

    /**
     * Test that persist associates a BelongsTo relation given a string key.
     *
     * @return void
     */
    public function testPersistAssociatesABelongsToByStringKey(): void
    {
        $org  = $this->createOrganization();
        $user = $this->createAlice();

        self::assertTrue($this->attributeSetter->persist($user, ['organization' => (string) $org->id]));
        self::assertSame($org->id, $user->fresh()?->organization_id);
    }

    /**
     * Test that persist associates a BelongsTo relation given the model.
     *
     * @return void
     */
    public function testPersistAssociatesABelongsToByModel(): void
    {
        $org  = $this->createOrganization();
        $user = $this->createAlice();

        self::assertTrue($this->attributeSetter->persist($user, ['organization' => $org]));
        self::assertSame($org->id, $user->fresh()?->organization_id);
        self::assertSame($org, $user->getRelation('organization'));
    }

    /**
     * Test that persist dissociates a BelongsTo relation given null.
     *
     * @return void
     */
    public function testPersistDissociatesABelongsToGivenNull(): void
    {
        $org  = $this->createOrganization();
        $user = User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL, 'organization_id' => $org->id]);

        self::assertTrue($this->attributeSetter->persist($user, ['organization' => null]));
        self::assertNull($user->fresh()?->organization_id);
    }

    /**
     * Test that a BelongsTo given neither a model nor a key is refused.
     *
     * @return void
     */
    public function testPersistRefusesABelongsToValueThatIsNeitherAModelNorAKey(): void
    {
        $user = $this->createAlice();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Attribute "organization" on %s must be given a model or its key', User::class));

        $this->attributeSetter->persist($user, ['organization' => [1]]);
    }

    /**
     * Test that a MorphTo with no type yet is associated from a model.
     *
     * @return void
     */
    public function testPersistAssociatesAMorphToFromAModel(): void
    {
        $alice = $this->createAlice();
        $note  = $this->createNote();

        self::assertTrue($this->attributeSetter->persist($note, ['noteable' => $alice]));

        $fresh = $note->fresh();

        self::assertNotNull($fresh);
        self::assertSame($alice->id, $fresh->noteable_id);
        self::assertSame(User::class, $fresh->noteable_type);
    }

    /**
     * Provide keys a MorphTo cannot be associated from.
     *
     * @return iterable<string, array{int|string}>
     */
    public static function morphToKeyProvider(): iterable
    {
        yield 'integer' => [5];
        yield 'string' => ['5'];
    }

    /**
     * Test that a MorphTo given a key is refused and keeps its association.
     *
     * @param  int|string  $key
     * @return void
     */
    #[DataProvider('morphToKeyProvider')]
    public function testPersistRefusesAMorphToGivenAKey(int|string $key): void
    {
        $alice = $this->createAlice();
        $note  = $this->createNote(['noteable_id' => $alice->id, 'noteable_type' => User::class]);

        try {
            $this->attributeSetter->persist($note, ['noteable' => $key]);

            self::fail('A MorphTo given a key must be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(sprintf('Attribute "noteable" on %s is a MorphTo relation and must be given a model', Note::class), $exception->getMessage());
        }

        $fresh = $note->fresh();

        self::assertNotNull($fresh);
        self::assertSame($alice->id, $fresh->noteable_id);
        self::assertSame(User::class, $fresh->noteable_type);
    }

    /**
     * Test that a MorphTo given null is dissociated.
     *
     * @return void
     */
    public function testPersistDissociatesAMorphToGivenNull(): void
    {
        $alice = $this->createAlice();
        $note  = $this->createNote(['noteable_id' => $alice->id, 'noteable_type' => User::class]);

        self::assertTrue($this->attributeSetter->persist($note, ['noteable' => null]));

        $fresh = $note->fresh();

        self::assertNotNull($fresh);
        self::assertNull($fresh->noteable_id);
        self::assertNull($fresh->noteable_type);
    }

    /**
     * Test that a dynamic relation is written through the name it was
     * registered under.
     *
     * @return void
     */
    public function testPersistAssociatesADynamicRelationByItsRegisteredName(): void
    {
        Note::resolveRelationUsing('primary_contact', static fn (Note $note): BelongsTo => $note->belongsTo(User::class, 'primary_contact_id', 'id', 'primary_contact'));

        $alice = $this->createAlice();
        $note  = $this->createNote();

        self::assertTrue($this->attributeSetter->persist($note, ['primary_contact' => $alice->id]));
        self::assertSame($alice->id, $note->fresh()?->primary_contact_id);
    }

    /**
     * Test that persist syncs a BelongsToMany from an array of keys, detaching
     * the rest.
     *
     * @return void
     */
    public function testPersistSyncsABelongsToManyFromKeys(): void
    {
        $post = $this->createPost();
        $old  = Tag::create(['name' => 'stale']);
        $new  = Tag::create(['name' => 'fresh']);

        $post->tags()->attach($old->getKey());

        self::assertTrue($this->attributeSetter->persist($post, ['tags' => [$new->getKey()]]));
        self::assertSame(['fresh'], $post->tags()->pluck('name')->all());
    }

    /**
     * Test that persist syncs a BelongsToMany from a single key.
     *
     * @return void
     */
    public function testPersistSyncsABelongsToManyFromASingleKey(): void
    {
        $post = $this->createPost();
        $tag  = Tag::create(['name' => 'php']);

        self::assertTrue($this->attributeSetter->persist($post, ['tags' => $tag->getKey()]));
        self::assertSame(['php'], $post->tags()->pluck('name')->all());
    }

    /**
     * Test that persist syncs a BelongsToMany from a single string key.
     *
     * @return void
     */
    public function testPersistSyncsABelongsToManyFromAStringKey(): void
    {
        $post = $this->createPost();

        Country::create(['code' => 'US', 'name' => 'United States']);

        self::assertTrue($this->attributeSetter->persist($post, ['countries' => 'US']));
        self::assertSame(['US'], $post->countries()->pluck('code')->all());
    }

    /**
     * Test that persist syncs a BelongsToMany from a single model.
     *
     * @return void
     */
    public function testPersistSyncsABelongsToManyFromAModel(): void
    {
        $post = $this->createPost();
        $tag  = Tag::create(['name' => 'single']);

        self::assertTrue($this->attributeSetter->persist($post, ['tags' => $tag]));
        self::assertSame(['single'], $post->tags()->pluck('name')->all());
    }

    /**
     * Test that syncing a collection plucks the related model's key name.
     *
     * @return void
     */
    public function testPersistSyncsABelongsToManyFromACollectionByTheRelatedKey(): void
    {
        $post = $this->createPost();
        $us   = Country::create(['code' => 'US', 'name' => 'United States']);
        $gb   = Country::create(['code' => 'GB', 'name' => 'United Kingdom']);

        self::assertTrue($this->attributeSetter->persist($post, ['countries' => collect([$us, $gb])]));
        self::assertSame(['GB', 'US'], $post->countries()->orderBy('code')->pluck('code')->all());
    }

    /**
     * Test that persist syncs a BelongsToMany once a new model is saved, and
     * writes the keys given after it.
     *
     * @return void
     */
    public function testPersistSyncsABelongsToManyOnCreate(): void
    {
        $user = $this->createAlice();
        $tag  = Tag::create(['name' => 'php']);
        $post = new Post;

        self::assertTrue($this->attributeSetter->persist($post, ['tags' => [$tag->getKey()], 'user_id' => $user->id, 'title' => 'T', 'body' => 'B']));
        self::assertTrue($post->exists);
        self::assertSame('T', $post->fresh()?->title);
        self::assertSame(['php'], $post->tags()->pluck('name')->all());
    }

    /**
     * Test that a BelongsToMany given neither models nor keys is refused, and
     * the save before it rolled back.
     *
     * @return void
     */
    public function testPersistRefusesABelongsToManyValueThatIsNeitherModelsNorKeys(): void
    {
        $post = $this->createPost();

        try {
            $this->attributeSetter->persist($post, ['title' => 'changed', 'tags' => 1.5]);

            self::fail('A value that is neither models nor keys must be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(sprintf('Attribute "tags" on %s must be given models or their keys', Post::class), $exception->getMessage());
        }

        self::assertSame('T', $post->fresh()?->title);
    }

    /**
     * Test that persist syncs a MorphToMany from keys, a model or a collection.
     *
     * @return void
     */
    public function testPersistSyncsAMorphToMany(): void
    {
        $note = $this->createNote();
        $php  = Tag::create(['name' => 'php']);
        $sql  = Tag::create(['name' => 'sql']);

        $this->attributeSetter->persist($note, ['labels' => [$php->getKey(), $sql->getKey()]]);

        self::assertSame(['php', 'sql'], $note->labels()->orderBy('name')->pluck('name')->all());

        $this->attributeSetter->persist($note, ['labels' => $sql]);

        self::assertSame(['sql'], $note->labels()->pluck('name')->all());

        $this->attributeSetter->persist($note, ['labels' => collect([$php])]);

        self::assertSame(['php'], $note->labels()->pluck('name')->all());
    }

    /**
     * Test that persist syncs a MorphToMany once a new model is saved.
     *
     * @return void
     */
    public function testPersistSyncsAMorphToManyOnCreate(): void
    {
        $tag  = Tag::create(['name' => 'php']);
        $note = new Note;

        self::assertTrue($this->attributeSetter->persist($note, ['labels' => [$tag->getKey()], 'title' => 'first']));
        self::assertTrue($note->exists);
        self::assertSame(['php'], $note->labels()->pluck('name')->all());
        self::assertSame(Note::class, Note::query()->getConnection()->table('labelables')->value('labelable_type'));
    }

    /**
     * Provide relations persist cannot write, with their relation type.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unwritableRelationProvider(): iterable
    {
        yield 'has many' => ['posts', 'HasMany'];
        yield 'has one' => ['profile', 'HasOne'];
    }

    /**
     * Test that a relation persist cannot write is refused before any key is
     * assigned.
     *
     * @param  string  $relation
     * @param  string  $type
     * @return void
     */
    #[DataProvider('unwritableRelationProvider')]
    public function testPersistRefusesAnUnwritableRelationBeforeAssigningAnything(string $relation, string $type): void
    {
        $user = $this->createAlice();

        try {
            $this->attributeSetter->persist($user, ['name' => 'changed', $relation => [1]]);

            self::fail('An unwritable relation must be refused.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(sprintf('Attribute "%s" on %s is a %s relation, which persist() cannot write', $relation, User::class, $type), $exception->getMessage());
        }

        self::assertFalse($user->isDirty());
        self::assertSame('Alice', $user->fresh()?->name);
    }

    /**
     * Test that a key naming no column fails at save and changes nothing.
     *
     * @return void
     */
    public function testPersistFailsAtSaveForAKeyNamingNoColumn(): void
    {
        $user = $this->createAlice();

        $persist = fn (): bool => $this->attributeSetter->persist($user, ['name' => 'changed', 'nickname' => 'Al']);

        // The savepoint rollback undoes any write, so no read could see one
        $statements = $this->recordStatements(fn (): mixed => $this->assertThrows(static fn (): mixed => DB::transaction($persist), QueryException::class, 'nickname'));

        self::assertSame([], $statements);
    }

    /**
     * Test that persist returns false when the save is cancelled.
     *
     * @return void
     */
    public function testPersistReturnsTheSaveResult(): void
    {
        $user = $this->createAlice();

        User::saving(static fn (): bool => false);

        self::assertFalse($this->attributeSetter->persist($user, ['name' => 'Bob']));
        self::assertSame('Alice', $user->fresh()?->name);
    }

    /**
     * Provide the many-to-many relations persist syncs, with their pivot table.
     *
     * @return iterable<string, array{class-string<\Tests\Fixtures\Models\Note|\Tests\Fixtures\Models\Post>, string, string}>
     */
    public static function syncedRelationProvider(): iterable
    {
        yield 'belongs to many' => [Post::class, 'tags', 'post_tag'];
        yield 'morph to many' => [Note::class, 'labels', 'labelables'];
    }

    /**
     * Test that a cancelled save on an existing model syncs nothing.
     *
     * @param  class-string<\Tests\Fixtures\Models\Note|\Tests\Fixtures\Models\Post>  $class
     * @param  string  $relation
     * @param  string  $pivot
     * @return void
     */
    #[DataProvider('syncedRelationProvider')]
    public function testPersistDoesNotSyncWhenTheSaveIsCancelled(string $class, string $relation, string $pivot): void
    {
        $model = $class === Post::class ? $this->createPost() : $this->createNote(['title' => 'T']);
        $tag   = Tag::create(['name' => 'php']);

        $class::saving(static fn (): bool => false);

        self::assertFalse($this->attributeSetter->persist($model, ['title' => 'changed', $relation => [$tag->getKey()]]));
        self::assertSame('T', $model->fresh()?->title);
        self::assertSame(0, DB::table($pivot)->count());
    }

    /**
     * Test that a cancelled create with a relation returns false and writes
     * nothing.
     *
     * @param  class-string<\Tests\Fixtures\Models\Note|\Tests\Fixtures\Models\Post>  $class
     * @param  string  $relation
     * @param  string  $pivot
     * @return void
     */
    #[DataProvider('syncedRelationProvider')]
    public function testPersistReturnsFalseForACancelledCreateWithARelation(string $class, string $relation, string $pivot): void
    {
        $tag = Tag::create(['name' => 'php']);

        $class::saving(static fn (): bool => false);

        self::assertFalse($this->attributeSetter->persist(new $class, ['title' => 'T', $relation => [$tag->getKey()]]));
        self::assertSame(0, $class::query()->count());
        self::assertSame(0, DB::table($pivot)->count());
    }

    /**
     * Test that a persist changing only relation membership advances the
     * version once, with one update.
     *
     * @return void
     */
    public function testPersistChangingOnlyRelationsAdvancesTheVersionOnce(): void
    {
        $document = Document::create(['title' => 'Draft']);
        $tag      = Tag::create(['name' => 'php']);
        $marked   = [];

        Document::updating(static function (Document $updating) use (&$marked): void {
            $marked[] = $updating->isDirty('version');
        });

        self::assertTrue($this->attributeSetter->persist($document, ['tags' => [$tag->id]]));
        self::assertSame([true], $marked);
        self::assertSame(2, $this->storedVersion($document));
        self::assertSame(2, $document->version);
    }

    /**
     * Test that a relation-only persist advances the version when the stored
     * version is read back as a numeric string after the save.
     *
     * @return void
     */
    public function testPersistChangingOnlyRelationsAdvancesAVersionReadBackAsAString(): void
    {
        $document = Document::create(['title' => 'Draft']);
        $tag      = Tag::create(['name' => 'php']);

        Document::saved(static function (Document $saved): void {
            $saved->setRawAttributes(['version' => (string) $saved->getRawOriginal('version')] + $saved->getAttributes(), true);
        });

        self::assertTrue($this->attributeSetter->persist($document, ['tags' => [$tag->id]]));
        self::assertSame(2, $this->storedVersion($document));
    }

    /**
     * Test that a persist leaving relation membership as it was does not
     * advance the version.
     *
     * @return void
     */
    public function testPersistLeavingRelationsUnchangedKeepsTheVersion(): void
    {
        $document = Document::create(['title' => 'Draft']);
        $tag      = Tag::create(['name' => 'php']);

        $document->tags()->attach($tag->id);

        self::assertTrue($this->attributeSetter->persist($document, ['tags' => [$tag->id]]));
        self::assertSame(1, $this->storedVersion($document));
    }

    /**
     * Test that a persist changing a column and relations advances the version
     * once, and a create with relations starts it at 1.
     *
     * @return void
     */
    public function testPersistChangingColumnsAndRelationsAdvancesTheVersionOnce(): void
    {
        $tag      = Tag::create(['name' => 'php']);
        $document = new Document;

        self::assertTrue($this->attributeSetter->persist($document, ['title' => 'Draft', 'tags' => [$tag->id]]));
        self::assertSame(1, $this->storedVersion($document));

        self::assertTrue($this->attributeSetter->persist($document, ['title' => 'Revised', 'tags' => []]));
        self::assertSame(2, $this->storedVersion($document));
    }

    /**
     * Test that successive persists on one instance each advance the version.
     *
     * @return void
     */
    public function testSuccessivePersistsOnOneInstanceEachAdvanceTheVersion(): void
    {
        $document = Document::create(['title' => 'Draft']);
        $tag      = Tag::create(['name' => 'php']);

        $this->attributeSetter->persist($document, ['title' => 'Revised']);
        $this->attributeSetter->persist($document, ['tags' => [$tag->id]]);

        self::assertSame(3, $this->storedVersion($document));
    }

    /**
     * Test that a sync whose related model touches the document advances the
     * version once, not twice.
     *
     * @return void
     */
    public function testPersistDoesNotAdvanceAgainAfterAnInverseTouch(): void
    {
        $document = $this->travel(-1)->minutes(static fn (): Document => Document::create(['title' => 'Draft']));
        $tag      = Tag::create(['name' => 'php']);
        $marked   = [];

        Document::updating(static function (Document $updating) use (&$marked): void {
            $marked[] = $updating->isDirty('version');
        });

        self::assertTrue($this->attributeSetter->persist($document, ['touchingTags' => [$tag->id]]));
        self::assertSame([false], $marked);
        self::assertSame(2, $this->storedVersion($document));
    }

    /**
     * Test that a refused save recording a relation change throws and rolls the
     * relation change back.
     *
     * @return void
     */
    public function testRefusedVersionSaveRollsBackTheRelationChange(): void
    {
        $document = Document::create(['title' => 'Draft']);
        $tag      = Tag::create(['name' => 'php']);

        Document::updating(static fn (): bool => false);

        try {
            $this->attributeSetter->persist($document, ['tags' => [$tag->id]]);

            self::fail('A refused version save must throw.');
        } catch (\LogicException $exception) {
            self::assertSame(Document::class . ' refused the save that records its relation change, so the change is rolled back.', $exception->getMessage());
        }

        self::assertSame(0, DB::table('document_tag')->count());
        self::assertSame(1, $this->storedVersion($document));
    }

    /**
     * Define the database migrations.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->createDocumentTables();
    }

    /**
     * Create the user the tests write to.
     *
     * @return \Tests\Fixtures\Models\User
     */
    private function createAlice(): User
    {
        return User::create(['name' => 'Alice', 'email' => self::ALICE_EMAIL]);
    }

    /**
     * Create an organization to associate.
     *
     * @return \Tests\Fixtures\Models\Organization
     */
    private function createOrganization(): Organization
    {
        return Organization::create(['name' => 'Acme', 'slug' => 'acme']);
    }

    /**
     * Create a post owned by a new user.
     *
     * @return \Tests\Fixtures\Models\Post
     */
    private function createPost(): Post
    {
        return Post::create(['user_id' => $this->createAlice()->id, 'title' => 'T', 'body' => 'B']);
    }

    /**
     * Create an unsaved note whose cast and relation keys carry set mutators.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function newMutatedNote(): Model
    {
        return new #[Table('notes')] class extends Model {
            /**
             * Store the kind by its case name.
             *
             * @return \Illuminate\Database\Eloquent\Casts\Attribute
             */
            protected function kind(): Attribute
            {
                return Attribute::make(
                    set: static fn (Platform $kind): string => $kind->name,
                );
            }

            /**
             * Set the primary contact from the contact's email address.
             *
             * @return \Illuminate\Database\Eloquent\Casts\Attribute
             */
            protected function primaryContact(): Attribute
            {
                return Attribute::make(
                    set: static fn (string $email): array => ['primary_contact_id' => User::query()->where('email', $email)->value('id')],
                );
            }

            /**
             * Get the attributes that should be cast.
             *
             * @return array<string, string>
             */
            #[\Override]
            protected function casts(): array
            {
                return ['kind' => Platform::class];
            }
        };
    }

    /**
     * Create a note holding the given raw attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @return \Tests\Fixtures\Models\Note
     */
    private function createNote(array $attributes = []): Note
    {
        $note = new Note;

        $note->forceFill($attributes)->save();

        return $note;
    }

    /**
     * Read the version stored for the given document.
     *
     * @param  \Tests\Fixtures\Models\Document  $document
     * @return int
     */
    private function storedVersion(Document $document): int
    {
        $version = DB::table('documents')->where('id', $document->id)->value('version');

        assert(is_numeric($version));

        return (int) $version;
    }
}
