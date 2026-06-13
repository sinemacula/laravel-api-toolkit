<?php

namespace Tests\Unit\OpenApi\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver;
use SineMacula\ApiToolkit\Services\SchemaIntrospector;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ResourceSchemaBuilder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResourceSchemaBuilder::class)]
class ResourceSchemaBuilderTest extends TestCase
{
    /**
     * Test that exactly one schema is emitted per registered resource, keyed by
     * its PascalCase component name.
     *
     * @return void
     */
    public function testEmitsOneSchemaPerRegisteredResource(): void
    {
        $schemas = $this->makeBuilder($this->fullResourceMap())->build();

        static::assertCount(4, $schemas);
        static::assertArrayHasKey('User', $schemas);
        static::assertArrayHasKey('Post', $schemas);
        static::assertArrayHasKey('Tag', $schemas);
        static::assertArrayHasKey('Organization', $schemas);
    }

    /**
     * Test that every compiled scalar, relation, and projection field key of a
     * resource appears as a property of its emitted schema.
     *
     * @return void
     */
    public function testEveryCompiledFieldKeyBecomesAProperty(): void
    {
        $schemas    = $this->makeBuilder($this->fullResourceMap())->build();
        $properties = $schemas['User']['properties'];

        foreach (['id', 'name', 'email', 'status', 'created_at', 'updated_at', 'full_label'] as $fieldKey) {
            static::assertArrayHasKey($fieldKey, $properties);
        }

        static::assertArrayHasKey('organization', $properties);
        static::assertArrayHasKey('profile_bio', $properties);
        static::assertArrayHasKey('posts', $properties);
    }

    /**
     * Test that a clean count key (no colliding field) becomes a property of
     * its resource schema.
     *
     * @return void
     */
    public function testCountKeyBecomesAProperty(): void
    {
        $schemas = $this->makeBuilder($this->fullResourceMap())->build();

        static::assertArrayHasKey('users', $schemas['Organization']['properties']);
    }

    /**
     * Test that the emitted schema is a JSON Schema object type.
     *
     * @return void
     */
    public function testSchemaIsAnObjectType(): void
    {
        $schemas = $this->makeBuilder($this->fullResourceMap())->build();

        static::assertSame('object', $schemas['Tag']['type']);
    }

    /**
     * Test that an inferred scalar field carries a concrete type derived from
     * its backing column.
     *
     * @return void
     */
    public function testInferredScalarFieldCarriesConcreteType(): void
    {
        $schemas = $this->makeBuilder($this->fullResourceMap())->build();

        static::assertSame('integer', $schemas['User']['properties']['id']['type']);
        static::assertSame('string', $schemas['User']['properties']['name']['type']);
    }

    /**
     * Test that a plain scalar field backed by a string column is inferred as a
     * string without a format hint.
     *
     * @return void
     */
    public function testPlainScalarStringFieldIsInferred(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['User']['properties']['status'];

        static::assertSame('string', $property['type']);
        static::assertArrayNotHasKey('x-undocumented', $property);
    }

    /**
     * Test that a relation field with a child resource emits the conservative
     * object-or-array reference shape, nullable and flagged with an unknown
     * cardinality.
     *
     * @return void
     */
    public function testRelationFieldEmitsConservativeReferenceShape(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['User']['properties']['organization'];

        static::assertArrayHasKey('oneOf', $property);
        static::assertSame(['$ref' => '#/components/schemas/Organization'], $property['oneOf'][0]);
        static::assertSame('array', $property['oneOf'][1]['type']);
        static::assertSame(['$ref' => '#/components/schemas/Organization'], $property['oneOf'][1]['items']);
        static::assertSame(['type' => 'null'], $property['oneOf'][2]);
        static::assertArrayNotHasKey('nullable', $property);
        static::assertSame('unknown', $property['x-cardinality']);
    }

    /**
     * Test that a string-projection relation with no child resource is not
     * emitted as a reference but flagged undocumented through the resolver.
     *
     * @return void
     */
    public function testProjectionRelationIsFlaggedNotReferenced(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['User']['properties']['profile_bio'];

        static::assertArrayNotHasKey('oneOf', $property);
        static::assertTrue($property['x-undocumented']);
    }

    /**
     * Test that a count key emits a non-negative integer property.
     *
     * @return void
     */
    public function testCountKeyEmitsNonNegativeInteger(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['Organization']['properties']['users'];

        static::assertSame('integer', $property['type']);
        static::assertSame(0, $property['minimum']);
    }

    /**
     * Test that a count never overwrites a colliding relation property: the
     * richer relation reference shape is preserved.
     *
     * @return void
     */
    public function testCountDoesNotOverwriteCollidingRelation(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['User']['properties']['posts'];

        static::assertArrayHasKey('oneOf', $property);
        static::assertSame(['$ref' => '#/components/schemas/Post'], $property['oneOf'][0]);
    }

    /**
     * Test that non-guarded scalar field keys are listed as required while
     * relations and counts are not.
     *
     * @return void
     */
    public function testRequiredListsScalarKeysButNotRelationsOrCounts(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $required = $schemas['User']['required'];

        static::assertContains('id', $required);
        static::assertContains('name', $required);
        static::assertContains('full_label', $required);
        static::assertNotContains('organization', $required);
        static::assertNotContains('posts', $required);
        static::assertNotContains('profile_bio', $required);
    }

    /**
     * Test that a count present key never appears in the required list.
     *
     * @return void
     */
    public function testCountKeyIsNeverRequired(): void
    {
        $schemas = $this->makeBuilder($this->fullResourceMap())->build();
        $org     = $schemas['Organization'];

        static::assertArrayHasKey('required', $org);
        static::assertNotContains('users', $org['required']);
    }

    /**
     * Test that a field whose type cannot be resolved keeps its undocumented
     * marker and remains schema-valid (admits any value).
     *
     * @return void
     */
    public function testUndocumentedFieldKeepsItsMarker(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['User']['properties']['full_label'];

        static::assertTrue($property['x-undocumented']);
        static::assertArrayNotHasKey('type', $property);
    }

    /**
     * Test that a relation field reference targets the related resource's
     * component schema by name.
     *
     * @return void
     */
    public function testRelationReferenceTargetsRelatedComponent(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['Post']['properties']['user'];

        static::assertSame(['$ref' => '#/components/schemas/User'], $property['oneOf'][0]);
    }

    /**
     * Build a ResourceSchemaBuilder backed by a stubbed catalogue returning the
     * given resource map, and a real resolver against the live test schema.
     *
     * @param  array<class-string, class-string>  $resourceMap
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder
     */
    private function makeBuilder(array $resourceMap): ResourceSchemaBuilder
    {
        $catalogue = static::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn($resourceMap);

        return new ResourceSchemaBuilder($catalogue, $this->resolver());
    }

    /**
     * Build a real field-type resolver against the container-bound introspector.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver
     */
    private function resolver(): FieldTypeResolver
    {
        return new FieldTypeResolver(new SchemaIntrospector, new ColumnTypeMapper);
    }

    /**
     * The full fixture resource map.
     *
     * @return array<class-string, class-string>
     */
    private function fullResourceMap(): array
    {
        return [
            User::class         => UserResource::class,
            Post::class         => PostResource::class,
            Tag::class          => TagResource::class,
            Organization::class => OrganizationResource::class,
        ];
    }
}
