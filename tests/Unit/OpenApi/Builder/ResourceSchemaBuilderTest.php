<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Exceptions\SchemaNameCollisionException;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\AuthoredPost;
use Tests\Fixtures\Models\Note;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Profile;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\AuthoredPostResource;
use Tests\Fixtures\Resources\DeclaredOpenApiUserResource;
use Tests\Fixtures\Resources\FixedFieldsUserResource;
use Tests\Fixtures\Resources\GuardedQueryableUserResource;
use Tests\Fixtures\Resources\GuardedUserResource;
use Tests\Fixtures\Resources\NoteResource;
use Tests\Fixtures\Resources\NullableFilterableUserResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\ProfiledUserResource;
use Tests\Fixtures\Resources\ProfileResource;
use Tests\Fixtures\Resources\RestorableArticleResource;
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
     * Test that relation aggregates are emitted under one aggregates object,
     * never as top-level properties.
     *
     * @return void
     */
    public function testAggregatesAreNestedUnderOneProperty(): void
    {
        $schemas = $this->makeBuilder($this->fullResourceMap())->build();

        self::assertIsArray($schemas['Organization']['properties']);
        self::assertArrayNotHasKey('users_count', $schemas['Organization']['properties']);
        self::assertSame(
            ['type' => 'object', 'properties' => ['users_count' => ['type' => 'integer', 'minimum' => 0]]],
            $schemas['Organization']['properties']['aggregates'],
        );
        self::assertIsArray($schemas['Tag']['properties']);
        self::assertArrayNotHasKey('aggregates', $schemas['Tag']['properties']);
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
     * Test that a belongs-to relation admits its reference or null.
     *
     * @return void
     */
    public function testToOneBelongsToRelationAdmitsTheReferenceOrNull(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['User']['properties']['organization'];

        self::assertSame(['oneOf' => [['$ref' => '#/components/schemas/Organization'], ['type' => 'null']]], $property);
    }

    /**
     * Test that a has-one, morph-one and has-one-through relation each admit
     * their reference or null.
     *
     * @return void
     */
    public function testEveryToOneKindAdmitsTheReferenceOrNull(): void
    {
        $schemas = $this->makeBuilder($this->toOneResourceMap())->build();
        $profile = ['oneOf' => [['$ref' => '#/components/schemas/Profile'], ['type' => 'null']]];

        self::assertSame($profile, $schemas['ProfiledUser']['properties']['profile']);
        self::assertSame($profile, $schemas['AuthoredPost']['properties']['author_profile']);
        self::assertSame(['oneOf' => [['$ref' => '#/components/schemas/Note'], ['type' => 'null']]], $schemas['AuthoredPost']['properties']['pinned_note']);
    }

    /**
     * Test that a to-one relation declaring a default model keeps the bare
     * reference, since it never renders null.
     *
     * @return void
     */
    public function testToOneRelationWithADefaultModelKeepsTheBareReference(): void
    {
        $properties = $this->makeBuilder($this->toOneResourceMap())->build()['AuthoredPost']['properties'];

        self::assertIsArray($properties);
        self::assertSame(['$ref' => '#/components/schemas/ProfiledUser'], $properties['author']);
        self::assertSame(['$ref' => '#/components/schemas/Profile'], $properties['profile']);
    }

    /**
     * Test that a morph-to relation admits its target or null.
     *
     * @return void
     */
    public function testMorphToRelationAdmitsItsTargetOrNull(): void
    {
        $noteable   = $this->relationField(null, 'noteable');
        $properties = $this->buildGhost(new CompiledSchema(['noteable' => $noteable], []), modelClass: Note::class)['properties'];

        self::assertIsArray($properties);
        self::assertSame(['oneOf' => [['type' => 'object', 'x-undocumented' => true], ['type' => 'null']]], $properties['noteable']);
    }

    /**
     * Test that a callable default model keeps the bare target, while an empty
     * attribute default, which Eloquent treats as none, admits null.
     *
     * @return void
     */
    public function testCallableDefaultKeepsTheBareTargetAndAnEmptyDefaultAdmitsNull(): void
    {
        $schema = new CompiledSchema([
            'editor'   => $this->relationField(null, 'editor', UserResource::class),
            'reviewer' => $this->relationField(null, 'reviewer', UserResource::class),
        ], []);

        $properties   = $this->buildGhost($schema, modelClass: AuthoredPost::class)['properties'];
        $undocumented = ['type' => 'object', 'x-undocumented' => true];

        self::assertIsArray($properties);
        self::assertSame($undocumented, $properties['editor']);
        self::assertSame(['oneOf' => [$undocumented, ['type' => 'null']]], $properties['reviewer']);
    }

    /**
     * Test that a declared to-many relation drops the inferred bound but is
     * still documented as truncatable.
     *
     * @return void
     */
    public function testDeclaredToManyRelationDropsItsBoundButKeepsItsTruncation(): void
    {
        $posts      = $this->relationField(5, openApi: new OpenApiFieldSchema(type: 'integer'));
        $properties = $this->buildGhost(new CompiledSchema(['posts' => $posts], []))['properties'];

        self::assertIsArray($properties);
        self::assertSame(['type' => 'integer'], $properties['posts']);
        self::assertSame(['posts'], $properties['_truncated']['items']['enum']);
    }

    /**
     * Test that a relation's declared schema replaces the inferred one.
     *
     * @return void
     */
    public function testDeclaredRelationExportsItsDeclaration(): void
    {
        $properties = $this->makeBuilder($this->toOneResourceMap())->build()['ProfiledUser']['properties'];

        self::assertIsArray($properties);
        self::assertSame(['type' => ['string', 'null']], $properties['organization_name']);
    }

    /**
     * Test that a transformed relation without a declaration is undocumented
     * and unbounded, while its truncation is still documented.
     *
     * @return void
     */
    public function testTransformedRelationIsUndocumentedAndUnbounded(): void
    {
        $posts      = $this->relationField(5, transformers: [static fn ($resource, mixed $value): int => is_countable($value) ? count($value) : 0]);
        $properties = $this->buildGhost(new CompiledSchema(['posts' => $posts], []))['properties'];

        self::assertIsArray($properties);
        self::assertSame(['x-undocumented' => true], $properties['posts']);
        self::assertSame(['posts'], $properties['_truncated']['items']['enum']);
    }

    /**
     * Test that a guard leaves a relation's inferred schema in place.
     *
     * @return void
     */
    public function testGuardedRelationKeepsItsInferredSchema(): void
    {
        $posts      = $this->relationField(5, guards: [static fn (): bool => false]);
        $properties = $this->buildGhost(new CompiledSchema(['posts' => $posts], []))['properties'];

        self::assertIsArray($properties);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'object', 'x-undocumented' => true], 'maxItems' => 5], $properties['posts']);
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
     * Test that each function emits its own inner type, a minimum and maximum
     * taking the related column's type as nullable, and that the aggregates
     * object is never required.
     *
     * @return void
     */
    public function testAggregatesCarryThePerFunctionInnerTypes(): void
    {
        $schema = new CompiledSchema([], [
            'posts_count'          => $this->aggregate('posts_count', AggregateFunction::COUNT),
            'posts_exists'         => $this->aggregate('posts_exists', AggregateFunction::EXISTS),
            'posts_sum_id'         => $this->aggregate('posts_sum_id', AggregateFunction::SUM, 'id'),
            'posts_avg_id'         => $this->aggregate('posts_avg_id', AggregateFunction::AVG, 'id'),
            'posts_min_title'      => $this->aggregate('posts_min_title', AggregateFunction::MIN, 'title'),
            'posts_max_created_at' => $this->aggregate('posts_max_created_at', AggregateFunction::MAX, 'created_at'),
            'posts_max_published'  => $this->aggregate('posts_max_published', AggregateFunction::MAX, 'published'),
            'gone_max_id'          => $this->aggregate('gone_max_id', AggregateFunction::MAX, 'id', 'missing'),
        ]);

        $built = $this->buildGhost($schema);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'posts_count'          => ['type' => 'integer', 'minimum' => 0],
                'posts_exists'         => ['type' => 'boolean'],
                'posts_sum_id'         => ['type' => ['number', 'null']],
                'posts_avg_id'         => ['type' => ['number', 'null']],
                'posts_min_title'      => ['type' => ['string', 'null']],
                'posts_max_created_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'posts_max_published'  => ['type' => ['boolean', 'null']],
                'gone_max_id'          => ['x-undocumented' => true],
            ],
        ], $built['properties']['aggregates']);
        self::assertArrayNotHasKey('required', $built);
    }

    /**
     * Test that an aggregate never overwrites a relation sharing its name.
     *
     * @return void
     */
    public function testAggregateDoesNotOverwriteCollidingRelation(): void
    {
        $schemas  = $this->makeBuilder($this->fullResourceMap())->build();
        $property = $schemas['User']['properties']['posts'];

        self::assertSame('array', $property['type']);
        self::assertSame(['$ref' => '#/components/schemas/Post'], $property['items']);
    }

    /**
     * Test that a component requires only what every response carries and lists
     * its default fields, then its fixed ones.
     *
     * @return void
     */
    public function testRequiresOnlyTheTypeAndFixedColumnsAndListsTheDefaultFields(): void
    {
        $user = $this->makeBuilder($this->fullResourceMap())->build()['User'];

        self::assertSame(['_type', 'id'], $user['required']);
        self::assertSame(['id', 'name', 'email'], $user['x-default-fields']);
    }

    /**
     * Test that a field added to the configured fixed fields is required.
     *
     * @return void
     */
    public function testAConfiguredFixedColumnIsRequired(): void
    {
        Config::set('api-toolkit.resources.fixed_fields', ['id', '_type', 'status']);

        $user = $this->makeBuilder($this->fullResourceMap())->build()['User'];

        self::assertSame(['_type', 'id', 'status'], $user['required']);
        self::assertSame(['id', 'name', 'email', 'status'], $user['x-default-fields']);
    }

    /**
     * Test that a fixed field a guard, transformer, compute, accessor or
     * relation may leave out is not required, nor is an undeclared one.
     *
     * @return void
     */
    public function testOnlyAFixedPlainColumnIsRequired(): void
    {
        $schema = $this->makeBuilder([User::class => FixedFieldsUserResource::class])->build()['FixedFieldsUser'];

        self::assertSame(['_type', 'id', 'name'], $schema['required']);
    }

    /**
     * Test that a resource with no default fields lists only the fixed fields
     * its schema declares as its default fields.
     *
     * @return void
     */
    public function testNoDefaultFieldsListsOnlyTheDeclaredFixedFields(): void
    {
        $schema = $this->makeBuilder([User::class => FixedFieldsUserResource::class])->build()['FixedFieldsUser'];

        self::assertSame(
            ['id', 'email', 'status', 'full_label', 'display_label', 'organization', 'name'],
            $schema['x-default-fields'],
        );
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

        self::assertSame(['$ref' => '#/components/schemas/User'], $property['oneOf'][0]);
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
     * Test that a guarded scalar is typed from its column but optional.
     *
     * @return void
     */
    public function testGuardedScalarIsTypedFromItsColumnButOptional(): void
    {
        $schema = $this->makeBuilder([User::class => GuardedUserResource::class])->build()['GuardedUser'];

        self::assertSame(['type' => 'string'], $schema['properties']['email']);
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
     * Test that an ability flag exports as a described, non-nullable boolean.
     *
     * @return void
     */
    public function testAbilityFlagExportsAsADescribedBoolean(): void
    {
        $schema = $this->makeBuilder([Article::class => RestorableArticleResource::class])->build()['RestorableArticle'];

        self::assertSame([
            'type'        => 'boolean',
            'description' => 'Whether the current user passes the "restore" ability for this record.',
        ], $schema['properties']['can_restore']);
    }

    /**
     * Test that to-one and to-many relations differ in reference shape.
     *
     * @return void
     */
    public function testToOneEmitsANullableRefAndToManyEmitsArrayOfRefs(): void
    {
        $properties = $this->makeBuilder($this->fullResourceMap())->build()['User']['properties'];

        self::assertSame(['oneOf' => [['$ref' => '#/components/schemas/Organization'], ['type' => 'null']]], $properties['organization']);
        self::assertSame([
            'type'     => 'array',
            'items'    => ['$ref' => '#/components/schemas/Post'],
            'maxItems' => 25,
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
     * Test that a key only caller-guarded fields declare is marked conditional
     * for the filter and the sort independently.
     *
     * @return void
     */
    public function testConditionalKeysAreMarkedPerFilterAndSort(): void
    {
        $schema = new CompiledSchema(
            fields               : [
                'email' => $this->queryableField(filterable: 'email', capability: Capability::EXACT, sortable: 'email'),
                'name'  => $this->queryableField(filterable: 'name', capability: Capability::EXACT, sortable: 'name'),
            ],
            filterableColumns    : ['email' => Capability::EXACT, 'name' => Capability::EXACT],
            sortableColumns      : ['email', 'name'],
            conditionalFilterKeys: ['email' => ['email']],
            conditionalSortKeys  : ['name' => ['name']],
        );

        $properties = $this->buildGhost($schema)['properties'];
        $operators  = ['$eq', '$in', '$null', '$notNull'];

        self::assertSame([
            'filter' => ['key' => 'email', 'capability' => 'exact', 'operators' => $operators, 'conditional' => true],
            'sort'   => ['key' => 'email', 'indexed' => true],
        ], $properties['email']['x-query-surface']);

        self::assertSame([
            'filter' => ['key' => 'name', 'capability' => 'exact', 'operators' => $operators],
            'sort'   => ['key' => 'name', 'indexed' => true, 'conditional' => true],
        ], $properties['name']['x-query-surface']);
    }

    /**
     * Test that a fixed field a caller guard may leave out is not required.
     *
     * @return void
     */
    public function testACallerGuardedFixedFieldIsNotRequired(): void
    {
        Config::set('api-toolkit.resources.fixed_fields', ['id', '_type', 'name', 'email']);

        $schema = $this->makeBuilder([User::class => GuardedQueryableUserResource::class])->build()['GuardedQueryableUser'];

        self::assertSame(['_type', 'id', 'name'], $schema['required']);
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

        $properties = $this->buildGhost(new CompiledSchema(['name' => $field], [], [], ['name']))['properties'];

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

        $schema = new CompiledSchema(['email_address' => $field], [], ['email' => Capability::EXACT]);

        $properties = $this->buildGhost($schema)['properties'];

        self::assertSame('email', $properties['email_address']['x-query-surface']['filter']['key']);
    }

    /**
     * Test that a registered token is documented on the columns its
     * capabilities admit, and only those.
     *
     * @return void
     */
    public function testARegisteredTokenIsDocumentedOnlyOnTheColumnsItsCapabilitiesAdmit(): void
    {
        $schema = new CompiledSchema(
            ['id' => $this->queryableField(filterable: 'id', capability: Capability::RANGE), 'email' => $this->queryableField(filterable: 'email', capability: Capability::EXACT)],
            [],
            ['id' => Capability::RANGE, 'email' => Capability::EXACT],
        );

        $this->registry()->register('$starts', static function (): void {}, Capability::RANGE);

        $properties = $this->buildGhost($schema)['properties'];

        self::assertSame(['$eq', '$in', '$gt', '$ge', '$lt', '$le', '$between', '$null', '$notNull', '$starts'], $properties['id']['x-query-surface']['filter']['operators']);
        self::assertSame(['$eq', '$in', '$null', '$notNull'], $properties['email']['x-query-surface']['filter']['operators']);
    }

    /**
     * Test that an operator removed from the registry is not documented.
     *
     * @return void
     */
    public function testARemovedOperatorIsNotDocumented(): void
    {
        $field  = $this->queryableField(filterable: 'id', capability: Capability::RANGE);
        $schema = new CompiledSchema(['id' => $field], [], ['id' => Capability::RANGE]);

        $this->registry()->remove('$in');
        $this->registry()->remove('$between');

        $properties = $this->buildGhost($schema)['properties'];

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
            ['payload' => Capability::DOCUMENT],
            ['payload'],
        );

        $this->registry()->remove('$contains');

        $surface = $this->buildGhost($schema)['properties']['payload']['x-query-surface'];

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

        self::assertSame(['oneOf' => [['type' => 'object', 'x-undocumented' => true], ['type' => 'null']]], $properties['organization']);
        self::assertSame(
            ['type' => 'array', 'items' => ['type' => 'object', 'x-undocumented' => true], 'maxItems' => 25],
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

        self::assertSame(['$ref' => '#/components/schemas/Organization'], $properties['organization']['oneOf'][0]);
        self::assertSame(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Post'], 'maxItems' => 25], $properties['posts']);
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
        $this->expectExceptionMessage(sprintf(
            'The OpenAPI component schema name "User" is derived by both [%s] and [%s]. Disambiguate one with the #[SchemaName] attribute.',
            V1UserResource::class,
            V2UserResource::class,
        ));

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
     * Test that a declared limit bounds the array property of a to-many.
     *
     * @return void
     */
    public function testDeclaredLimitBoundsTheToManyArray(): void
    {
        $properties = $this->buildGhost(new CompiledSchema(['posts' => $this->relationField(5)], []))['properties'];

        self::assertIsArray($properties);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'object', 'x-undocumented' => true], 'maxItems' => 5], $properties['posts']);
    }

    /**
     * Test that a limit bounds the array member of an unknown cardinality.
     *
     * @return void
     */
    public function testLimitBoundsTheConservativeArrayMember(): void
    {
        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['GhostResource' => new CompiledSchema(['posts' => $this->relationField(5)], [])]);

        $schema = $this->makeGhostBuilder(['GhostModel' => 'GhostResource', Post::class => PostResource::class])->build()['Ghost'];

        self::assertSame(
            ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Post'], 'maxItems' => 5],
            $schema['properties']['posts']['oneOf'][1],
        );
        self::assertSame(['posts'], $schema['properties']['_truncated']['items']['enum']);
    }

    /**
     * Test that a to-one relation carries no bound however it is limited.
     *
     * @return void
     */
    public function testToOneRelationCarriesNoBound(): void
    {
        $properties = $this->buildGhost(new CompiledSchema(['organization' => $this->relationField(5, 'organization', OrganizationResource::class)], []))['properties'];

        self::assertIsArray($properties);
        self::assertSame(['oneOf' => [['type' => 'object', 'x-undocumented' => true], ['type' => 'null']]], $properties['organization']);
        self::assertArrayNotHasKey('_truncated', $properties);
    }

    /**
     * Test that a resource with a limited relation documents the optional
     * truncation list of its limited keys.
     *
     * @return void
     */
    public function testLimitedRelationsAreDocumentedAsAnOptionalTruncationList(): void
    {
        $schema = $this->makeBuilder($this->fullResourceMap())->build()['User'];

        self::assertSame([
            'type'        => 'array',
            'items'       => ['type' => 'string', 'enum' => ['posts']],
            'uniqueItems' => true,
            'minItems'    => 1,
        ], $schema['properties']['_truncated']);
        self::assertIsArray($schema['required']);
        self::assertNotContains('_truncated', $schema['required']);
    }

    /**
     * Test that no truncation list is documented when nothing is limited.
     *
     * @return void
     */
    public function testNoTruncationListWhenNothingIsLimited(): void
    {
        Config::set('api-toolkit.resources.relation_limit', 0);

        $schemas = $this->makeBuilder($this->fullResourceMap())->build();

        $user = $schemas['User']['properties'];
        $tag  = $schemas['Tag']['properties'];

        self::assertIsArray($user);
        self::assertIsArray($tag);
        self::assertSame(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Post']], $user['posts']);
        self::assertArrayNotHasKey('_truncated', $user);
        self::assertArrayNotHasKey('_truncated', $tag);
    }

    /**
     * Build the schema of a synthetic resource compiled from the given schema.
     *
     * The user model backs it by default so scalar properties resolve against a
     * table.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return array<string, mixed>
     */
    private function buildGhost(CompiledSchema $schema, string $modelClass = User::class): array
    {
        $this->setStaticProperty(SchemaCompiler::class, 'cache', ['GhostResource' => $schema]);

        return $this->makeGhostBuilder([$modelClass => 'GhostResource'])->build()['Ghost'];
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
     * Build a compiled aggregate definition over the given relation.
     *
     * @param  string  $key
     * @param  \SineMacula\ApiToolkit\Enums\AggregateFunction  $function
     * @param  string|null  $column
     * @param  string  $relation
     * @return \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition
     */
    private function aggregate(string $key, AggregateFunction $function, ?string $column = null, string $relation = 'posts'): CompiledAggregateDefinition
    {
        return new CompiledAggregateDefinition($key, $relation, $function, $column, null, false, []);
    }

    /**
     * Build a compiled relation field rendered through a child resource.
     *
     * @param  int|null  $limit
     * @param  string  $relation
     * @param  string  $resource
     * @param  array<int, callable(mixed, mixed): bool>  $guards
     * @param  array<int, callable(mixed, mixed): mixed>  $transformers
     * @param  \SineMacula\ApiToolkit\Schema\OpenApiFieldSchema|null  $openApi
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function relationField(
        ?int $limit,
        string $relation = 'posts',
        string $resource = PostResource::class,
        array $guards = [],
        array $transformers = [],
        ?OpenApiFieldSchema $openApi = null,
    ): CompiledFieldDefinition {
        return new CompiledFieldDefinition(
            accessor: null,
            compute: null,
            relation: $relation,
            resource: $resource,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: $guards,
            transformers: $transformers,
            openApi: $openApi,
            limit: $limit,
        );
    }

    /**
     * Build a ResourceSchemaBuilder over the given resource map.
     *
     * @param  array<class-string, class-string>  $resourceMap
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder
     */
    private function makeBuilder(array $resourceMap): ResourceSchemaBuilder
    {
        $registry  = $this->registry();
        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn($resourceMap);
        $catalogue->method('getOperatorsFor')->willReturnCallback($registry->tokensFor(...));

        return new ResourceSchemaBuilder($catalogue, $this->resolver(), $this->introspector());
    }

    /**
     * Build a builder over a stubbed compiled schema keyed by an unmapped name.
     *
     * @param  array<string, string>  $resourceMap
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder
     */
    private function makeGhostBuilder(array $resourceMap): ResourceSchemaBuilder
    {
        /** @var array<class-string, class-string> $resourceMap */
        return $this->makeBuilder($resourceMap);
    }

    /**
     * Return the bound operator registry, seeded with the shipped operators.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry
     */
    private function registry(): OperatorRegistry
    {
        return app(OperatorRegistry::class);
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

    /**
     * The resource map of the fixtures embedding each kind of to-one relation.
     *
     * @return array<class-string, class-string>
     */
    private function toOneResourceMap(): array
    {
        return [
            User::class         => ProfiledUserResource::class,
            AuthoredPost::class => AuthoredPostResource::class,
            Profile::class      => ProfileResource::class,
            Note::class         => NoteResource::class,
            Organization::class => OrganizationResource::class,
        ];
    }
}
