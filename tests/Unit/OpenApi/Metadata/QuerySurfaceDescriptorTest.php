<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryScopeDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\UserResource;

/**
 * Tests for the QuerySurfaceDescriptor value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QuerySurfaceDescriptor::class)]
final class QuerySurfaceDescriptorTest extends TestCase
{
    /**
     * Test that all constructor properties are stored and accessible.
     *
     * @return void
     */
    public function testStoresAllProperties(): void
    {
        $column = new QueryColumnDescriptor(property: 'id', column: 'id');

        $relation   = new QueryRelationDescriptor('organization', OrganizationResource::class);
        $descriptor = new QuerySurfaceDescriptor(UserResource::class, [$column], [$relation], models: [User::class]);

        self::assertSame(UserResource::class, $descriptor->resource);
        self::assertSame([$column], $descriptor->columns);
        self::assertSame([$relation], $descriptor->relations);
        self::assertSame([User::class], $descriptor->models);
    }

    /**
     * Test that traversable relations and models default to none.
     *
     * @return void
     */
    public function testRelationsDefaultToNone(): void
    {
        $descriptor = new QuerySurfaceDescriptor(UserResource::class, []);

        self::assertSame([], $descriptor->relations);
        self::assertSame([], $descriptor->models);
    }

    /**
     * Test that a surface is filterable by a column answering a filter, a
     * relation or a scope, and not by an order or search column alone.
     *
     * @return void
     */
    public function testASurfaceIsFilterableByAColumnARelationOrAScope(): void
    {
        $ordered = new QueryColumnDescriptor('name', 'name', sortable: true, strategy: SearchStrategy::EXACT);

        self::assertFalse((new QuerySurfaceDescriptor(UserResource::class, [$ordered]))->isFilterable());
        self::assertTrue((new QuerySurfaceDescriptor(UserResource::class, [$ordered, new QueryColumnDescriptor('id', 'id', Capability::RANGE, ['$eq'])]))->isFilterable());
        self::assertTrue((new QuerySurfaceDescriptor(UserResource::class, [], [new QueryRelationDescriptor('posts')]))->isFilterable());
        self::assertTrue((new QuerySurfaceDescriptor(UserResource::class, [], scopes: [new QueryScopeDescriptor('upcoming')]))->isFilterable());
    }

    /**
     * Test that a surface is searchable only where a column declares a search
     * strategy.
     *
     * @return void
     */
    public function testASurfaceIsSearchableOnlyByAColumnDeclaringAStrategy(): void
    {
        $filtered = new QueryColumnDescriptor('id', 'id', Capability::RANGE, ['$eq'], sortable: true);
        $searched = new QueryColumnDescriptor('name', 'name', strategy: SearchStrategy::PREFIX);

        self::assertFalse((new QuerySurfaceDescriptor(UserResource::class, [$filtered]))->isSearchable());
        self::assertTrue((new QuerySurfaceDescriptor(UserResource::class, [$filtered, $searched]))->isSearchable());
    }
}
