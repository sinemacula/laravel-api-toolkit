<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;

/**
 * Tests for the FieldColumnMapper builder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FieldColumnMapper::class)]
final class FieldColumnMapperTest extends TestCase
{
    /**
     * Test that build skips unmapped fields but still processes later mapped
     * fields.
     *
     * @return void
     */
    public function testBuildContinuesPastUnmappedFieldsToLaterMappedFields(): void
    {
        $schema = new CompiledSchema([
            'opaque' => $this->definition(accessor: 'nested.path'),
            'plain'  => $this->definition(),
        ], []);

        $map = FieldColumnMapper::build($schema);

        self::assertFalse($map->isMapped('opaque'));
        self::assertTrue($map->isMapped('plain'));
        self::assertSame(['plain'], $map->columnsFor('plain'));
    }

    /**
     * Test that a plain scalar is mapped by inference from its own key.
     *
     * @return void
     */
    public function testBuildInfersAPlainScalarFromItsKey(): void
    {
        $map = FieldColumnMapper::build(new CompiledSchema(['email' => $this->definition()], []));

        self::assertTrue($map->isMapped('email'));
        self::assertTrue($map->isInferred('email'));
        self::assertSame(['email'], $map->columnsFor('email'));
    }

    /**
     * Test that a needs-carrying plain scalar is declared, not inferred.
     *
     * @return void
     */
    public function testBuildDeclaresANeedsCarryingScalarRatherThanInferringIt(): void
    {
        $map = FieldColumnMapper::build(new CompiledSchema(['title' => $this->definition(needs: ['title', 'slug'])], []));

        self::assertTrue($map->isMapped('title'));
        self::assertFalse($map->isInferred('title'));
        self::assertSame(['title', 'slug'], $map->columnsFor('title'));
    }

    /**
     * Test that a computed or transformed field without needs is unmapped.
     *
     * @return void
     */
    public function testBuildLeavesComputedAndTransformedFieldsUnmapped(): void
    {
        $schema = new CompiledSchema([
            'computed'    => $this->definition(compute: 'getComputed'),
            'transformed' => $this->definition(transformers: [static fn (mixed $value): mixed => $value]),
        ], []);

        $map = FieldColumnMapper::build($schema);

        self::assertFalse($map->isMapped('computed'));
        self::assertFalse($map->isMapped('transformed'));
    }

    /**
     * Test that build maps a needs-carrying field to its declared columns.
     *
     * @return void
     */
    public function testBuildMapsNeedsCarryingFieldToDeclaredColumns(): void
    {
        $schema = new CompiledSchema([
            'full_name' => $this->definition(accessor: 'x', needs: ['first_name', 'last_name']),
        ], []);

        $map = FieldColumnMapper::build($schema);

        self::assertTrue($map->isMapped('full_name'));
        self::assertFalse($map->isInferred('full_name'));
        self::assertSame(['first_name', 'last_name'], $map->columnsFor('full_name'));
    }

    /**
     * Build a compiled field definition with sensible defaults for the fields
     * not under test.
     *
     * @param  mixed  $accessor
     * @param  array<int, string>  $needs
     * @param  mixed  $compute
     * @param  array<int, callable(mixed, mixed): mixed>  $transformers
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function definition(mixed $accessor = null, array $needs = [], mixed $compute = null, array $transformers = []): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor    : $accessor,
            compute     : $compute,
            relation    : null,
            resource    : null,
            fields      : null,
            constraint  : null,
            extras      : [],
            needs       : $needs,
            guards      : [],
            transformers: $transformers,
        );
    }
}
