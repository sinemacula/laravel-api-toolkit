<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;

/**
 * Tests for the CompiledAggregateDefinition value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CompiledAggregateDefinition::class)]
final class CompiledAggregateDefinitionTest extends TestCase
{
    /**
     * Test that all constructor properties are stored and accessible.
     *
     * @return void
     */
    public function testCompiledAggregateDefinitionStoresAllProperties(): void
    {
        $constraint = fn ($query) => $query->where('active', true);
        $guard      = fn () => true;

        $definition = new CompiledAggregateDefinition(
            presentKey: 'posts_id',
            relation: 'posts',
            column: 'id',
            metric: 'sum',
            constraint: $constraint,
            isDefault: true,
            guards: [$guard],
        );

        self::assertSame('posts_id', $definition->presentKey);
        self::assertSame('posts', $definition->relation);
        self::assertSame('id', $definition->column);
        self::assertSame('sum', $definition->metric);
        self::assertSame($constraint, $definition->constraint);
        self::assertTrue($definition->isDefault);
        self::assertSame([$guard], $definition->guards);
    }

    /**
     * Test that isDefault can be false.
     *
     * @return void
     */
    public function testCompiledAggregateDefinitionDefaultFlagCanBeFalse(): void
    {
        $definition = new CompiledAggregateDefinition(
            presentKey: 'posts_id',
            relation: 'posts',
            column: 'id',
            metric: 'avg',
            constraint: null,
            isDefault: false,
            guards: [],
        );

        self::assertFalse($definition->isDefault);
    }

    /**
     * Test that constraint can be null.
     *
     * @return void
     */
    public function testCompiledAggregateDefinitionConstraintCanBeNull(): void
    {
        $definition = new CompiledAggregateDefinition(
            presentKey: 'posts_id',
            relation: 'posts',
            column: 'id',
            metric: 'sum',
            constraint: null,
            isDefault: false,
            guards: [],
        );

        self::assertNull($definition->constraint);
    }

    /**
     * Test that guards can be empty.
     *
     * @return void
     */
    public function testCompiledAggregateDefinitionGuardsCanBeEmpty(): void
    {
        $definition = new CompiledAggregateDefinition(
            presentKey: 'posts_id',
            relation: 'posts',
            column: 'id',
            metric: 'avg',
            constraint: null,
            isDefault: false,
            guards: [],
        );

        self::assertSame([], $definition->guards);
    }

    /**
     * Test that the metric discriminator stores the provided value.
     *
     * @return void
     */
    public function testMetricDiscriminatorIsStoredVerbatim(): void
    {
        $sum = new CompiledAggregateDefinition(
            presentKey: 'posts_id',
            relation: 'posts',
            column: 'id',
            metric: 'sum',
            constraint: null,
            isDefault: false,
            guards: [],
        );

        $avg = new CompiledAggregateDefinition(
            presentKey: 'posts_id',
            relation: 'posts',
            column: 'id',
            metric: 'avg',
            constraint: null,
            isDefault: false,
            guards: [],
        );

        self::assertSame('sum', $sum->metric);
        self::assertSame('avg', $avg->metric);
    }
}
