<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Schema\DefinedSchemaRegistry;
use Tests\Fixtures\OpenApi\PendingSignInShape;
use Tests\Fixtures\OpenApi\SignedInShape;

/**
 * Tests for the DefinedSchemaRegistry.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DefinedSchemaRegistry::class)]
final class DefinedSchemaRegistryTest extends TestCase
{
    /**
     * Test that a freshly built registry reports no classes.
     *
     * @return void
     */
    public function testStartsEmpty(): void
    {
        self::assertSame([], (new DefinedSchemaRegistry)->classes());
    }

    /**
     * Test that registered classes are listed once each, in first-registration
     * order.
     *
     * @return void
     */
    public function testRegistrationKeepsFirstRegistrationOrder(): void
    {
        $registry = new DefinedSchemaRegistry;

        $registry->register(SignedInShape::class);
        $registry->register(PendingSignInShape::class);
        $registry->register(SignedInShape::class);

        self::assertSame([SignedInShape::class, PendingSignInShape::class], $registry->classes());
    }

    /**
     * Test that resetting clears the collected set.
     *
     * @return void
     */
    public function testResetClearsTheSet(): void
    {
        $registry = new DefinedSchemaRegistry;

        $registry->register(SignedInShape::class);
        $registry->reset();

        self::assertSame([], $registry->classes());
    }
}
