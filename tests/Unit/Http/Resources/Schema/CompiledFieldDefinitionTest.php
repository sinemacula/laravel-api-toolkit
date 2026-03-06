<?php

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition;

/**
 * Tests for the CompiledFieldDefinition value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CompiledFieldDefinition::class)]
class CompiledFieldDefinitionTest extends TestCase
{
    /**
     * Test that all constructor properties are stored and accessible.
     *
     * @return void
     */
    public function testCompiledFieldDefinitionStoresAllProperties(): void
    {
        $constraint  = fn ($query) => $query->where('active', true);
        $guard       = fn () => true;
        $transformer = fn ($resource, $value) => strtoupper($value);
        $compute     = fn ($resource) => 'computed_value';

        $definition = new CompiledFieldDefinition(
            accessor: 'profile.name',
            compute: $compute,
            relation: 'profile',
            resource: 'App\Http\Resources\ProfileResource',
            fields: ['name', 'email'],
            constraint: $constraint,
            extras: ['profile.avatar'],
            guards: [$guard],
            transformers: [$transformer],
        );

        static::assertSame('profile.name', $definition->accessor);
        static::assertSame($compute, $definition->compute);
        static::assertSame('profile', $definition->relation);
        static::assertSame('App\Http\Resources\ProfileResource', $definition->resource);
        static::assertSame(['name', 'email'], $definition->fields);
        static::assertSame($constraint, $definition->constraint);
        static::assertSame(['profile.avatar'], $definition->extras);
        static::assertSame([$guard], $definition->guards);
        static::assertSame([$transformer], $definition->transformers);
    }

    /**
     * Test that nullable properties accept null values.
     *
     * @return void
     */
    public function testCompiledFieldDefinitionAcceptsNullableProperties(): void
    {
        $definition = new CompiledFieldDefinition(
            accessor: null,
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            guards: [],
            transformers: [],
        );

        static::assertNull($definition->accessor);
        static::assertNull($definition->compute);
        static::assertNull($definition->relation);
        static::assertNull($definition->resource);
        static::assertNull($definition->fields);
        static::assertNull($definition->constraint);
    }

    /**
     * Test that array properties accept empty arrays.
     *
     * @return void
     */
    public function testCompiledFieldDefinitionAcceptsEmptyArrayDefaults(): void
    {
        $definition = new CompiledFieldDefinition(
            accessor: null,
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            guards: [],
            transformers: [],
        );

        static::assertSame([], $definition->extras);
        static::assertSame([], $definition->guards);
        static::assertSame([], $definition->transformers);
    }
}
