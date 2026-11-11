<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
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
use Tests\Fixtures\Resources\SearchableUserResource;
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
     * Test that a field key with no definition is skipped.
     *
     * @return void
     */
    public function testSkipsFieldKeyWithoutADefinition(): void
    {
        $schema = new CompiledSchema(['ghost' => null], []); // @phpstan-ignore argument.type

        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['GhostResource' => $schema]);

        $schemas = $this->makeGhostBuilder(['GhostModel' => 'GhostResource'])->build();

        self::assertSame(['type' => 'object', 'properties' => []], $schemas['Ghost']);
    }

    /**
     * Test that a null field definition skips only its own key.
     *
     * @return void
     */
    public function testNullFieldKeySkipsOnlyItselfNotLaterKeys(): void
    {
        $relation = new CompiledFieldDefinition(
            accessor: null,
            compute: null,
            relation: 'posts',
            resource: PostResource::class,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [],
            transformers: [],
        );

        $schema = new CompiledSchema(['ghost' => null, 'posts' => $relation], []); // @phpstan-ignore argument.type

        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['GhostResource' => $schema]);

        $properties = $this->makeGhostBuilder(['GhostModel' => 'GhostResource', Post::class => PostResource::class])
            ->build()['Ghost']['properties'];

        self::assertIsArray($properties);
        self::assertArrayNotHasKey('ghost', $properties);
        self::assertIsArray($properties);
        self::assertArrayHasKey('posts', $properties);
        self::assertSame(['$ref' => '#/components/schemas/Post'], $properties['posts']['oneOf'][0]);
    }

    /**
     * Test that one schema is emitted per resource under its component name.
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
     * Test that every compiled field key appears as a schema property.
     *
     * @return void
     */
    public function testEveryCompiledFieldKeyBecomesAProperty(): void
    {
        $schemas    = $this->makeBuilder($this->fullResourceMap())->build();
        $properties = $schemas['User']['properties'];

        self::assertIsArray($properties);

        foreach (['id', 'name', 'email', 'status', 'created_at', 'updated_at', 'full_label'] as $fieldKey) {
            self::assertArrayHasKey($fieldKey, $properties);
        }

        self::assertArrayHasKey('organization', $properties);
        self::assertArrayHasKey('profile_bio', $properties);
        self::assertArrayHasKey('posts', $properties);
    }

    /**
     * Test that a non-colliding count key becomes a schema property.
     *
     * @return void
     */
    public function testCountKeyBecomesAProperty(): void
    {
        $schemas = $this->makeBuilder($this->fullResourceMap())->build();

        self::assertIsArray($schemas['Organization']['properties']);
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
     * Test that an inferred scalar carries a type from its backing column.
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
     * Test that a backed enum cast references the enum's component.
     *
     * @return void
     */
    public function testBackedEnumCastFieldEmitsComponentReference(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['User']['properties']['status'];

        self::assertSame('#/components/schemas/UserStatus', $property['$ref']);
        self::assertIsArray($property);
        self::assertArrayNotHasKey('type', $property);
        self::assertIsArray($property);
        self::assertArrayNotHasKey('x-undocumented', $property);
    }

    /**
     * Test that a to-one relation emits a single reference.
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
     * Test that a to-many relation emits an array of references.
     *
     * @return void
     */
    public function testToManyHasManyRelationEmitsArrayOfReferences(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['User']['properties']['posts'];

        self::assertSame('array', $property['type']);
        self::assertSame(['$ref' => '#/components/schemas/Post'], $property['items']);
        self::assertIsArray($property);
        self::assertArrayNotHasKey('oneOf', $property);
        self::assertIsArray($property);
        self::assertArrayNotHasKey('x-cardinality', $property);
    }

    /**
     * Test that a many-to-many relation emits an array of references.
     *
     * @return void
     */
    public function testToManyBelongsToManyRelationEmitsArrayOfReferences(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['Post']['properties']['tags'];

        self::assertSame('array', $property['type']);
        self::assertSame(['$ref' => '#/components/schemas/Tag'], $property['items']);
        self::assertIsArray($property);
        self::assertArrayNotHasKey('x-cardinality', $property);
    }

    /**
     * Test that an uninstantiable owner falls back to the unknown shape.
     *
     * @return void
     */
    public function testUnresolvableRelationFallsBackToConservativeShape(): void
    {
        $relation = new CompiledFieldDefinition(
            accessor: null,
            compute: null,
            relation: 'posts',
            resource: PostResource::class,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [],
            transformers: [],
        );

        $schema = new CompiledSchema(['posts' => $relation], []); // @phpstan-ignore argument.type

        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['GhostResource' => $schema]);

        $property = $this->makeGhostBuilder(['GhostModel' => 'GhostResource', Post::class => PostResource::class])
            ->build()['Ghost']['properties']['posts'];

        self::assertSame(['$ref' => '#/components/schemas/Post'], $property['oneOf'][0]);
        self::assertSame('array', $property['oneOf'][1]['type']);
        self::assertSame(['$ref' => '#/components/schemas/Post'], $property['oneOf'][1]['items']);
        self::assertSame(['type' => 'null'], $property['oneOf'][2]);
        self::assertSame('unknown', $property['x-cardinality']);
    }

    /**
     * Test that a projection without a child resource is undocumented.
     *
     * @return void
     */
    public function testProjectionRelationIsFlaggedNotReferenced(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['User']['properties']['profile_bio'];

        self::assertIsArray($property);
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
     * Test that a count never overwrites a colliding relation property.
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
     * Test that unguarded scalars are required but relations and counts not.
     *
     * @return void
     */
    public function testRequiredListsScalarKeysButNotRelationsOrCounts(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $required = $schemas['User']['required'];

        self::assertIsIterable($required);
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
        self::assertIsIterable($org['required']);
        self::assertNotContains('users', $org['required']);
    }

    /**
     * Test that an unresolvable field stays undocumented and schema-valid.
     *
     * @return void
     */
    public function testUndocumentedFieldKeepsItsMarker(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['User']['properties']['full_label'];

        self::assertTrue($property['x-undocumented']);
        self::assertIsArray($property);
        self::assertArrayNotHasKey('type', $property);
    }

    /**
     * Test that a relation reference targets the related component by name.
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
     * Test that every resource schema leads with a constant `_type` field.
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
     * Test that the `_type` discriminator is required.
     *
     * @return void
     */
    public function testTypeDiscriminatorIsRequired(): void
    {
        $schemas = $this->makeBuilder($this->fullResourceMap())->build();

        self::assertIsIterable($schemas['User']['required']);
        self::assertContains('_type', $schemas['User']['required']);
    }

    /**
     * Test that an unresolvable resource class omits the `_type` field.
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

        self::assertIsArray($ghost['properties']);
        self::assertArrayNotHasKey('_type', $ghost['properties']);
        self::assertArrayNotHasKey('required', $ghost);
    }

    /**
     * Test that a HasOne relation is classified as to-one.
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
     * Test that a MorphOne relation is classified as to-one.
     *
     * @return void
     */
    public function testMorphOneRelationIsClassifiedAsToOne(): void
    {
        $builder = $this->makeBuilder([]);

        self::assertTrue($this->invokeMethod($builder, 'isToOne', (new User)->morphOne(Profile::class, 'imageable')));
    }

    /**
     * Test that a HasOneThrough relation is classified as to-one.
     *
     * @return void
     */
    public function testHasOneThroughRelationIsClassifiedAsToOne(): void
    {
        $builder = $this->makeBuilder([]);

        self::assertTrue($this->invokeMethod($builder, 'isToOne', (new User)->hasOneThrough(Profile::class, Organization::class)));
    }

    /**
     * Test that a guarded scalar is undocumented and optional.
     *
     * @return void
     */
    public function testGuardedScalarIsUndocumentedAndOptionalWhileSiblingIsRequired(): void
    {
        $schema = $this->makeBuilder([User::class => GuardedUserResource::class])->build()['GuardedUser'];

        self::assertSame(['x-undocumented' => true], $schema['properties']['email']);
        self::assertIsIterable($schema['required']);
        self::assertContains('id', $schema['required']);
        self::assertNotContains('email', $schema['required']);
    }

    /**
     * Test that a declared OpenAPI contract is emitted verbatim.
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
     * Test that to-one and to-many relations differ in reference shape.
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
     * Test that a nullable scalar emits the 2020-12 null type member.
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
     * Test that a filterable field documents its key, capability and operators.
     *
     * @return void
     */
    public function testFilterableFieldDocumentsItsCapabilityAndPermittedOperators(): void
    {
        $properties = $this->makeBuilder($this->fullResourceMap())->build()['User']['properties'];

        self::assertSame([
            'key'        => 'id',
            'capability' => 'range',
            'operators'  => ['$eq', '$in', '$gt', '$ge', '$lt', '$le', '$between', '$null', '$notNull'],
        ], $properties['id']['x-query-surface']['filter']);

        self::assertSame([
            'key'        => 'status',
            'capability' => 'enum',
            'operators'  => ['$eq', '$in', '$neq', '$null', '$notNull'],
        ], $properties['status']['x-query-surface']['filter']);
    }

    /**
     * Test that a field declaring nothing queryable has no query surface.
     *
     * @return void
     */
    public function testFieldDeclaringNothingQueryableCarriesNoSurface(): void
    {
        $properties = $this->makeBuilder($this->fullResourceMap())->build()['User']['properties'];

        self::assertIsArray($properties['full_label']);
        self::assertArrayNotHasKey('x-query-surface', $properties['full_label']);
    }

    /**
     * Test that a sortable-only field documents its order as index-backed.
     *
     * @return void
     */
    public function testSortableFieldWithoutAnExemptionIsDocumentedIndexBacked(): void
    {
        $properties = $this->makeBuilder($this->fullResourceMap())->build()['User']['properties'];

        self::assertSame(
            ['sort' => ['key' => 'created_at', 'indexed' => true]],
            $properties['created_at']['x-query-surface'],
        );
    }

    /**
     * Test that an index-exempt sortable column documents it and the reason.
     *
     * @return void
     */
    public function testIndexExemptSortableFieldDocumentsWhyItIsUnindexed(): void
    {
        $field = $this->queryableField(sortable: 'name', unindexedReason: 'The table holds a fixed handful of rows');

        $properties = $this->buildGhost(new CompiledSchema(['name' => $field], [], [], [], ['name']))['properties'];

        self::assertSame(
            ['sort' => ['key' => 'name', 'indexed' => false, 'reason' => 'The table holds a fixed handful of rows']],
            $properties['name']['x-query-surface'],
        );
    }

    /**
     * Test that a searchable field documents its match strategy.
     *
     * @return void
     */
    public function testSearchableFieldDocumentsItsMatchStrategy(): void
    {
        $properties = $this->makeBuilder([User::class => SearchableUserResource::class])
            ->build()['SearchableUser']['properties'];

        self::assertSame(['key' => 'id', 'strategy' => 'exact'], $properties['id']['x-query-surface']['search']);
        self::assertSame(['key' => 'name', 'strategy' => 'substring'], $properties['name']['x-query-surface']['search']);
        self::assertIsArray($properties['status']);
        self::assertArrayNotHasKey('x-query-surface', $properties['status']);
    }

    /**
     * Test that a declaration absent from the compiled sets is not documented.
     *
     * @return void
     */
    public function testDeclarationTheCompiledQuerySetsOmitIsNotDocumented(): void
    {
        $field = $this->queryableField(
            filterable: 'email',
            capability: Capability::EXACT,
            sortable: 'email',
            searchable: 'email',
            strategy: SearchStrategy::PREFIX,
        );

        $properties = $this->buildGhost(new CompiledSchema(['email' => $field], []))['properties'];

        self::assertIsArray($properties['email']);
        self::assertArrayNotHasKey('x-query-surface', $properties['email']);
    }

    /**
     * Test that an aliased field documents its query-grammar key.
     *
     * @return void
     */
    public function testAliasedFieldDocumentsTheKeyTheQueryGrammarAccepts(): void
    {
        $field = $this->queryableField(filterable: 'email', capability: Capability::EXACT);

        $schema = new CompiledSchema(['email_address' => $field], [], [], ['email' => Capability::EXACT]);

        $properties = $this->buildGhost($schema)['properties'];

        self::assertSame('email', $properties['email_address']['x-query-surface']['filter']['key']);
    }

    /**
     * Test that an operator gone from the vocabulary is dropped.
     *
     * @return void
     */
    public function testAnOperatorTheVocabularyNoLongerHoldsIsNotDocumented(): void
    {
        $field  = $this->queryableField(filterable: 'id', capability: Capability::RANGE);
        $schema = new CompiledSchema(['id' => $field], [], [], ['id' => Capability::RANGE]);

        $vocabulary = array_values(array_diff($this->vocabulary(), ['$in', '$between']));
        $properties = $this->buildGhost($schema, $vocabulary)['properties'];

        self::assertSame([
            'key'        => 'id',
            'capability' => 'range',
            'operators'  => ['$eq', '$gt', '$ge', '$lt', '$le', '$null', '$notNull'],
        ], $properties['id']['x-query-surface']['filter']);
    }

    /**
     * Test that a column with no remaining operators has no filter surface.
     *
     * @return void
     */
    public function testAColumnLeftWithNoDispatchableOperatorCarriesNoFilterSurface(): void
    {
        $field  = $this->queryableField(filterable: 'payload', capability: Capability::DOCUMENT, sortable: 'payload');
        $schema = new CompiledSchema(
            ['payload' => $field],
            [],
            [],
            ['payload' => Capability::DOCUMENT],
            ['payload'],
        );

        $vocabulary = array_values(array_diff($this->vocabulary(), ['$contains']));
        $surface    = $this->buildGhost($schema, $vocabulary)['properties']['payload']['x-query-surface'];

        self::assertIsArray($surface);
        self::assertArrayNotHasKey('filter', $surface);
        self::assertSame(['key' => 'payload', 'indexed' => true], $surface['sort']);
    }

    /**
     * Test that an unregistered relation target emits an inline shape.
     *
     * @return void
     */
    public function testRelationToAnUnregisteredResourceEmitsNoDanglingReference(): void
    {
        $properties = $this->makeBuilder([User::class => UserResource::class])->build()['User']['properties'];

        self::assertSame(['type' => 'object', 'x-undocumented' => true], $properties['organization']);
        self::assertSame(
            ['type' => 'array', 'items' => ['type' => 'object', 'x-undocumented' => true]],
            $properties['posts'],
        );
    }

    /**
     * Test that a registered relation target emits its reference.
     *
     * @return void
     */
    public function testRelationToARegisteredResourceKeepsItsReference(): void
    {
        $properties = $this->makeBuilder($this->fullResourceMap())->build()['User']['properties'];

        self::assertSame(['$ref' => '#/components/schemas/Organization'], $properties['organization']);
        self::assertSame(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Post']], $properties['posts']);
    }

    /**
     * Test that the schema names the relations a filter may descend through.
     *
     * @return void
     */
    public function testTraversableRelationsAreNamedOnTheSchema(): void
    {
        $schema = $this->makeBuilder($this->fullResourceMap())->build()['User'];

        self::assertSame(['organization', 'posts'], $schema['x-traversable-relations']);
    }

    /**
     * Test that a resource without traversable relations omits the key.
     *
     * @return void
     */
    public function testResourceWithoutTraversableRelationsCarriesNoRelationKey(): void
    {
        $schema = $this->makeBuilder($this->fullResourceMap())->build()['Tag'];

        self::assertArrayNotHasKey('x-traversable-relations', $schema);
    }

    /**
     * Test that two resources deriving one component name fail loud.
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
     * Test that one resource shared across models builds a single schema.
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
     * Test that a #[SchemaName] override disambiguates colliding resources.
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
     * Build the schema of a synthetic resource compiled from the given schema.
     *
     * The user model backs it so scalar properties resolve against a table.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @param  array<int, string>|null  $vocabulary
     * @return array<string, mixed>
     */
    private function buildGhost(CompiledSchema $schema, ?array $vocabulary = null): array
    {
        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['GhostResource' => $schema]);

        return $this->makeGhostBuilder([User::class => 'GhostResource'], $vocabulary)->build()['Ghost'];
    }

    /**
     * Build a compiled field definition with only the given query declarations.
     *
     * @param  string|null  $filterable
     * @param  \SineMacula\ApiToolkit\Enums\Capability|null  $capability
     * @param  string|null  $sortable
     * @param  string|null  $unindexedReason
     * @param  string|null  $searchable
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy|null  $strategy
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function queryableField(
        ?string $filterable = null,
        ?Capability $capability = null,
        ?string $sortable = null,
        ?string $unindexedReason = null,
        ?string $searchable = null,
        ?SearchStrategy $strategy = null,
    ): CompiledFieldDefinition {
        return new CompiledFieldDefinition(
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
            openApi: null,
            filterable: $filterable,
            filterCapability: $capability,
            sortable: $sortable,
            unindexedReason: $unindexedReason,
            searchable: $searchable,
            searchStrategy: $strategy,
        );
    }

    /**
     * Build a ResourceSchemaBuilder over the given resource map.
     *
     * @param  array<class-string, class-string>  $resourceMap
     * @param  array<int, string>|null  $vocabulary
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder
     */
    private function makeBuilder(array $resourceMap, ?array $vocabulary = null): ResourceSchemaBuilder
    {
        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn($resourceMap);
        $catalogue->method('getOperatorTokens')->willReturn($vocabulary ?? $this->vocabulary());

        return new ResourceSchemaBuilder($catalogue, $this->resolver(), $this->introspector());
    }

    /**
     * Build a builder over a stubbed compiled schema keyed by an unmapped name.
     *
     * @param  array<string, string>  $resourceMap
     * @param  array<int, string>|null  $vocabulary
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder
     */
    private function makeGhostBuilder(array $resourceMap, ?array $vocabulary = null): ResourceSchemaBuilder
    {
        /** @var array<class-string, class-string> $resourceMap */
        return $this->makeBuilder($resourceMap, $vocabulary);
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
     * Build a real field-type resolver against the bound introspector.
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
