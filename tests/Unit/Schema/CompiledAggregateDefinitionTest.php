<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
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
        $constraint  = fn ($query) => $query->where('active', true);
        $guard       = fn () => true;
        $transformer = fn ($resource, $value) => $value;

        $definition = new CompiledAggregateDefinition(
            presentKey: 'orders_sum_total',
            relation: 'orders',
            function: AggregateFunction::SUM,
            column: 'total',
            constraint: $constraint,
            isDefault: true,
            guards: [$guard],
            transformers: [$transformer],
        );

        self::assertSame('orders_sum_total', $definition->presentKey);
        self::assertSame('orders', $definition->relation);
        self::assertSame(AggregateFunction::SUM, $definition->function);
        self::assertSame('total', $definition->column);
        self::assertSame($constraint, $definition->constraint);
        self::assertTrue($definition->isDefault);
        self::assertSame([$guard], $definition->guards);
        self::assertSame([$transformer], $definition->transformers);
    }

    /**
     * Test that a count carries no column and no transformers by default.
     *
     * @return void
     */
    public function testCountCarriesNoColumnOrTransformers(): void
    {
        $definition = new CompiledAggregateDefinition(
            presentKey: 'posts_count',
            relation: 'posts',
            function: AggregateFunction::COUNT,
            column: null,
            constraint: null,
            isDefault: false,
            guards: [],
        );

        self::assertNull($definition->column);
        self::assertNull($definition->constraint);
        self::assertFalse($definition->isDefault);
        self::assertSame([], $definition->transformers);
        self::assertSame([], $definition->callerGuards);
    }

    /**
     * Test that the attribute prefixes the present key, whatever the function.
     *
     * @return void
     */
    public function testAttributePrefixesThePresentKey(): void
    {
        $definition = new CompiledAggregateDefinition(
            presentKey: 'revenue',
            relation: 'orders',
            function: AggregateFunction::MAX,
            column: 'total',
            constraint: null,
            isDefault: false,
            guards: [],
        );

        self::assertSame('aggregate_revenue', $definition->attribute());
    }

    /**
     * Test that the caller guards are stored apart from the row guards.
     *
     * @return void
     */
    public function testCallerGuardsAreStoredApartFromTheRowGuards(): void
    {
        $guard       = static fn (): bool => true;
        $callerGuard = static fn (): bool => false;

        $definition = new CompiledAggregateDefinition(
            presentKey: 'posts_count',
            relation: 'posts',
            function: AggregateFunction::COUNT,
            column: null,
            constraint: null,
            isDefault: false,
            guards: [$guard],
            callerGuards: [$callerGuard],
        );

        self::assertSame([$guard], $definition->guards);
        self::assertSame([$callerGuard], $definition->callerGuards);
    }
}
