<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledCountDefinition;
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

        self::assertCount(1, $counts);
        self::assertArrayHasKey('posts', $counts);
        self::assertSame($count, $counts['posts']);
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

        self::assertSame([], $schema->getCountDefinitions());
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
            column: 'id',
            metric: 'sum',
            constraint: null,
            isDefault: true,
            guards: [],
        );

        $schema = new CompiledSchema([], [], ['posts_id' => $aggregate]);

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
            column: 'id',
            metric: 'sum',
            constraint: null,
            isDefault: true,
            guards: [],
        );

        $avg = new CompiledAggregateDefinition(
            presentKey: 'posts_id_avg',
            relation: 'posts',
            column: 'id',
            metric: 'avg',
            constraint: null,
            isDefault: false,
            guards: [],
        );

        $schema = new CompiledSchema([], [], ['posts_id' => $sum, 'posts_id_avg' => $avg]);

        $aggregates = $schema->getAggregateDefinitions();

        self::assertCount(2, $aggregates);
        self::assertSame('sum', $aggregates['posts_id']->metric);
        self::assertSame('avg', $aggregates['posts_id_avg']->metric);
    }
}
