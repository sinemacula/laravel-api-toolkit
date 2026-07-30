<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use Tests\Fixtures\Enums\UserLevel;
use Tests\Fixtures\Enums\UserStatus;

/**
 * Tests for the EnumSchemaRegistry.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(EnumSchemaRegistry::class)]
final class EnumSchemaRegistryTest extends TestCase
{
    /**
     * Test that a freshly built registry reports no classes.
     *
     * @return void
     */
    public function testStartsEmpty(): void
    {
        self::assertSame([], (new EnumSchemaRegistry)->classes());
    }

    /**
     * Test that registered enum classes are listed, and that registering one
     * twice keeps it a single entry.
     *
     * @return void
     */
    public function testRegistrationIsIdempotent(): void
    {
        $registry = new EnumSchemaRegistry;

        $registry->register(UserStatus::class);
        $registry->register(UserLevel::class);
        $registry->register(UserStatus::class);

        self::assertSame([UserStatus::class, UserLevel::class], $registry->classes());
    }

    /**
     * Test that resetting clears the collected set so the next document starts
     * empty.
     *
     * @return void
     */
    public function testResetClearsTheSet(): void
    {
        $registry = new EnumSchemaRegistry;

        $registry->register(UserStatus::class);
        $registry->reset();

        self::assertSame([], $registry->classes());
    }
}
