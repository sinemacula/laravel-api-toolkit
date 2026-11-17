<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;

/**
 * Tests for the CompiledSchema value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CompiledSchema::class)]
final class CompiledSchemaTest extends TestCase
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
            needs: [],
            guards: [],
            transformers: [],
        );

        $schema = new CompiledSchema(['name' => $field], []);

        self::assertSame($field, $schema->getField('name'));
    }

    /**
     * Test that getField returns null for an unknown key.
     *
     * @return void
     */
    public function testCompiledSchemaGetFieldReturnsNullForUnknownKey(): void
    {
        $schema = new CompiledSchema([], []);

        self::assertNull($schema->getField('missing'));
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
            needs: [],
            guards: [],
            transformers: [],
        );

        $schema = new CompiledSchema(
            ['name' => $field, 'email' => $field, 'status' => $field],
            [],
        );

        self::assertSame(['name', 'email', 'status'], $schema->getFieldKeys());
    }

    /**
     * Test that getFieldKeys returns an empty array for an empty schema.
     *
     * @return void
     */
    public function testCompiledSchemaGetFieldKeysReturnsEmptyForEmptySchema(): void
    {
        $schema = new CompiledSchema([], []);

        self::assertSame([], $schema->getFieldKeys());
    }

    /**
     * Test that getAggregateDefinitions returns all aggregate definitions.
     *
     * @return void
     */
    public function testCompiledSchemaGetAggregateDefinitionsReturnsAllAggregates(): void
    {
        $aggregate = new CompiledAggregateDefinition(
            presentKey: 'posts_id',
            relation: 'posts',
            function: AggregateFunction::SUM,
            column: 'id',
            constraint: null,
            isDefault: true,
            guards: [],
        );

        $schema = new CompiledSchema([], ['posts_id' => $aggregate]);

        $aggregates = $schema->getAggregateDefinitions();

        self::assertCount(1, $aggregates);
        self::assertArrayHasKey('posts_id', $aggregates);
        self::assertSame($aggregate, $aggregates['posts_id']);
    }

    /**
     * Test that getAggregateDefinitions returns an empty array when no
     * aggregates are defined.
     *
     * @return void
     */
    public function testCompiledSchemaGetAggregateDefinitionsReturnsEmptyWhenNone(): void
    {
        $schema = new CompiledSchema([], []);

        self::assertSame([], $schema->getAggregateDefinitions());
    }

    /**
     * Test that sum and average aggregates coexist under separate present keys.
     *
     * @return void
     */
    public function testCompiledSchemaAggregatesCanHoldBothSumAndAvg(): void
    {
        $sum = new CompiledAggregateDefinition(
            presentKey: 'posts_id',
            relation: 'posts',
            function: AggregateFunction::SUM,
            column: 'id',
            constraint: null,
            isDefault: true,
            guards: [],
        );

        $avg = new CompiledAggregateDefinition(
            presentKey: 'posts_id_avg',
            relation: 'posts',
            function: AggregateFunction::AVG,
            column: 'id',
            constraint: null,
            isDefault: false,
            guards: [],
        );

        $schema = new CompiledSchema([], ['posts_id' => $sum, 'posts_id_avg' => $avg]);

        $aggregates = $schema->getAggregateDefinitions();

        self::assertCount(2, $aggregates);
        self::assertSame(AggregateFunction::SUM, $aggregates['posts_id']->function);
        self::assertSame(AggregateFunction::AVG, $aggregates['posts_id_avg']->function);
    }

    /**
     * Test that getFilterableColumns returns the declared filterable columns
     * mapped to the capability each was declared with.
     *
     * @return void
     */
    public function testGetFilterableColumnsReturnsDeclaredColumns(): void
    {
        $schema = new CompiledSchema([], [], ['email' => Capability::EXACT, 'status' => Capability::ENUM]);

        self::assertSame(['email' => Capability::EXACT, 'status' => Capability::ENUM], $schema->getFilterableColumns());
    }

    /**
     * Test that getFilterableColumns defaults to an empty array.
     *
     * @return void
     */
    public function testGetFilterableColumnsDefaultsToEmpty(): void
    {
        $schema = new CompiledSchema([], []);

        self::assertSame([], $schema->getFilterableColumns());
    }

    /**
     * Test that getSortableColumns returns the declared sortable columns.
     *
     * @return void
     */
    public function testGetSortableColumnsReturnsDeclaredColumns(): void
    {
        $schema = new CompiledSchema([], [], [], ['created_at', 'name']);

        self::assertSame(['created_at', 'name'], $schema->getSortableColumns());
    }

    /**
     * Test that getSortableColumns defaults to an empty array.
     *
     * @return void
     */
    public function testGetSortableColumnsDefaultsToEmpty(): void
    {
        $schema = new CompiledSchema([], []);

        self::assertSame([], $schema->getSortableColumns());
    }

    /**
     * Test that getTraversableRelations returns the declared traversable
     * relation names.
     *
     * @return void
     */
    public function testGetTraversableRelationsReturnsDeclaredRelations(): void
    {
        $schema = new CompiledSchema([], [], [], [], ['posts', 'organization']);

        self::assertSame(['posts', 'organization'], $schema->getTraversableRelations());
    }

    /**
     * Test that getSearchableColumns returns the declared columns mapped to
     * their match strategy.
     *
     * @return void
     */
    public function testGetSearchableColumnsReturnsDeclaredColumns(): void
    {
        $schema = new CompiledSchema([], [], [], [], [], ['name' => SearchStrategy::SUBSTRING]);

        self::assertSame(['name' => SearchStrategy::SUBSTRING], $schema->getSearchableColumns());
    }

    /**
     * Test that getSearchableColumns defaults to an empty array.
     *
     * @return void
     */
    public function testGetSearchableColumnsDefaultsToEmpty(): void
    {
        $schema = new CompiledSchema([], []);

        self::assertSame([], $schema->getSearchableColumns());
    }

    /**
     * Test that getTraversableRelations defaults to an empty array.
     *
     * @return void
     */
    public function testGetTraversableRelationsDefaultsToEmpty(): void
    {
        $schema = new CompiledSchema([], []);

        self::assertSame([], $schema->getTraversableRelations());
    }
}
