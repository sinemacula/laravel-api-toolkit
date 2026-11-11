<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Actors;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Services\Actors\EloquentActor;
use Tests\Fixtures\Actors\ActorUser;
use Tests\TestCase;

/**
 * Unit tests for EloquentActor.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(EloquentActor::class)]
final class EloquentActorTest extends TestCase
{
    /**
     * Register the ActorUser morph alias before each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap(['actor_user' => ActorUser::class]);
    }

    /**
     * Test that the actor accessors reflect the wrapped model.
     *
     * @return void
     */
    public function testAdaptsModel(): void
    {
        $user  = ActorUser::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']);
        $actor = new EloquentActor($user);

        self::assertSame($user->id, $actor->actorIdentifier());
        self::assertSame('actor_user', $actor->actorType());
        self::assertSame('Alice', $actor->actorLabel());
        self::assertSame($user, $actor->toAuthenticatable());
    }

    /**
     * Test that the static for() factory delegates to the constructor.
     *
     * @return void
     */
    public function testForFactoryAdaptsModel(): void
    {
        $user  = ActorUser::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret']);
        $actor = EloquentActor::for($user);

        self::assertSame($user->id, $actor->actorIdentifier());
        self::assertSame('actor_user', $actor->actorType());
        self::assertSame('Bob', $actor->actorLabel());
    }

    /**
     * Test that the label is snapshotted at construction.
     *
     * @return void
     */
    public function testLabelSnapshotTakenAtConstruction(): void
    {
        $user  = ActorUser::create(['name' => 'Charlie', 'email' => 'charlie@example.com', 'password' => 'secret']);
        $actor = new EloquentActor($user);

        $user->name = 'Updated Charlie';
        $user->save();

        self::assertSame('Charlie', $actor->actorLabel());
    }

    /**
     * Test that serialize() keeps the morph type, id and label, not the model.
     *
     * @return void
     */
    public function testSerialisesAsMorphReferenceAndLabel(): void
    {
        $user  = ActorUser::create(['name' => 'Diana', 'email' => 'diana@example.com', 'password' => 'secret']);
        $actor = new EloquentActor($user);

        $serialised = $actor->__serialize();

        self::assertSame('actor_user', $serialised['morph_type']);
        self::assertSame($user->id, $serialised['identifier']);
        self::assertSame('Diana', $serialised['label']);
        self::assertArrayNotHasKey('model', $serialised);
        self::assertArrayNotHasKey('resolved', $serialised);
    }

    /**
     * Test that a serialization round trip re-resolves the model via morph map.
     *
     * @return void
     */
    public function testReResolvesModelOnUnserialize(): void
    {
        $user  = ActorUser::create(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'secret']);
        $actor = new EloquentActor($user);

        /** @var \SineMacula\ApiToolkit\Services\Actors\EloquentActor $restored */
        $restored = unserialize(serialize($actor));

        self::assertSame('actor_user', $restored->actorType());
        self::assertSame($user->id, $restored->actorIdentifier());
        self::assertSame('Eve', $restored->actorLabel());

        $resolved = $restored->toAuthenticatable();

        self::assertNotNull($resolved);
        self::assertInstanceOf(ActorUser::class, $resolved);
        self::assertSame($user->id, $resolved->getKey());
    }

    /**
     * Test that a round trip re-resolves a model with no morph alias by class.
     *
     * @return void
     */
    public function testReResolvesUnmappedModelOnUnserialize(): void
    {
        Relation::morphMap([], false);

        $user  = ActorUser::create(['name' => 'Ivy', 'email' => 'ivy@example.com', 'password' => 'secret']);
        $actor = new EloquentActor($user);

        /** @var \SineMacula\ApiToolkit\Services\Actors\EloquentActor $restored */
        $restored = unserialize(serialize($actor));

        self::assertSame(ActorUser::class, $restored->actorType());

        $resolved = $restored->toAuthenticatable();

        self::assertInstanceOf(ActorUser::class, $resolved);
        self::assertSame($user->id, $resolved->getKey());
    }

    /**
     * Provide morph types that resolve to no model.
     *
     * @return iterable<string, array{string}>
     */
    public static function unresolvableMorphTypeProvider(): iterable
    {
        yield 'unknown alias' => ['unknown_alias'];
        yield 'class that is not a model' => [\stdClass::class];
    }

    /**
     * Test that a morph type naming no alias or model resolves to no model.
     *
     * @param  string  $morphType
     * @return void
     */
    #[DataProvider('unresolvableMorphTypeProvider')]
    public function testUnresolvableMorphTypeResolvesToNull(string $morphType): void
    {
        $actor = new EloquentActor(ActorUser::create(['name' => 'Jack', 'email' => 'jack@example.com', 'password' => 'secret']));

        $payload               = $actor->__serialize();
        $payload['morph_type'] = $morphType;

        $actor->__unserialize($payload);

        self::assertNull($actor->toAuthenticatable());
    }

    /**
     * Test that label and identifier survive the record changing or going away.
     *
     * @return void
     */
    public function testRetainsAttributionWhenRecordChanges(): void
    {
        $user  = ActorUser::create(['name' => 'Frank', 'email' => 'frank@example.com', 'password' => 'secret']);
        $actor = new EloquentActor($user);

        $capturedId    = $user->id;
        $capturedLabel = 'Frank';

        $user->name = 'Renamed Frank';
        $user->save();

        self::assertSame($capturedLabel, $actor->actorLabel());
        self::assertSame($capturedId, $actor->actorIdentifier());

        $user->delete();

        /** @var \SineMacula\ApiToolkit\Services\Actors\EloquentActor $restored */
        $restored = unserialize(serialize($actor));

        self::assertSame($capturedLabel, $restored->actorLabel());
        self::assertSame($capturedId, $restored->actorIdentifier());
        self::assertNull($restored->toAuthenticatable());
    }

    /**
     * Test that the label falls back to the email attribute without a name.
     *
     * @return void
     */
    public function testUsesEmailAsLabelFallback(): void
    {
        $user  = ActorUser::create(['name' => '', 'email' => 'grace@example.com', 'password' => 'secret']);
        $actor = new EloquentActor($user);

        self::assertSame('grace@example.com', $actor->actorLabel());
    }

    /**
     * Test that toAuthenticatable() after unserialisation queries only once.
     *
     * @return void
     */
    public function testReResolvesOnlyOnce(): void
    {
        $user  = ActorUser::create(['name' => 'Hank', 'email' => 'hank@example.com', 'password' => 'secret']);
        $actor = new EloquentActor($user);

        /** @var \SineMacula\ApiToolkit\Services\Actors\EloquentActor $restored */
        $restored = unserialize(serialize($actor));

        $first  = $restored->toAuthenticatable();
        $second = $restored->toAuthenticatable();

        self::assertSame($first, $second);
    }

    /**
     * Test that a string primary key is captured verbatim as the identifier.
     *
     * @return void
     */
    public function testCapturesStringPrimaryKeyAsIdentifier(): void
    {
        $model = $this->makeStringKeyedModel(['code' => 'GB', 'name' => 'Great Britain']);

        $actor = new EloquentActor($model);

        self::assertSame('GB', $actor->actorIdentifier());
    }

    /**
     * Test that a null primary key falls back to an empty string identifier.
     *
     * @return void
     */
    public function testFallsBackToEmptyIdentifierForNullKey(): void
    {
        $model = $this->makeStringKeyedModel(['name' => 'Keyless']);

        $actor = new EloquentActor($model);

        self::assertSame('', $actor->actorIdentifier());
    }

    /**
     * Test that the label falls back to the class basename.
     *
     * @return void
     */
    public function testLabelFallsBackToClassBasename(): void
    {
        $model = new #[Table('users')] class extends Model implements AuthenticatableContract {
            use Authenticatable;
        };

        $model->setAttribute('id', 5);

        $actor = new EloquentActor($model);

        self::assertSame(class_basename($model), $actor->actorLabel());
    }

    /**
     * Build an authenticatable model keyed by a non-incrementing string.
     *
     * @param  array<string, mixed>  $attributes
     * @return \Illuminate\Contracts\Auth\Authenticatable&\Illuminate\Database\Eloquent\Model
     */
    private function makeStringKeyedModel(array $attributes): AuthenticatableContract&Model
    {
        $model = new #[Table('countries')] class extends Model implements AuthenticatableContract {
            use Authenticatable;

            /** @var string */
            protected $primaryKey = 'code';

            /** @var string */
            protected $keyType = 'string';

            /** @var bool */
            public $incrementing = false;
        };

        foreach ($attributes as $key => $value) {
            $model->setAttribute($key, $value);
        }

        return $model;
    }
}
