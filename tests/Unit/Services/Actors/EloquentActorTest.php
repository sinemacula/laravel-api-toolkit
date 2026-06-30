<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Actors;

use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\CoversClass;
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
     * Test identifier, type, label, and toAuthenticatable() reflect the wrapped
     * model.
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
     * Test that the label snapshot is taken at construction time and does not
     * change when the underlying record is updated afterwards.
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
     * Test that serialize() persists morph type, id, and label snapshot but not
     * the full model instance.
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
     * Test that a serialize()/unserialize() round trip preserves the morph
     * type, identifier, and label, and the deserialised actor re-resolves the
     * model via the morph map.
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
     * Test that label and identifier are retained when the underlying record is
     * changed or removed after the actor was captured.
     *
     * @return void
     */
    public function testRetainsAttributionWhenRecordChanges(): void
    {
        $user  = ActorUser::create(['name' => 'Frank', 'email' => 'frank@example.com', 'password' => 'secret']);
        $actor = new EloquentActor($user);

        $capturedId    = $user->id;
        $capturedLabel = 'Frank';

        // Change the underlying record.
        $user->name = 'Renamed Frank';
        $user->save();

        self::assertSame($capturedLabel, $actor->actorLabel());
        self::assertSame($capturedId, $actor->actorIdentifier());

        // Delete the underlying record; the deserialised actor returns null
        // from toAuthenticatable() but retains the attribution scalars.
        $user->delete();

        /** @var \SineMacula\ApiToolkit\Services\Actors\EloquentActor $restored */
        $restored = unserialize(serialize($actor));

        self::assertSame($capturedLabel, $restored->actorLabel());
        self::assertSame($capturedId, $restored->actorIdentifier());
        self::assertNull($restored->toAuthenticatable());
    }

    /**
     * Test that the email attribute is used as the label fallback when there is
     * no name attribute on the model.
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
     * Test that a second call to toAuthenticatable() after unserialisation does
     * not re-query the database (resolved flag prevents repeated lookups).
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
}
