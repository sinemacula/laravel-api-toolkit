<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;
use SineMacula\ApiToolkit\Schema\Relation;
use Tests\Fixtures\Resources\OrganizationResource;

/**
 * Tests for the Relation schema definition.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Relation::class)]
final class RelationTest extends TestCase
{
    /**
     * Test that to with a class string sets the resource.
     *
     * @return void
     */
    public function testToWithClassStringSetsResource(): void
    {
        $relation = Relation::to('organization', OrganizationResource::class);

        $array = $relation->toArray();

        self::assertSame('organization', $array['organization']['relation']);
        self::assertSame(OrganizationResource::class, $array['organization']['resource']);
        self::assertArrayNotHasKey('accessor', $array['organization']);
    }

    /**
     * Test that traversable() emits the relation name.
     *
     * @return void
     */
    public function testTraversableMarkerEmitsTheRelationName(): void
    {
        $array = Relation::to('organization', OrganizationResource::class)->traversable()->toArray();

        self::assertSame('organization', $array['organization']['traversable']);
    }

    /**
     * Test that a relation without the traversable marker omits it.
     *
     * @return void
     */
    public function testRelationWithoutTraversableOmitsIt(): void
    {
        $array = Relation::to('organization', OrganizationResource::class)->toArray();

        self::assertArrayNotHasKey('traversable', $array['organization']);
    }

    /**
     * Test that to with a non-class string sets the accessor.
     *
     * @return void
     */
    public function testToWithNonClassStringSetsAccessor(): void
    {
        $relation = Relation::to('organization', 'name');

        $array = $relation->toArray();

        self::assertSame('organization', $array['organization']['relation']);
        self::assertSame('name', $array['organization']['accessor']);
        self::assertArrayNotHasKey('resource', $array['organization']);
    }

    /**
     * Test that to with a callable sets the accessor.
     *
     * @return void
     */
    public function testToWithCallableSetsAccessor(): void
    {
        $accessor = fn ($resource) => $resource->organization->name;
        $relation = Relation::to('organization', $accessor);

        $array = $relation->toArray();

        self::assertSame($accessor, $array['organization']['accessor']);
        self::assertArrayNotHasKey('resource', $array['organization']);
    }

    /**
     * Test that alias changes the output key.
     *
     * @return void
     */
    public function testAliasChangesOutputKey(): void
    {
        $relation = Relation::to('organization', OrganizationResource::class);

        $relation->alias('org');

        $array = $relation->toArray();

        self::assertArrayHasKey('org', $array);
        self::assertArrayNotHasKey('organization', $array);
        self::assertSame('organization', $array['org']['relation']);
    }

    /**
     * Test that alias can be set via the static factory.
     *
     * @return void
     */
    public function testAliasCanBeSetViaStaticFactory(): void
    {
        $relation = Relation::to('organization', 'name', 'org_name');

        $array = $relation->toArray();

        self::assertArrayHasKey('org_name', $array);
        self::assertArrayNotHasKey('organization', $array);
    }

    /**
     * Test that fields sets child field projection.
     *
     * @return void
     */
    public function testFieldsSetsChildFieldProjection(): void
    {
        $relation = Relation::to('organization', OrganizationResource::class);

        $relation->fields(['id', 'name']);

        $array = $relation->toArray();

        self::assertSame(['id', 'name'], $array['organization']['fields']);
    }

    /**
     * Test that fields deduplicates values.
     *
     * @return void
     */
    public function testFieldsDeduplicatesValues(): void
    {
        $relation = Relation::to('organization', OrganizationResource::class);

        $relation->fields(['id', 'name', 'id']);

        $array = $relation->toArray();

        self::assertSame(['id', 'name'], $array['organization']['fields']);
    }

    /**
     * Test that fields reindexes sequentially when deduplication removes an
     * interior duplicate.
     *
     * @return void
     */
    public function testFieldsReindexesAfterInteriorDeduplication(): void
    {
        $relation = Relation::to('organization', OrganizationResource::class);

        $relation->fields(['id', 'name', 'id', 'slug']);

        $array = $relation->toArray();

        self::assertSame(['id', 'name', 'slug'], $array['organization']['fields']);
    }

    /**
     * Test that constrain sets an eager-load constraint.
     *
     * @return void
     */
    public function testConstrainSetsEagerLoadConstraint(): void
    {
        $constraint = fn ($query) => $query->where('active', true);
        $relation   = Relation::to('organization', OrganizationResource::class);

        $relation->constrain($constraint);

        $array = $relation->toArray();

        self::assertSame($constraint, $array['organization']['constraint']);
    }

    /**
     * Test that toArray returns correct structure with all properties set.
     *
     * @return void
     */
    public function testToArrayReturnsCorrectStructureWithAllProperties(): void
    {
        $constraint  = fn ($query) => $query->where('active', true);
        $guard       = fn () => true;
        $transformer = fn ($resource, $value) => $value;

        $relation = Relation::to('organization', OrganizationResource::class)
            ->fields(['id', 'name'])
            ->constrain($constraint)
            ->guard($guard)
            ->transform($transformer);

        $array = $relation->toArray();

        self::assertArrayHasKey('organization', $array);
        self::assertSame('organization', $array['organization']['relation']);
        self::assertSame(OrganizationResource::class, $array['organization']['resource']);
        self::assertSame(['id', 'name'], $array['organization']['fields']);
        self::assertSame($constraint, $array['organization']['constraint']);
        self::assertSame([$guard], $array['organization']['guards']);
        self::assertSame([$transformer], $array['organization']['transformers']);
    }

    /**
     * Test that toArray excludes null values.
     *
     * @return void
     */
    public function testToArrayExcludesNullValues(): void
    {
        $relation = Relation::to('organization', OrganizationResource::class);

        $array = $relation->toArray();

        self::assertArrayNotHasKey('accessor', $array['organization']);
        self::assertArrayNotHasKey('fields', $array['organization']);
        self::assertArrayNotHasKey('constraint', $array['organization']);
        self::assertArrayNotHasKey('guards', $array['organization']);
        self::assertArrayNotHasKey('transformers', $array['organization']);
    }

    /**
     * Test that extras are included in the toArray output when set.
     *
     * @return void
     */
    public function testExtrasIncludedInToArray(): void
    {
        $relation = Relation::to('organization', OrganizationResource::class)
            ->extras('organization.owner');

        $array = $relation->toArray();

        self::assertSame(['organization.owner'], $array['organization']['extras']);
    }

    /**
     * Test that the openapi key is absent when no declaration was made.
     *
     * Backward-compatibility oracle (AC-11): a relation with no openapi() call
     * must serialize without the new key.
     *
     * @return void
     */
    public function testToArrayOmitsOpenApiWhenNotDeclared(): void
    {
        $relation = Relation::to('organization', OrganizationResource::class);

        $array = $relation->toArray();

        self::assertArrayNotHasKey('openapi', $array['organization']);
    }

    /**
     * Test that the openapi key is emitted when a declaration was made.
     *
     * @return void
     */
    public function testToArrayIncludesOpenApiWhenDeclared(): void
    {
        $relation = Relation::to('organization', OrganizationResource::class);
        $relation->openapi()->type('object')->description('The owning organization');

        $array = $relation->toArray();

        self::assertArrayHasKey('openapi', $array['organization']);
        self::assertInstanceOf(OpenApiFieldSchema::class, $array['organization']['openapi']);
        self::assertSame('object', $array['organization']['openapi']->type);
    }

    /**
     * Test that toArray emits the needs key when declared.
     *
     * @return void
     */
    public function testToArrayEmitsNeedsWhenDeclared(): void
    {
        $relation = Relation::to('organization', OrganizationResource::class)
            ->needs('owner_id', 'owner_type');

        $array = $relation->toArray();

        self::assertSame(['owner_id', 'owner_type'], $array['organization']['needs']);
    }

    /**
     * Test that toArray omits the needs key when not declared.
     *
     * @return void
     */
    public function testToArrayOmitsNeedsWhenNotDeclared(): void
    {
        $relation = Relation::to('organization', OrganizationResource::class);

        $array = $relation->toArray();

        self::assertArrayNotHasKey('needs', $array['organization']);
    }

    /**
     * Test that needs deduplicates across multiple calls.
     *
     * @return void
     */
    public function testNeedsDeduplicatesAcrossCalls(): void
    {
        $relation = Relation::to('organization', OrganizationResource::class)
            ->needs('a')
            ->needs('a', 'b');

        $array = $relation->toArray();

        self::assertSame(['a', 'b'], $array['organization']['needs']);
    }
}
