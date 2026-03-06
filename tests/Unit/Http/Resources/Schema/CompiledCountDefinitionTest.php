<?php

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledCountDefinition;

/**
 * Tests for the CompiledCountDefinition value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CompiledCountDefinition::class)]
class CompiledCountDefinitionTest extends TestCase
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

        static::assertSame('active_posts', $definition->presentKey);
        static::assertSame('posts', $definition->relation);
        static::assertSame($constraint, $definition->constraint);
        static::assertTrue($definition->isDefault);
        static::assertSame([$guard], $definition->guards);
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

        static::assertFalse($definition->isDefault);
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

        static::assertNull($definition->constraint);
    }
}
