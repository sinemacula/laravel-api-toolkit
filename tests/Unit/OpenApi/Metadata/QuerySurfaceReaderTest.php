<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceReader;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\AliasedQueryableUserResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\SharedColumnMarkerResource;
use Tests\Fixtures\Resources\SplitColumnMarkerResource;
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
        ], $this->vocabulary());

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
        self::assertSame([], (new QuerySurfaceReader)->read([], $this->vocabulary()));
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
     * Test that a filterable column reports the operators its capability
     * answers, in the capability's own order.
     *
     * @return void
     */
    public function testFilterableColumnReportsTheOperatorsItsCapabilityAnswers(): void
    {
        self::assertSame(
            ['$eq', '$in', '$neq', '$null', '$notNull'],
            $this->columns(UserResource::class)['status']->operators,
        );
    }

    /**
     * Test that an operator the live vocabulary no longer holds is dropped from
     * the column's reported operators while the rest survive, so the surface
     * cannot offer a predicate the filter engine no longer dispatches.
     *
     * @return void
     */
    public function testAnOperatorTheVocabularyNoLongerHoldsIsDroppedFromTheColumn(): void
    {
        $vocabulary = array_values(array_diff($this->vocabulary(), ['$in']));
        $column     = $this->columns(UserResource::class, $vocabulary)['status'];

        self::assertSame(['$eq', '$neq', '$null', '$notNull'], $column->operators);
        self::assertSame(Capability::ENUM, $column->capability);
    }

    /**
     * Test that a column whose every operator has left the vocabulary reports
     * no capability at all, being no more filterable than an undeclared one.
     *
     * @return void
     */
    public function testAColumnLeftWithNoDispatchableOperatorReportsNoCapability(): void
    {
        $vocabulary = array_values(array_diff($this->vocabulary(), ['$eq', '$in', '$null', '$notNull']));
        $columns    = $this->columns(AliasedQueryableUserResource::class, $vocabulary);

        self::assertSame([], $columns['email']->operators);
        self::assertNull($columns['email']->capability);
        self::assertTrue($columns['email']->sortable);
    }

    /**
     * Test that a field naming a different column in each declaration is
     * reported once per column, each carrying only what that column answers, so
     * no capability or strategy is attributed to a column never declared for
     * it.
     *
     * @return void
     */
    public function testAFieldNamingSeveralColumnsIsReportedOncePerColumn(): void
    {
        $columns = $this->surface(SplitColumnMarkerResource::class)->columns;

        self::assertCount(2, $columns);

        self::assertSame('label', $columns[0]->property);
        self::assertSame('label_filter', $columns[0]->column);
        self::assertSame(Capability::EXACT, $columns[0]->capability);
        self::assertFalse($columns[0]->sortable);

        self::assertSame('label', $columns[1]->property);
        self::assertSame('label_sort', $columns[1]->column);
        self::assertNull($columns[1]->capability);
        self::assertTrue($columns[1]->sortable);
    }

    /**
     * Test that the recorded exemption reason travels with the column the field
     * declared sortable rather than with a sibling column of the same field.
     *
     * @return void
     */
    public function testTheExemptionReasonTravelsWithTheOrderedColumnAlone(): void
    {
        $columns = $this->surface(SplitColumnMarkerResource::class)->columns;

        self::assertNull($columns[0]->unindexedReason);
        self::assertSame('the label table is a fixed lookup', $columns[1]->unindexedReason);
    }

    /**
     * Test that a column another field made orderable is not reported orderable
     * on a field that never declared an order for it, an order belonging to the
     * field that declared it rather than to the resource's sortable set.
     *
     * @return void
     */
    public function testAColumnIsOrderableOnlyOnTheFieldThatDeclaredTheOrder(): void
    {
        $columns = $this->surface(SharedColumnMarkerResource::class)->columns;

        $orderable = array_values(array_filter(
            $columns,
            static fn (QueryColumnDescriptor $column): bool => $column->sortable,
        ));

        self::assertSame(['owner', 'note'], array_column($orderable, 'property'));
        self::assertSame(['shared', 'shared'], array_column($orderable, 'column'));

        $filterable = array_values(array_filter(
            $columns,
            static fn (QueryColumnDescriptor $column): bool => $column->capability !== null,
        ));

        self::assertSame(['alias'], array_column($filterable, 'property'));
        self::assertFalse($filterable[0]->sortable);
    }

    /**
     * Test that a marker the compiled maps refuse is skipped without abandoning
     * the columns the same field declares after it.
     *
     * @return void
     */
    public function testARefusedMarkerDoesNotAbandonTheRestOfTheField(): void
    {
        $note = array_values(array_filter(
            $this->surface(SharedColumnMarkerResource::class)->columns,
            static fn (QueryColumnDescriptor $column): bool => $column->property === 'note',
        ));

        self::assertCount(1, $note);
        self::assertSame('shared', $note[0]->column);
        self::assertTrue($note[0]->sortable);
    }

    /**
     * Read a single resource's surface.
     *
     * @param  class-string  $resourceClass
     * @param  array<int, string>|null  $vocabulary
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor
     */
    private function surface(string $resourceClass, ?array $vocabulary = null): QuerySurfaceDescriptor
    {
        return (new QuerySurfaceReader)->read(
            [User::class => $resourceClass],
            $vocabulary ?? $this->vocabulary(),
        )[0];
    }

    /**
     * The operator tokens the package registers by default.
     *
     * @return array<int, string>
     */
    private function vocabulary(): array
    {
        return ['$eq', '$neq', '$gt', '$lt', '$ge', '$le', '$in', '$between', '$contains', '$null', '$notNull'];
    }

    /**
     * Read a single resource's columns, keyed by the property each is presented
     * under.
     *
     * @param  class-string  $resourceClass
     * @param  array<int, string>|null  $vocabulary
     * @return array<string, \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor>
     */
    private function columns(string $resourceClass, ?array $vocabulary = null): array
    {
        $columns = [];

        foreach ($this->surface($resourceClass, $vocabulary)->columns as $column) {
            $columns[$column->property] = $column;
        }

        return $columns;
    }
}
