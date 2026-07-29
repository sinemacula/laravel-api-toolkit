<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Exceptions\SchemaNameCollisionException;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Profile;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\DeclaredOpenApiUserResource;
use Tests\Fixtures\Resources\GuardedUserResource;
use Tests\Fixtures\Resources\NullableFilterableUserResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Resources\V1\UserResource as V1UserResource;
use Tests\Fixtures\Resources\V2\UserResource as V2UserResource;
use Tests\Fixtures\Resources\V3\UserResource as V3UserResource;
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
final class ResourceSchemaBuilderTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Tear down the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Test that a compiled field key that resolves to no field definition is
     * skipped when building the schema, emitting an empty-property object.
     *
     * @return void
     */
    public function testSkipsFieldKeyWithoutADefinition(): void
    {
        // A compiled schema whose only field key maps to a null definition -
        // the builder must skip it rather than resolve a property.
        $schema = new CompiledSchema(['ghost' => null], []); // @phpstan-ignore argument.type

        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['GhostResource' => $schema]);

        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn(['GhostModel' => 'GhostResource']);

        $schemas = (new ResourceSchemaBuilder($catalogue, $this->resolver(), $this->introspector()))->build();

        self::assertSame(['type' => 'object', 'properties' => []], $schemas['Ghost']);
    }

    /**
     * Test that a null field definition only skips its own key: later field
     * keys in the same compiled schema are still emitted as properties.
     *
     * @return void
     */
    public function testNullFieldKeySkipsOnlyItselfNotLaterKeys(): void
    {
        $relation = new CompiledFieldDefinition(
            accessor: null,
            compute: null,
            relation: 'posts',
            resource: 'PostResource',
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [],
            transformers: [],
        );

        // The null 'ghost' key precedes a valid relation key; skipping the null
        // must not abandon the rest of the loop.
        $schema = new CompiledSchema(['ghost' => null, 'posts' => $relation], []); // @phpstan-ignore argument.type

        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['GhostResource' => $schema]);

        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn(['GhostModel' => 'GhostResource']);

        $properties = (new ResourceSchemaBuilder($catalogue, $this->resolver(), $this->introspector()))->build()['Ghost']['properties'];

        self::assertArrayNotHasKey('ghost', $properties);
        self::assertArrayHasKey('posts', $properties);
        self::assertSame(['$ref' => '#/components/schemas/Post'], $properties['posts']['oneOf'][0]);
    }

    /**
     * Test that exactly one schema is emitted per registered resource, keyed by
     * its PascalCase component name.
     *
     * @return void
     */
    public function testEmitsOneSchemaPerRegisteredResource(): void
    {
        $schemas = $this->makeBuilder($this->fullResourceMap())->build();

        self::assertCount(4, $schemas);
        self::assertArrayHasKey('User', $schemas);
        self::assertArrayHasKey('Post', $schemas);
        self::assertArrayHasKey('Tag', $schemas);
        self::assertArrayHasKey('Organization', $schemas);
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
            self::assertArrayHasKey($fieldKey, $properties);
        }

        self::assertArrayHasKey('organization', $properties);
        self::assertArrayHasKey('profile_bio', $properties);
        self::assertArrayHasKey('posts', $properties);
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

        self::assertArrayHasKey('users', $schemas['Organization']['properties']);
    }

    /**
     * Test that the emitted schema is a JSON Schema object type.
     *
     * @return void
     */
    public function testSchemaIsAnObjectType(): void
    {
        $schemas = $this->makeBuilder($this->fullResourceMap())->build();

        self::assertSame('object', $schemas['Tag']['type']);
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

        self::assertSame('integer', $schemas['User']['properties']['id']['type']);
        self::assertSame('string', $schemas['User']['properties']['name']['type']);
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

        self::assertSame('string', $property['type']);
        self::assertArrayNotHasKey('x-undocumented', $property);
    }

    /**
     * Test that a resolved to-one relation (a BelongsTo) emits a single
     * reference to the related component with no cardinality flag.
     *
     * @return void
     */
    public function testToOneBelongsToRelationEmitsSingleReference(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['User']['properties']['organization'];

        self::assertSame(['$ref' => '#/components/schemas/Organization'], $property);
        self::assertArrayNotHasKey('oneOf', $property);
        self::assertArrayNotHasKey('x-cardinality', $property);
    }

    /**
     * Test that a resolved to-many relation (a HasMany) emits an array of
     * references to the related component with no cardinality flag.
     *
     * @return void
     */
    public function testToManyHasManyRelationEmitsArrayOfReferences(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['User']['properties']['posts'];

        self::assertSame('array', $property['type']);
        self::assertSame(['$ref' => '#/components/schemas/Post'], $property['items']);
        self::assertArrayNotHasKey('oneOf', $property);
        self::assertArrayNotHasKey('x-cardinality', $property);
    }

    /**
     * Test that a resolved many-to-many relation (a BelongsToMany) emits an
     * array of references to the related component.
     *
     * @return void
     */
    public function testToManyBelongsToManyRelationEmitsArrayOfReferences(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['Post']['properties']['tags'];

        self::assertSame('array', $property['type']);
        self::assertSame(['$ref' => '#/components/schemas/Tag'], $property['items']);
        self::assertArrayNotHasKey('x-cardinality', $property);
    }

    /**
     * Test that a relation whose owning model cannot be instantiated falls back
     * to the conservative object-or-array-or-null reference shape flagged with
     * an unknown cardinality.
     *
     * @return void
     */
    public function testUnresolvableRelationFallsBackToConservativeShape(): void
    {
        $relation = new CompiledFieldDefinition(
            accessor: null,
            compute: null,
            relation: 'posts',
            resource: 'PostResource',
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [],
            transformers: [],
        );

        $schema = new CompiledSchema(['posts' => $relation], []); // @phpstan-ignore argument.type

        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['GhostResource' => $schema]);

        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn(['GhostModel' => 'GhostResource']);

        $property = (new ResourceSchemaBuilder($catalogue, $this->resolver(), $this->introspector()))
            ->build()['Ghost']['properties']['posts'];

        self::assertSame(['$ref' => '#/components/schemas/Post'], $property['oneOf'][0]);
        self::assertSame('array', $property['oneOf'][1]['type']);
        self::assertSame(['$ref' => '#/components/schemas/Post'], $property['oneOf'][1]['items']);
        self::assertSame(['type' => 'null'], $property['oneOf'][2]);
        self::assertSame('unknown', $property['x-cardinality']);
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

        self::assertArrayNotHasKey('oneOf', $property);
        self::assertTrue($property['x-undocumented']);
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

        self::assertSame('integer', $property['type']);
        self::assertSame(0, $property['minimum']);
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

        self::assertSame('array', $property['type']);
        self::assertSame(['$ref' => '#/components/schemas/Post'], $property['items']);
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

        self::assertContains('id', $required);
        self::assertContains('name', $required);
        self::assertContains('full_label', $required);
        self::assertNotContains('organization', $required);
        self::assertNotContains('posts', $required);
        self::assertNotContains('profile_bio', $required);
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

        self::assertArrayHasKey('required', $org);
        self::assertNotContains('users', $org['required']);
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

        self::assertTrue($property['x-undocumented']);
        self::assertArrayNotHasKey('type', $property);
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

        self::assertSame(['$ref' => '#/components/schemas/User'], $property);
    }

    /**
     * Test that every resource schema leads with a `_type` discriminator fixed
     * to the resource's registered type via a constant.
     *
     * @return void
     */
    public function testTypeDiscriminatorIsAConstantOfTheResourceType(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['User']['properties']['_type'];

        self::assertSame('string', $property['type']);
        self::assertSame('users', $property['const']);
    }

    /**
     * Test that the `_type` discriminator is listed as a required property of
     * the resource schema.
     *
     * @return void
     */
    public function testTypeDiscriminatorIsRequired(): void
    {
        $schemas = $this->makeBuilder($this->fullResourceMap())->build();

        self::assertContains('_type', $schemas['User']['required']);
    }

    /**
     * Test that a resource whose class cannot be resolved as an API resource
     * omits the `_type` discriminator rather than resolving a type from a
     * non-existent class.
     *
     * @return void
     */
    public function testTypeDiscriminatorIsOmittedForUnresolvableResource(): void
    {
        $schema = new CompiledSchema([], []);

        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['GhostResource' => $schema]);

        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn(['GhostModel' => 'GhostResource']);

        $ghost = (new ResourceSchemaBuilder($catalogue, $this->resolver(), $this->introspector()))->build()['Ghost'];

        self::assertArrayNotHasKey('_type', $ghost['properties']);
        self::assertArrayNotHasKey('required', $ghost);
    }

    /**
     * Test that a resolved HasOne relation is classified as to-one, emitting a
     * single reference, while a to-many relation on the same model is not.
     *
     * @return void
     */
    public function testHasOneRelationIsClassifiedAsToOne(): void
    {
        $builder = $this->makeBuilder([]);

        self::assertTrue($this->invokeMethod($builder, 'isToOne', (new User)->hasOne(Profile::class)));
        self::assertFalse($this->invokeMethod($builder, 'isToOne', (new User)->hasMany(Post::class)));
    }

    /**
     * Test that a resolved MorphOne relation is classified as to-one, emitting
     * a single reference rather than an array of references.
     *
     * @return void
     */
    public function testMorphOneRelationIsClassifiedAsToOne(): void
    {
        $builder = $this->makeBuilder([]);

        self::assertTrue($this->invokeMethod($builder, 'isToOne', (new User)->morphOne(Profile::class, 'imageable')));
    }

    /**
     * Test that a resolved HasOneThrough relation is classified as to-one
     * despite descending from the to-many HasManyThrough base class.
     *
     * @return void
     */
    public function testHasOneThroughRelationIsClassifiedAsToOne(): void
    {
        $builder = $this->makeBuilder([]);

        self::assertTrue($this->invokeMethod($builder, 'isToOne', (new User)->hasOneThrough(Profile::class, Organization::class)));
    }

    /**
     * Test that a guarded scalar field is emitted as an undocumented, optional
     * property while a non-guarded sibling scalar remains required.
     *
     * @return void
     */
    public function testGuardedScalarIsUndocumentedAndOptionalWhileSiblingIsRequired(): void
    {
        $schema = $this->makeBuilder([User::class => GuardedUserResource::class])->build()['GuardedUser'];

        self::assertSame(['x-undocumented' => true], $schema['properties']['email']);
        self::assertContains('id', $schema['required']);
        self::assertNotContains('email', $schema['required']);
    }

    /**
     * Test that a field carrying a full author-declared OpenAPI contract is
     * emitted verbatim through the compiler and builder, applying the 2020-12
     * nullable type-array form.
     *
     * @return void
     */
    public function testFullOpenApiOverrideSurvivesVerbatim(): void
    {
        $schema = $this->makeBuilder([User::class => DeclaredOpenApiUserResource::class])->build()['DeclaredOpenApiUser'];

        self::assertSame([
            'type'        => ['string', 'null'],
            'format'      => 'color',
            'enum'        => ['bronze', 'silver', 'gold'],
            'example'     => 'gold',
            'description' => 'The membership tier of the user',
        ], $schema['properties']['tier']);
    }

    /**
     * Test that a to-one relation emits a single reference while a to-many
     * relation on the same resource emits an array of references.
     *
     * @return void
     */
    public function testToOneEmitsSingleRefAndToManyEmitsArrayOfRefs(): void
    {
        $properties = $this->makeBuilder($this->fullResourceMap())->build()['User']['properties'];

        self::assertSame(['$ref' => '#/components/schemas/Organization'], $properties['organization']);
        self::assertSame([
            'type'  => 'array',
            'items' => ['$ref' => '#/components/schemas/Post'],
        ], $properties['posts']);
    }

    /**
     * Test that a nullable inferred scalar emits the 2020-12 null type member
     * while a non-nullable sibling scalar emits a bare scalar type.
     *
     * @return void
     */
    public function testNullableInferredScalarEmitsNullMemberWhileSiblingDoesNot(): void
    {
        $properties = $this->makeBuilder([User::class => NullableFilterableUserResource::class])
            ->build()['NullableFilterableUser']['properties'];

        self::assertSame(['integer', 'null'], $properties['organization_id']['type']);
        self::assertSame('string', $properties['name']['type']);
    }

    /**
     * Test that two distinct resources deriving the same component name make
     * the build fail loud, naming the collided name and both resource classes.
     *
     * @return void
     */
    public function testCollidingComponentNamesThrow(): void
    {
        $builder = $this->makeBuilder([
            User::class => V1UserResource::class,
            Post::class => V2UserResource::class,
        ]);

        $this->expectException(SchemaNameCollisionException::class);
        $this->expectExceptionMessage(V1UserResource::class);
        $this->expectExceptionMessage(V2UserResource::class);

        $builder->build();
    }

    /**
     * Test that the same resource claimed under two models does not collide, so
     * a resource shared across models still builds a single schema.
     *
     * @return void
     */
    public function testSameResourceUnderTwoModelsDoesNotThrow(): void
    {
        $schemas = $this->makeBuilder([
            User::class => V1UserResource::class,
            Post::class => V1UserResource::class,
        ])->build();

        self::assertCount(1, $schemas);
        self::assertArrayHasKey('User', $schemas);
    }

    /**
     * Test that a #[SchemaName] override on one of two basename-colliding
     * resources disambiguates them, so both schemas survive under distinct
     * keys.
     *
     * @return void
     */
    public function testSchemaNameOverrideAvoidsCollision(): void
    {
        $schemas = $this->makeBuilder([
            User::class => V1UserResource::class,
            Post::class => V3UserResource::class,
        ])->build();

        self::assertCount(2, $schemas);
        self::assertArrayHasKey('User', $schemas);
        self::assertArrayHasKey('UserV3', $schemas);
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
        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn($resourceMap);

        return new ResourceSchemaBuilder($catalogue, $this->resolver(), $this->introspector());
    }

    /**
     * Build a real field-type resolver against the container-bound
     * introspector.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver
     */
    private function resolver(): FieldTypeResolver
    {
        return new FieldTypeResolver($this->introspector(), new ColumnTypeMapper);
    }

    /**
     * Resolve the container-bound schema introspector.
     *
     * @return \SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector
     */
    private function introspector(): SchemaIntrospector
    {
        assert($this->app !== null);

        return $this->app->make(SchemaIntrospector::class);
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
