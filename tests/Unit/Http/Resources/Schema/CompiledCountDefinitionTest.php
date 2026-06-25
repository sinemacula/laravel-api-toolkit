<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\CompiledCountDefinition;

/**
 * Tests for the CompiledCountDefinition value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CompiledCountDefinition::class)]
final class CompiledCountDefinitionTest extends TestCase
{
    /**
     * Test that all constructor properties are stored and accessible.
     *
     * @return void
     */
    public function testCompiledCountDefinitionStoresAllProperties(): void
    {
        $constraint = fn ($query) => $query->where('active', true);
        $guard      = fn () => true;

        $definition = new CompiledCountDefinition(
            presentKey: 'active_posts',
            relation: 'posts',
            constraint: $constraint,
            isDefault: true,
            guards: [$guard],
        );

        self::assertSame('active_posts', $definition->presentKey);
        self::assertSame('posts', $definition->relation);
        self::assertSame($constraint, $definition->constraint);
        self::assertTrue($definition->isDefault);
        self::assertSame([$guard], $definition->guards);
    }

    /**
     * Test that isDefault can be false.
     *
     * @return void
     */
    public function testCompiledCountDefinitionDefaultFlagIsFalse(): void
    {
        $definition = new CompiledCountDefinition(
            presentKey: 'comments',
            relation: 'comments',
            constraint: null,
            isDefault: false,
            guards: [],
        );

        self::assertFalse($definition->isDefault);
    }

    /**
     * Test that constraint accepts null.
     *
     * @return void
     */
    public function testCompiledCountDefinitionConstraintCanBeNull(): void
    {
        $definition = new CompiledCountDefinition(
            presentKey: 'posts',
            relation: 'posts',
            constraint: null,
            isDefault: false,
            guards: [],
        );

        self::assertNull($definition->constraint);
    }
}
