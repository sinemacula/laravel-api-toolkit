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
     * fields rather than stopping at the first unmapped one.
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
        self::assertSame(['first_name', 'last_name'], $map->columnsFor('full_name'));
    }

    /**
     * Build a compiled field definition with sensible defaults for the fields
     * not under test.
     *
     * @param  mixed  $accessor
     * @param  array<int, string>  $needs
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function definition(mixed $accessor = null, array $needs = []): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor    : $accessor,
            compute     : null,
            relation    : null,
            resource    : null,
            fields      : null,
            constraint  : null,
            extras      : [],
            needs       : $needs,
            guards      : [],
            transformers: [],
        );
    }
}
