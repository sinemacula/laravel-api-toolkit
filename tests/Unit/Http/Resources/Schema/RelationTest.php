<?php

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Schema\Relation;
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
class RelationTest extends TestCase
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

        static::assertSame('organization', $array['organization']['relation']);
        static::assertSame(OrganizationResource::class, $array['organization']['resource']);
        static::assertArrayNotHasKey('accessor', $array['organization']);
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

        static::assertSame('organization', $array['organization']['relation']);
        static::assertSame('name', $array['organization']['accessor']);
        static::assertArrayNotHasKey('resource', $array['organization']);
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

        static::assertSame($accessor, $array['organization']['accessor']);
        static::assertArrayNotHasKey('resource', $array['organization']);
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

        static::assertArrayHasKey('org', $array);
        static::assertArrayNotHasKey('organization', $array);
        static::assertSame('organization', $array['org']['relation']);
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

        static::assertArrayHasKey('org_name', $array);
        static::assertArrayNotHasKey('organization', $array);
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

        static::assertSame(['id', 'name'], $array['organization']['fields']);
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

        static::assertSame(['id', 'name'], $array['organization']['fields']);
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

        static::assertSame($constraint, $array['organization']['constraint']);
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

        static::assertArrayHasKey('organization', $array);
        static::assertSame('organization', $array['organization']['relation']);
        static::assertSame(OrganizationResource::class, $array['organization']['resource']);
        static::assertSame(['id', 'name'], $array['organization']['fields']);
        static::assertSame($constraint, $array['organization']['constraint']);
        static::assertSame([$guard], $array['organization']['guards']);
        static::assertSame([$transformer], $array['organization']['transformers']);
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

        static::assertArrayNotHasKey('accessor', $array['organization']);
        static::assertArrayNotHasKey('fields', $array['organization']);
        static::assertArrayNotHasKey('constraint', $array['organization']);
        static::assertArrayNotHasKey('guards', $array['organization']);
        static::assertArrayNotHasKey('transformers', $array['organization']);
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

        static::assertSame(['organization.owner'], $array['organization']['extras']);
    }
}
