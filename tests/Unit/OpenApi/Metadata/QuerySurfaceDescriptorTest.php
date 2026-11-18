<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
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
        $descriptor = new QuerySurfaceDescriptor(UserResource::class, [$column], [$relation]);

        self::assertSame(UserResource::class, $descriptor->resource);
        self::assertSame([$column], $descriptor->columns);
        self::assertSame([$relation], $descriptor->relations);
    }

    /**
     * Test that traversable relations default to none.
     *
     * @return void
     */
    public function testRelationsDefaultToNone(): void
    {
        $descriptor = new QuerySurfaceDescriptor(UserResource::class, []);

        self::assertSame([], $descriptor->relations);
    }
}
