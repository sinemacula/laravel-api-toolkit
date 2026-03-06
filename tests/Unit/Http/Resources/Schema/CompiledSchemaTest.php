<?php

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledCountDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;

/**
 * Tests for the CompiledSchema value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CompiledSchema::class)]
class CompiledSchemaTest extends TestCase
{
    /**
     * Test that getField returns the definition for a known key.
     *
     * @return void
     */
    public function testCompiledSchemaGetFieldReturnsDefinitionForKnownKey(): void
    {
        $field = new CompiledFieldDefinition(
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

        $schema = new CompiledSchema(['name' => $field], []);

        static::assertSame($field, $schema->getField('name'));
    }

    /**
     * Test that getField returns null for an unknown key.
     *
     * @return void
     */
    public function testCompiledSchemaGetFieldReturnsNullForUnknownKey(): void
    {
        $schema = new CompiledSchema([], []);

        static::assertNull($schema->getField('missing'));
    }

    /**
     * Test that getFieldKeys returns all field keys.
     *
     * @return void
     */
    public function testCompiledSchemaGetFieldKeysReturnsAllFieldKeys(): void
    {
        $field = new CompiledFieldDefinition(
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

        $schema = new CompiledSchema(
            ['name' => $field, 'email' => $field, 'status' => $field],
            [],
        );

        static::assertSame(['name', 'email', 'status'], $schema->getFieldKeys());
    }

    /**
     * Test that getFieldKeys returns an empty array for an empty schema.
     *
     * @return void
     */
    public function testCompiledSchemaGetFieldKeysReturnsEmptyForEmptySchema(): void
    {
        $schema = new CompiledSchema([], []);

        static::assertSame([], $schema->getFieldKeys());
    }

    /**
     * Test that getCountDefinitions returns all count definitions.
     *
     * @return void
     */
    public function testCompiledSchemaGetCountDefinitionsReturnsAllCounts(): void
    {
        $count = new CompiledCountDefinition(
            presentKey: 'posts',
            relation: 'posts',
            constraint: null,
            isDefault: true,
            guards: [],
        );

        $schema = new CompiledSchema([], ['posts' => $count]);

        $counts = $schema->getCountDefinitions();

        static::assertCount(1, $counts);
        static::assertArrayHasKey('posts', $counts);
        static::assertSame($count, $counts['posts']);
    }

    /**
     * Test that getCountDefinitions returns an empty array when no counts
     * exist.
     *
     * @return void
     */
    public function testCompiledSchemaGetCountDefinitionsReturnsEmptyWhenNoCounts(): void
    {
        $schema = new CompiledSchema([], []);

        static::assertSame([], $schema->getCountDefinitions());
    }

    /**
     * Test that hasField returns true for an existing field.
     *
     * @return void
     */
    public function testCompiledSchemaHasFieldReturnsTrueForExistingField(): void
    {
        $field = new CompiledFieldDefinition(
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

        $schema = new CompiledSchema(['name' => $field], []);

        static::assertTrue($schema->hasField('name'));
    }

    /**
     * Test that hasField returns false for a missing field.
     *
     * @return void
     */
    public function testCompiledSchemaHasFieldReturnsFalseForMissingField(): void
    {
        $schema = new CompiledSchema([], []);

        static::assertFalse($schema->hasField('nonexistent'));
    }
}
