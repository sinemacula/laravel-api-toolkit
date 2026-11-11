<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceReader;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\InteractsWithNonPublicMembers;
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
    use InteractsWithNonPublicMembers;

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
     * Test that a filterable column reports its compiled capability.
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
     * Test that a sortable-only column reports the order alone.
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
     * Test that a column never declared sortable reports no order.
     *
     * @return void
     */
    public function testFilterableColumnWithoutASortReportsNoOrder(): void
    {
        self::assertFalse($this->columns(UserResource::class)['organization_id']->sortable);
    }

    /**
     * Test that a column answering nothing is left out of the surface.
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
     * Test that an aliased column reports both property and column.
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
     * Test that an index-exempt order carries its recorded reason.
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
     * Test that a searchable column reports its compiled strategy.
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
     * Test that only traversable relations are reported.
     *
     * @return void
     */
    public function testOnlyTraversableRelationsAreReported(): void
    {
        self::assertSame(['organization'], $this->surface(AliasedQueryableUserResource::class)->relations);
    }

    /**
     * Test that a resource declaring nothing queryable reads as empty.
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
     * Test that a declaration the compiled maps refuse is not reported.
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
     * Test that a filterable column reports its operators in order.
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
     * Test that an operator gone from the vocabulary is dropped.
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
     * Test that a column with no remaining operators reports no capability.
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
     * Test that each declared column reports only what it answers.
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
     * Test that an exemption reason stays with the sortable column.
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
     * Test that an order belongs to the field that declared it.
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
     * Test that a refused marker does not abandon the field's later columns.
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
     * Test that a null field definition does not abandon later keys.
     *
     * @return void
     */
    public function testFieldKeyWithoutADefinitionIsSteppedOver(): void
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
            filterable: 'name',
            filterCapability: Capability::EXACT,
        );

        $schema = new CompiledSchema(['ghost' => null, 'name' => $field], [], [], ['name' => Capability::EXACT]); // @phpstan-ignore argument.type

        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['GhostResource' => $schema]);

        $columns = $this->ghostSurface('GhostResource')->columns;

        self::assertCount(1, $columns);
        self::assertSame('name', $columns[0]->property);
        self::assertSame('name', $columns[0]->column);
        self::assertSame(Capability::EXACT, $columns[0]->capability);
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
     * Read the surface of a resource name the compiler has been primed for.
     *
     * @param  string  $resourceClass
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor
     */
    private function ghostSurface(string $resourceClass): QuerySurfaceDescriptor
    {
        /** @var class-string $resourceClass */
        return $this->surface($resourceClass);
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
     * Read a single resource's columns, keyed by property.
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
