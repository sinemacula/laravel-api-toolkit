<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceReader;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\AliasedQueryableUserResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\UndeclaredQueryMarkerResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the query surface reader.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QuerySurfaceReader::class)]
final class QuerySurfaceReaderTest extends TestCase
{
    /**
     * Test that one surface is read per registered resource, in registry order.
     *
     * @return void
     */
    public function testReadsOneSurfacePerResourceInRegistryOrder(): void
    {
        $surfaces = (new QuerySurfaceReader)->read([
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
        ]);

        self::assertCount(2, $surfaces);
        self::assertSame(UserResource::class, $surfaces[0]->resource);
        self::assertSame(OrganizationResource::class, $surfaces[1]->resource);
    }

    /**
     * Test that an empty resource map reads no surfaces at all.
     *
     * @return void
     */
    public function testEmptyResourceMapReadsNoSurfaces(): void
    {
        self::assertSame([], (new QuerySurfaceReader)->read([]));
    }

    /**
     * Test that a filterable column reports the capability the compiled map
     * holds it to, so two capabilities on one resource read differently.
     *
     * @return void
     */
    public function testFilterableColumnReportsItsDeclaredCapability(): void
    {
        $columns = $this->columns(UserResource::class);

        self::assertSame(Capability::RANGE, $columns['id']->capability);
        self::assertSame(Capability::ENUM, $columns['status']->capability);
        self::assertSame(Capability::EXACT, $columns['organization_id']->capability);
    }

    /**
     * Test that a column declared sortable and nothing else reports the order
     * alone, with no capability and no search strategy beside it.
     *
     * @return void
     */
    public function testSortableOnlyColumnReportsTheOrderAlone(): void
    {
        $column = $this->columns(UserResource::class)['created_at'];

        self::assertTrue($column->sortable);
        self::assertNull($column->capability);
        self::assertNull($column->strategy);
        self::assertNull($column->unindexedReason);
    }

    /**
     * Test that a filterable column the resource never declared sortable
     * reports no order.
     *
     * @return void
     */
    public function testFilterableColumnWithoutASortReportsNoOrder(): void
    {
        self::assertFalse($this->columns(UserResource::class)['organization_id']->sortable);
    }

    /**
     * Test that a column answering nothing queryable is left out of the surface
     * entirely rather than reported as an empty offer.
     *
     * @return void
     */
    public function testColumnAnsweringNothingIsLeftOutOfTheSurface(): void
    {
        $columns = $this->columns(UserResource::class);

        self::assertArrayNotHasKey('full_label', $columns);
        self::assertArrayNotHasKey('display_label', $columns);
    }

    /**
     * Test that an aliased column reports both the property the response
     * carries it under and the column the query grammar names.
     *
     * @return void
     */
    public function testAliasedColumnReportsBothThePropertyAndTheColumn(): void
    {
        $column = $this->columns(AliasedQueryableUserResource::class)['email'];

        self::assertSame('email', $column->property);
        self::assertSame('email_address', $column->column);
    }

    /**
     * Test that an order exempted from needing an index carries the reason the
     * resource recorded for it.
     *
     * @return void
     */
    public function testExemptedOrderCarriesTheRecordedReason(): void
    {
        $column = $this->columns(AliasedQueryableUserResource::class)['email'];

        self::assertTrue($column->sortable);
        self::assertSame('the table is bounded by the seat count', $column->unindexedReason);
    }

    /**
     * Test that a searchable column reports the strategy the compiled map
     * matches it by.
     *
     * @return void
     */
    public function testSearchableColumnReportsItsMatchStrategy(): void
    {
        self::assertSame(
            SearchStrategy::SUBSTRING,
            $this->columns(AliasedQueryableUserResource::class)['email']->strategy,
        );
    }

    /**
     * Test that only the relations declared traversable are reported, an
     * ordinary relation being no part of the filter grammar.
     *
     * @return void
     */
    public function testOnlyTraversableRelationsAreReported(): void
    {
        self::assertSame(['organization'], $this->surface(AliasedQueryableUserResource::class)->relations);
    }

    /**
     * Test that a resource declaring nothing queryable reads as a surface with
     * no columns and no relations rather than being skipped.
     *
     * @return void
     */
    public function testResourceDeclaringNothingReadsAsAnEmptySurface(): void
    {
        $surface = $this->surface(OrganizationResource::class);

        self::assertSame([], $surface->columns);
        self::assertSame([], $surface->relations);
    }

    /**
     * Test that a declaration the compiled maps refuse to carry is never
     * reported, so the surface cannot offer a column the request-time gates
     * would reject.
     *
     * @return void
     */
    public function testDeclarationTheCompiledMapsRefuseIsNeverReported(): void
    {
        $columns = $this->columns(UndeclaredQueryMarkerResource::class);

        self::assertArrayNotHasKey('email', $columns);
        self::assertArrayNotHasKey('handle', $columns);
        self::assertArrayHasKey('id', $columns);
    }

    /**
     * Read a single resource's surface.
     *
     * @param  class-string  $resourceClass
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor
     */
    private function surface(string $resourceClass): QuerySurfaceDescriptor
    {
        return (new QuerySurfaceReader)->read([User::class => $resourceClass])[0];
    }

    /**
     * Read a single resource's columns, keyed by the property each is presented
     * under.
     *
     * @param  class-string  $resourceClass
     * @return array<string, \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor>
     */
    private function columns(string $resourceClass): array
    {
        $columns = [];

        foreach ($this->surface($resourceClass)->columns as $column) {
            $columns[$column->property] = $column;
        }

        return $columns;
    }
}
