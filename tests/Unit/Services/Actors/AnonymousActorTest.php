<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Actors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Actors\AnonymousActor;

/**
 * Unit tests for AnonymousActor.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(AnonymousActor::class)]
final class AnonymousActorTest extends TestCase
{
    /**
     * Test anonymous semantics: identifier is null, type is 'anonymous',
     * label is 'Anonymous', and toAuthenticatable() returns null.
     *
     * @return void
     */
    public function testAnonymousSemantics(): void
    {
        $actor = new AnonymousActor;

        self::assertNull($actor->actorIdentifier());
        self::assertSame('anonymous', $actor->actorType());
        self::assertSame('Anonymous', $actor->actorLabel());
        self::assertNull($actor->toAuthenticatable());
    }
}
