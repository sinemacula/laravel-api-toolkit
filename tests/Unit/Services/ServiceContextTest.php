<?php

declare(strict_types = 1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Contracts\Actor;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\ServiceContext;
use Tests\Fixtures\Services\StubActor;

/**
 * Tests for the ServiceContext value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceContext::class)]
final class ServiceContextTest extends TestCase
{
    /**
     * Test that the constructor exposes all four properties.
     *
     * @return void
     */
    public function testConstructorExposesProperties(): void
    {
        $actor    = $this->makeActor();
        $metadata = ['ip' => '127.0.0.1'];

        $context = new ServiceContext(
            actor: $actor,
            correlationId: 'abc-123',
            source: ServiceSource::HTTP,
            metadata: $metadata,
        );

        self::assertSame($actor, $context->actor);
        self::assertSame('abc-123', $context->correlationId);
        self::assertSame(ServiceSource::HTTP, $context->source);
        self::assertSame($metadata, $context->metadata);
    }

    /**
     * Test that for() generates a non-empty correlation id when none is given.
     *
     * @return void
     */
    public function testForGeneratesCorrelationId(): void
    {
        $context = ServiceContext::for($this->makeActor());

        self::assertNotEmpty($context->correlationId);
    }

    /**
     * Test that for() preserves an explicit correlation id.
     *
     * @return void
     */
    public function testForPreservesExplicitCorrelationId(): void
    {
        $context = ServiceContext::for($this->makeActor(), correlationId: 'explicit-id-99');

        self::assertSame('explicit-id-99', $context->correlationId);
    }

    /**
     * Test that for() defaults the source to ServiceSource::INTERNAL.
     *
     * @return void
     */
    public function testForDefaultsSourceToInternal(): void
    {
        $context = ServiceContext::for($this->makeActor());

        self::assertSame(ServiceSource::INTERNAL, $context->source);
    }

    /**
     * Test that ServiceContext round-trips through PHP serialization.
     *
     * @return void
     */
    public function testRoundTripsThroughSerialization(): void
    {
        $actor    = $this->makeActor();
        $metadata = ['user-agent' => 'test/1.0', 'request-id' => 'req-42'];

        $context = ServiceContext::for(
            actor: $actor,
            source: ServiceSource::QUEUE,
            metadata: $metadata,
            correlationId: 'corr-xyz',
        );

        /** @var \SineMacula\ApiToolkit\Services\ServiceContext $restored */
        $restored = unserialize(serialize($context));

        self::assertInstanceOf(ServiceContext::class, $restored);
        self::assertSame('corr-xyz', $restored->correlationId);
        self::assertSame(ServiceSource::QUEUE, $restored->source);
        self::assertSame($metadata, $restored->metadata);
        self::assertSame($actor->actorType(), $restored->actor->actorType());
        self::assertSame($actor->actorIdentifier(), $restored->actor->actorIdentifier());
    }

    /**
     * Test that ServiceContext is immutable (final readonly class).
     *
     * @return void
     */
    public function testContextIsImmutable(): void
    {
        $reflection = new \ReflectionClass(ServiceContext::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }

    /**
     * Build a lightweight in-test Actor stub.
     *
     * @return \SineMacula\ApiToolkit\Services\Contracts\Actor
     */
    private function makeActor(): Actor
    {
        return new StubActor;
    }
}
