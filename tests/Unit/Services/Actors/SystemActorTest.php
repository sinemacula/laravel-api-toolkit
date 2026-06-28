<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Actors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Actors\SystemActor;

/**
 * Unit tests for SystemActor.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SystemActor::class)]
final class SystemActorTest extends TestCase
{
    /**
     * Test null-object semantics: identifier is null, type is 'system',
     * and toAuthenticatable() returns null.
     *
     * @return void
     */
    public function testNullObjectSemantics(): void
    {
        $actor = new SystemActor;

        self::assertNull($actor->actorIdentifier());
        self::assertSame('system', $actor->actorType());
        self::assertNull($actor->toAuthenticatable());
    }

    /**
     * Test that named() sets the label and the default label is 'System'.
     *
     * @return void
     */
    public function testNamedSetsLabel(): void
    {
        $default = new SystemActor;
        $named   = SystemActor::named('Scheduler');

        self::assertSame('System', $default->actorLabel());
        self::assertSame('Scheduler', $named->actorLabel());
    }
}
