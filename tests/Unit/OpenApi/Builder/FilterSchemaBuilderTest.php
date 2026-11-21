<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\FilterValueShape;
use SineMacula\ApiToolkit\OpenApi\Builder\FilterSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ScopeSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Exceptions\SchemaNameCollisionException;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryScopeDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use SineMacula\ApiToolkit\OpenApi\Schema\FieldSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser;
use SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Concerns\ColumnLiteral;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\CompiledScopeDefinition;
use SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Concerns\ValidatesOpenApiDocuments;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\Device;
use Tests\Fixtures\Models\ManualUser;
use Tests\Fixtures\Models\Note;
use Tests\Fixtures\Models\Release;
use Tests\Fixtures\Models\Ticket;
use Tests\Fixtures\Models\TypedWidget;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\CustomNamedResource;
use Tests\Fixtures\Resources\ManualUserResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the FilterSchemaBuilder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FilterSchemaBuilder::class)]
final class FilterSchemaBuilderTest extends TestCase
{
    use InteractsWithNonPublicMembers, ValidatesOpenApiDocuments;

    /** @var array<string, mixed> Any scalar, the value of a column of unknown type */
    private const array SCALAR = ['type' => ['string', 'number', 'boolean']];

    /** @var array<string, mixed> A 64-bit integer */
    private const array INT8 = ['type' => 'integer', 'minimum' => PHP_INT_MIN, 'maximum' => PHP_INT_MAX];

    /** @var string The description of a key only some callers may use */
    private const string CONDITIONAL = 'Refused, as an undeclared key is, for callers the resource does not admit.';

    /** @var array<string, \SineMacula\ApiToolkit\Enums\FilterValueShape> The shape each shipped token declares */
    private const array SHAPES = [
        '$eq'       => FilterValueShape::SCALAR,
        '$gt'       => FilterValueShape::SCALAR,
        '$in'       => FilterValueShape::LIST,
        '$between'  => FilterValueShape::BOUNDS,
        '$null'     => FilterValueShape::PRESENCE,
        '$contains' => FilterValueShape::ELEMENTS,
    ];

    /** @var \SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry */
    private EnumSchemaRegistry $enums;

    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->enums = new EnumSchemaRegistry;
    }

    /**
     * Test that one document is built per surface, named after its resource's
     * component.
     *
     * @return void
     */
    public function testOneDocumentIsBuiltPerSurfaceNamedAfterItsResource(): void
    {
        $schemas = $this->build([$this->surface(UserResource::class, []), $this->surface(CustomNamedResource::class, [])]);

        self::assertSame(['UserFilters', 'CustomNameFilters'], array_keys($schemas));
        self::assertSame('CustomNameFilters', FilterSchemaBuilder::nameOf(CustomNamedResource::class));
    }

    /**
     * Test that each name is claimed for its resource, and a name already
     * claimed by another source fails the build naming both.
     *
     * @return void
     */
    public function testANameAlreadyClaimedFailsTheBuildNamingBothSources(): void
    {
        $claimed = [];

        $this->builder([$this->surface(UserResource::class, [])])->build($claimed);

        self::assertSame(['UserFilters' => UserResource::class], $claimed);

        $claimed = ['UserFilters' => PostResource::class];

        $this->expectException(SchemaNameCollisionException::class);
        $this->expectExceptionMessage(sprintf(
            'The OpenAPI component schema name "UserFilters" is derived by both [%s] and [%s]. Disambiguate one with the #[SchemaName] attribute.',
            PostResource::class,
            UserResource::class,
        ));

        $this->builder([$this->surface(UserResource::class, [])])->build($claimed);
    }

    /**
     * Test that a surface no filter may name anything on admits only the empty
     * document.
     *
     * @return void
     */
    public function testASurfaceWithNoKeyAdmitsOnlyTheEmptyDocument(): void
    {
        self::assertSame(
            ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            $this->document($this->surface(UserResource::class, [new QueryColumnDescriptor('name', 'name', sortable: true)])),
        );
    }

    /**
     * Test that a column answering equality takes a value or an object of its
     * operators, one that does not takes the object alone, and one no filter
     * names is left out.
     *
     * @return void
     */
    public function testAColumnTakesTheShorthandOnlyWhereItAnswersEquality(): void
    {
        $document = $this->document($this->surface(UserResource::class, [
            new QueryColumnDescriptor('name', 'name', sortable: true),
            $this->column('id', Capability::RANGE, ['$eq', '$gt']),
            $this->column('settings', Capability::DOCUMENT, ['$contains']),
        ]), ['id' => 'int8', 'settings' => 'jsonb']);

        self::assertSame(['id', 'settings', '$and', '$or'], array_keys($document['properties']));

        self::assertSame([
            'anyOf' => [
                self::INT8,
                ['type' => 'object', 'properties' => ['$eq' => self::INT8, '$gt' => self::INT8], 'minProperties' => 1, 'additionalProperties' => false],
            ],
        ], $document['properties']['id']);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                '$contains' => ['anyOf' => [self::SCALAR, ['type' => 'array', 'items' => self::SCALAR, 'minItems' => 1]]],
            ],
            'minProperties'        => 1,
            'additionalProperties' => false,
        ], $document['properties']['settings']);
    }

    /**
     * Provide each catalogue type with the canonical value schema it exports.
     *
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function kindProvider(): iterable
    {
        yield 'int8' => ['int8', self::INT8];
        yield 'mysql integer' => ['int', self::INT8];
        yield 'int4' => ['int4', ['type' => 'integer', 'minimum' => -2147483648, 'maximum' => 2147483647]];
        yield 'int2' => ['int2', ['type' => 'integer', 'minimum' => -32768, 'maximum' => 32767]];
        yield 'float8' => ['float8', ['type' => 'number']];
        yield 'float4' => ['float4', ['type' => 'number', 'format' => 'float', 'minimum' => -3.4028234663852886E+38, 'maximum' => 3.4028234663852886E+38]];
        yield 'boolean' => ['bool', ['type' => 'boolean']];
        yield 'date' => ['date', ['type' => 'string', 'format' => 'date']];
        yield 'datetime' => ['timestamptz', [
            'type'    => 'string',
            'pattern' => '^([0-9]{4})-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])([T ]([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9](\.[0-9]+)?)?(Z|[+-](0[0-9]|1[0-4])(:[0-5][0-9])?)?)?$',
        ]];
        yield 'uuid' => ['uuid', ['type' => 'string', 'format' => 'uuid']];
        yield 'text' => ['varchar', ['type' => 'string']];
    }

    /**
     * Test that a column's value is typed from its storage type in the
     * canonical form the guard accepts.
     *
     * @param  string  $type
     * @param  array<string, mixed>  $expected
     * @return void
     */
    #[DataProvider('kindProvider')]
    public function testAColumnValueIsTypedFromItsStorageType(string $type, array $expected): void
    {
        self::assertSame($expected, $this->valueOf(User::class, 'name', ['name' => $type]));
    }

    /**
     * Provide date-time values, with whether the parser accepts each.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function dateTimeProvider(): iterable
    {
        yield 'date alone' => ['2024-01-01', true];
        yield 'space separator' => ['2024-01-01 00:00:00', true];
        yield 'minutes' => ['2024-01-01T10:00', true];
        yield 'fraction in UTC' => ['2024-01-01T10:00:00.123456Z', true];
        yield 'hour offset' => ['2024-01-01 10:00:00.5+00', true];
        yield 'furthest offset' => ['2024-01-31T23:59:59+14:00', true];
        yield 'half-hour offset' => ['2024-01-01T10:00-05:30', true];
        yield 'slashed date' => ['2024/01/01', false];
        yield 'missing month separator' => ['202401-01T10:00', false];
        yield 'time alone' => ['10:00:00', false];
        yield 'month 00' => ['2024-00-01', false];
        yield 'month 13' => ['2024-13-01', false];
        yield 'day 00' => ['2024-01-00', false];
        yield 'day 32' => ['2024-01-32', false];
        yield 'hour 24' => ['2024-01-01T24:00', false];
        yield 'minute 60' => ['2024-01-01T10:60', false];
        yield 'second 60' => ['2024-01-01T10:00:60', false];
        yield 'fraction without seconds' => ['2024-01-01T10:00.5', false];
        yield 'zone on a date' => ['2024-01-01Z', false];
        yield 'offset past 14 hours' => ['2024-01-01T10:00+15:00', false];
        yield 'offset minute 60' => ['2024-01-01T10:00+02:60', false];
        yield 'one-digit offset' => ['2024-01-01T10:00+2', false];
        yield 'lowercase separator' => ['2024-01-01t10:00', false];
        yield 'trailing newline' => ["2024-01-01T10:00\n", false];
    }

    /**
     * Test that the exported date-time filter value matches exactly the values
     * the parser accepts.
     *
     * @param  string  $value
     * @param  bool  $accepted
     * @return void
     */
    #[DataProvider('dateTimeProvider')]
    public function testTheExportedDateTimeMatchesExactlyWhatTheParserAccepts(string $value, bool $accepted): void
    {
        self::assertSame($accepted, $this->literal(['at' => 'timestamptz'])->mismatch(new User, 'at', $value) === null);
        self::assertSame($accepted, $this->matchesExportedDateTime($value));
    }

    /**
     * Test that a day past its month's end, which no portable pattern states,
     * matches the exported date-time and is refused by the parser.
     *
     * @return void
     */
    public function testADayPastItsMonthsEndIsLeftToTheParser(): void
    {
        foreach (['2024-02-30', '2023-02-29T10:00', '2024-04-31 10:00:00Z'] as $value) {
            self::assertTrue($this->matchesExportedDateTime($value));
            self::assertNotNull($this->literal(['at' => 'timestamptz'])->mismatch(new User, 'at', $value));
        }
    }

    /**
     * Test that a cast other than an enum leaves the value typed by the column,
     * which is what the guard checks.
     *
     * @return void
     */
    public function testACastOtherThanAnEnumLeavesTheStorageType(): void
    {
        self::assertSame(self::INT8, $this->valueOf(TypedWidget::class, 'cast_string', ['cast_string' => 'int8']));
    }

    /**
     * Test that a column the catalogue does not describe, or an unreadable
     * catalogue, types the value as any scalar.
     *
     * @return void
     */
    public function testAColumnWithoutADefinitionTakesAnyScalar(): void
    {
        self::assertSame(self::SCALAR, $this->valueOf(User::class, 'name', ['email' => 'varchar']));
        self::assertSame(self::SCALAR, $this->valueOf(User::class, 'name', []));
    }

    /**
     * Test that an enum cast types the value by a reference to the enum's
     * component, registered for export, whatever the storage type.
     *
     * @return void
     */
    public function testAnEnumCastReferencesTheEnumComponent(): void
    {
        self::assertSame(['$ref' => '#/components/schemas/UserStatus'], $this->valueOf(User::class, 'status', ['status' => 'varchar']));
        self::assertSame(['$ref' => '#/components/schemas/Platform'], $this->valueOf(Device::class, 'platform', ['platform' => 'varchar']));
        self::assertSame([UserStatus::class, Platform::class], $this->enums->classes());
    }

    /**
     * Test that an aliased field is keyed and typed by the column a filter
     * names, not the property it renders under.
     *
     * @return void
     */
    public function testAnAliasedFieldIsKeyedAndTypedByItsColumn(): void
    {
        $document = $this->document($this->surface(UserResource::class, [
            new QueryColumnDescriptor('state', 'status', Capability::EXACT, ['$eq']),
        ]));

        self::assertArrayNotHasKey('state', $document['properties']);
        self::assertSame(['$ref' => '#/components/schemas/UserStatus'], $document['properties']['status']['anyOf'][0]);
    }

    /**
     * Provide models whose values on one column agree or differ.
     *
     * @return iterable<string, array{list<class-string>, array<string, mixed>, string, array<string, mixed>}>
     */
    public static function modelProvider(): iterable
    {
        yield 'one type on every model' => [[User::class, Note::class], ['priority' => 'int8'], 'priority', self::INT8];
        yield 'the same enum on every model' => [[Device::class, Release::class], ['platform' => 'varchar'], 'platform', ['$ref' => '#/components/schemas/Platform']];
        yield 'an enum against a storage type' => [[Ticket::class, Note::class], ['priority' => 'int8'], 'priority', self::SCALAR];
        yield 'different integer bounds' => [[User::class, Note::class], [User::class => ['priority' => 'int2'], Note::class => ['priority' => 'int8']], 'priority', self::SCALAR];
        yield 'no model' => [[], ['priority' => 'int8'], 'priority', self::SCALAR];
    }

    /**
     * Test that a column filtered on several models keeps its value schema only
     * where every model agrees, and otherwise takes any scalar.
     *
     * @param  list<class-string>  $models
     * @param  array<string, mixed>  $types
     * @param  string  $column
     * @param  array<string, mixed>  $expected
     * @return void
     */
    #[DataProvider('modelProvider')]
    public function testAColumnOnSeveralModelsKeepsOnlyTheValueTheyAgreeOn(array $models, array $types, string $column, array $expected): void
    {
        $surface = new QuerySurfaceDescriptor(UserResource::class, [$this->column($column, Capability::EXACT, ['$eq'])], models: $models);

        self::assertSame($expected, $this->document($surface, $types)['properties'][$column]['anyOf'][0]);
    }

    /**
     * Test that each declared value shape takes its schema, a token declaring
     * none takes any value, and lists are capped at the item cap.
     *
     * @return void
     */
    public function testEachOperatorTakesTheValueItsShapeDeclares(): void
    {
        $operators = $this->operatorsOf(['$eq', '$in', '$between', '$null', '$contains', '$custom'], 5);

        self::assertSame([
            '$eq'       => self::INT8,
            '$in'       => ['type' => 'array', 'items' => self::INT8, 'maxItems' => 5],
            '$between'  => ['type' => 'array', 'items' => self::INT8, 'minItems' => 2, 'maxItems' => 2],
            '$null'     => ['const' => true],
            '$contains' => ['anyOf' => [self::SCALAR, ['type' => 'array', 'items' => self::SCALAR, 'minItems' => 1, 'maxItems' => 5]]],
            '$custom'   => [],
        ], $operators);
    }

    /**
     * Test that a disabled or unnamed item cap leaves lists unbounded.
     *
     * @return void
     */
    public function testADisabledItemCapLeavesListsUnbounded(): void
    {
        foreach ([0, null] as $items) {

            $operators = $this->operatorsOf(['$in', '$between'], $items);

            self::assertSame(['type' => 'array', 'items' => self::INT8], $operators['$in']);
            self::assertSame(['type' => 'array', 'items' => self::INT8, 'minItems' => 2, 'maxItems' => 2], $operators['$between']);
        }
    }

    /**
     * Test that an item cap of one leaves out the bounds, which always carry
     * two items, and keeps them at two.
     *
     * @return void
     */
    public function testAnItemCapOfOneLeavesOutTheBounds(): void
    {
        self::assertSame(['$in'], array_keys($this->operatorsOf(['$between', '$in'], 1)));
        self::assertSame(['$between', '$in'], array_keys($this->operatorsOf(['$between', '$in'], 2)));
    }

    /**
     * Test that each relation takes the filter document of the resource
     * governing it, any object where each related type governs its own, and
     * only the empty document where nothing governs it.
     *
     * @return void
     */
    public function testEachRelationTakesTheDocumentOfWhatGovernsIt(): void
    {
        $document = $this->document($this->surface(UserResource::class, [], relations: [
            new QueryRelationDescriptor('organization', OrganizationResource::class),
            new QueryRelationDescriptor('commentable', polymorphic: true),
            new QueryRelationDescriptor('profile'),
        ]));

        self::assertSame(['$ref' => '#/components/schemas/OrganizationFilters'], $document['properties']['organization']);
        self::assertSame(['type' => 'object', 'description' => 'Checked against the filters of each related type.'], $document['properties']['commentable']);
        self::assertSame(['type' => 'object', 'maxProperties' => 0], $document['properties']['profile']);
    }

    /**
     * Test that a relation shadows a column of the same name, as the filter
     * routes the key to the relation.
     *
     * @return void
     */
    public function testARelationShadowsAColumnOfTheSameName(): void
    {
        $document = $this->document($this->surface(
            UserResource::class,
            [$this->column('organization', Capability::EXACT, ['$eq'])],
            relations: [new QueryRelationDescriptor('organization', OrganizationResource::class)],
        ));

        self::assertSame(['$ref' => '#/components/schemas/OrganizationFilters'], $document['properties']['organization']);
    }

    /**
     * Test that the logical groups take non-empty lists of non-empty documents
     * on the same surface, and the existence filters name its relations.
     *
     * @return void
     */
    public function testGroupsAndExistenceFiltersAreTypedOnTheSurface(): void
    {
        $relation = new QueryRelationDescriptor('posts', PostResource::class);
        $document = $this->document($this->surface(UserResource::class, [$this->column('id', Capability::RANGE, ['$eq'])], relations: [$relation]));
        $group    = ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/UserFilters', 'minProperties' => 1], 'minItems' => 1];
        $names    = ['type' => 'string', 'enum' => ['posts']];

        self::assertSame(['id', 'posts', '$and', '$or', '$has', '$hasnt'], array_keys($document['properties']));
        self::assertSame($group, $document['properties']['$and']);
        self::assertSame($group, $document['properties']['$or']);
        self::assertSame([
            'anyOf' => [
                $names,
                ['type' => 'array', 'items' => $names, 'minItems' => 1],
                [
                    'type'                 => 'object',
                    'properties'           => ['posts' => ['$ref' => '#/components/schemas/PostFilters']],
                    'minProperties'        => 1,
                    'additionalProperties' => false,
                ],
            ],
        ], $document['properties']['$has']);
        self::assertSame($document['properties']['$has'], $document['properties']['$hasnt']);
    }

    /**
     * Test that a surface without relations takes no existence filter.
     *
     * @return void
     */
    public function testASurfaceWithoutRelationsTakesNoExistenceFilter(): void
    {
        $document = $this->document($this->surface(UserResource::class, [$this->column('id', Capability::RANGE, ['$eq'])]));

        self::assertSame(['id', '$and', '$or'], array_keys($document['properties']));
    }

    /**
     * Test that named scopes are invoked by a bare name only where no argument
     * is required, and by name keyed to the arguments each takes.
     *
     * @return void
     */
    public function testScopesAreInvokedByNameOrByNameKeyedToTheirArguments(): void
    {
        $document = $this->document($this->surface(ManualUserResource::class, [], scopes: [
            new QueryScopeDescriptor('near', null, ['lat' => true, 'lng' => true, 'km' => true]),
            new QueryScopeDescriptor('upcoming'),
        ], models: [ManualUser::class]));

        $arguments = $this->scopeBuilder()->build(SchemaCompiler::compile(ManualUserResource::class));
        $names     = ['type' => 'string', 'enum' => ['upcoming']];

        self::assertSame(['$and', '$or', '$scope'], array_keys($document['properties']));
        self::assertSame([
            'anyOf' => [
                $names,
                ['type' => 'array', 'items' => $names, 'minItems' => 1],
                [
                    'type'                 => 'object',
                    'properties'           => ['near' => $arguments['near']['parameters'], 'upcoming' => $arguments['upcoming']['parameters']],
                    'minProperties'        => 1,
                    'additionalProperties' => false,
                ],
            ],
        ], $document['properties']['$scope']);
    }

    /**
     * Test that scopes each requiring an argument are invoked only by name
     * keyed to their arguments, with every required one listed, including one
     * the rules leave optional.
     *
     * @return void
     */
    public function testScopesAllRequiringArgumentsAreInvokedOnlyWithThem(): void
    {
        $document = $this->document($this->surface(ManualUserResource::class, [], scopes: [
            new QueryScopeDescriptor('near', null, ['lat' => true, 'lng' => true, 'km' => true, 'unit' => true]),
            new QueryScopeDescriptor('upcoming', null, ['from' => true, 'until' => false]),
        ], models: [ManualUser::class]));

        $arguments = $this->scopeBuilder()->build(SchemaCompiler::compile(ManualUserResource::class));

        /** @var array<string, mixed> $near */
        $near = $arguments['near']['parameters'];

        /** @var array<string, mixed> $upcoming */
        $upcoming = $arguments['upcoming']['parameters'];

        self::assertSame(['lat', 'lng', 'km'], $near['required']);
        self::assertSame([
            'type'                 => 'object',
            'properties'           => ['near' => [...$near, 'required' => ['lat', 'lng', 'km', 'unit']], 'upcoming' => [...$upcoming, 'required' => ['from']]],
            'minProperties'        => 1,
            'additionalProperties' => false,
        ], $document['properties']['$scope']);
    }

    /**
     * Test that a scope whose argument schema requires a key is not invoked by
     * a bare name, though its descriptor marks no argument required.
     *
     * @return void
     */
    public function testAScopeWhoseArgumentSchemaRequiresAKeyTakesNoBareName(): void
    {
        $document = $this->document($this->surface(ManualUserResource::class, [], scopes: [
            new QueryScopeDescriptor('near', null, ['lat' => false, 'lng' => false, 'km' => false]),
            new QueryScopeDescriptor('upcoming'),
        ], models: [ManualUser::class]));

        self::assertSame(['type' => 'string', 'enum' => ['upcoming']], $document['properties']['$scope']['anyOf'][0]);
    }

    /**
     * Test that a scope whose arguments must name one of several keys is not
     * invoked by a bare name.
     *
     * @return void
     */
    public function testAScopeRequiringOneOfSeveralArgumentsTakesNoBareName(): void
    {
        $scope = new CompiledScopeDefinition('either', 'either', ['a' => 'required_without:b|string', 'b' => 'string'], ['a', 'b']);

        $upcoming = new CompiledScopeDefinition('upcoming', 'upcoming');

        $this->setStaticProperty(SchemaCompiler::class, 'cache', [ManualUserResource::class => new CompiledSchema([], scopes: ['either' => $scope, 'upcoming' => $upcoming])]);

        $document = $this->document($this->surface(ManualUserResource::class, [], scopes: [
            new QueryScopeDescriptor('either', null, ['a' => false, 'b' => false]),
            new QueryScopeDescriptor('upcoming'),
        ], models: [ManualUser::class]));

        self::assertSame(['type' => 'string', 'enum' => ['upcoming']], $document['properties']['$scope']['anyOf'][0]);
        self::assertSame([['required' => ['a']], ['required' => ['b']]], $document['properties']['$scope']['anyOf'][2]['properties']['either']['anyOf']);
    }

    /**
     * Test that a column, relation or scope only some callers may use says so.
     *
     * @return void
     */
    public function testAKeyOnlySomeCallersMayUseIsDescribedAsSuch(): void
    {
        $document = $this->document($this->surface(ManualUserResource::class, [
            $this->column('age', Capability::RANGE, ['$gt'], conditional: true),
            $this->column('email', Capability::EXACT, ['$eq']),
        ], relations: [
            new QueryRelationDescriptor('posts', PostResource::class, conditional: true),
            new QueryRelationDescriptor('comments'),
            new QueryRelationDescriptor('commentable', polymorphic: true, conditional: true),
        ], scopes: [
            new QueryScopeDescriptor('upcoming', conditional: true),
        ], models: [ManualUser::class]));

        self::assertSame(self::CONDITIONAL, $document['properties']['age']['description']);
        self::assertSame(['$ref' => '#/components/schemas/PostFilters', 'description' => self::CONDITIONAL], $document['properties']['posts']);
        self::assertSame('Checked against the filters of each related type. ' . self::CONDITIONAL, $document['properties']['commentable']['description']);
        self::assertSame(self::CONDITIONAL, $document['properties']['$scope']['anyOf'][2]['properties']['upcoming']['description']);
        self::assertArrayNotHasKey('description', $document['properties']['email']);
        self::assertArrayNotHasKey('description', $document['properties']['comments']);
    }

    /**
     * Build the documents of the given surfaces.
     *
     * @param  list<\SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>  $surfaces
     * @param  array<string, mixed>  $types
     * @param  int|null  $items
     * @return array<string, array<string, mixed>>
     */
    private function build(array $surfaces, array $types = [], ?int $items = 0): array
    {
        $claimed = [];

        return $this->builder($surfaces, $types, $items)->build($claimed);
    }

    /**
     * Build the document of one surface.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor  $surface
     * @param  array<string, mixed>  $types
     * @param  int|null  $items
     * @return array{type: string, properties: array<string, array<string, mixed>>, additionalProperties: bool}
     */
    private function document(QuerySurfaceDescriptor $surface, array $types = [], ?int $items = 0): array
    {
        $schemas = $this->build([$surface], $types, $items);

        /** @var array{type: string, properties: array<string, array<string, mixed>>, additionalProperties: bool} */
        return $schemas[FilterSchemaBuilder::nameOf($surface->resource)];
    }

    /**
     * Read the value schema a column of the given model's type exports.
     *
     * @param  class-string  $model
     * @param  string  $column
     * @param  array<string, string>  $types
     * @return array<string, mixed>
     */
    private function valueOf(string $model, string $column, array $types): array
    {
        $surface = new QuerySurfaceDescriptor(UserResource::class, [$this->column($column, Capability::EXACT, ['$eq'])], models: [$model]);

        /** @var array<string, mixed> */
        return $this->document($surface, $types)['properties'][$column]['anyOf'][0];
    }

    /**
     * Determine whether the value validates against the filter value schema
     * exported for a date-time column.
     *
     * @param  string  $value
     * @return bool
     */
    private function matchesExportedDateTime(string $value): bool
    {
        $document = ['components' => ['schemas' => ['Value' => $this->valueOf(User::class, 'at', ['at' => 'timestamptz'])]]];

        return $this->validateAgainstExported($document, '/components/schemas/Value', $value)->isValid();
    }

    /**
     * Read the operators a 64-bit integer column answering the given tokens
     * exports under the item cap.
     *
     * @param  list<string>  $tokens
     * @param  int|null  $items
     * @return array<string, mixed>
     */
    private function operatorsOf(array $tokens, ?int $items): array
    {
        $surface = $this->surface(UserResource::class, [$this->column('id', Capability::RANGE, $tokens)]);
        $column  = $this->document($surface, ['id' => 'int8'], $items)['properties']['id'];

        /** @var array<string, mixed> */
        return ($column['anyOf'][1] ?? $column)['properties'];
    }

    /**
     * Build a surface, over the user model unless others are given.
     *
     * @param  class-string  $resource
     * @param  list<\SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor>  $columns
     * @param  list<\SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor>  $relations
     * @param  list<\SineMacula\ApiToolkit\OpenApi\Metadata\QueryScopeDescriptor>  $scopes
     * @param  list<class-string>  $models
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor
     */
    private function surface(string $resource, array $columns, array $relations = [], array $scopes = [], array $models = [User::class]): QuerySurfaceDescriptor
    {
        return new QuerySurfaceDescriptor($resource, $columns, $relations, $scopes, models: $models);
    }

    /**
     * Build a filterable column descriptor.
     *
     * @param  string  $column
     * @param  \SineMacula\ApiToolkit\Enums\Capability  $capability
     * @param  list<string>  $operators
     * @param  bool  $conditional
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor
     */
    private function column(string $column, Capability $capability, array $operators, bool $conditional = false): QueryColumnDescriptor
    {
        return new QueryColumnDescriptor($column, $column, $capability, $operators, filterConditional: $conditional);
    }

    /**
     * Build the builder over a catalogue of the given surfaces and column
     * types, naming no item cap for null.
     *
     * Types are keyed by column, or by model class and then column.
     *
     * @param  list<\SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>  $surfaces
     * @param  array<string, mixed>  $types
     * @param  int|null  $items
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\FilterSchemaBuilder
     */
    private function builder(array $surfaces, array $types = [], ?int $items = 0): FilterSchemaBuilder
    {
        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getQuerySurfaces')->willReturn($surfaces);
        $catalogue->method('getQueryLimits')->willReturn($items === null ? [] : [QueryCostLimits::MAX_IN_ITEMS => $items]);
        $catalogue->method('getOperatorShape')->willReturnCallback(static fn (string $token): ?FilterValueShape => self::SHAPES[$token] ?? null);

        return new FilterSchemaBuilder($catalogue, $this->literal($types), $this->enums, $this->scopeBuilder());
    }

    /**
     * Build the column literal resolver over a catalogue of the given column
     * types, keyed by column, or by model class and then column.
     *
     * @param  array<string, mixed>  $types
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\ColumnLiteral
     */
    private function literal(array $types): ColumnLiteral
    {
        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumnDefinitions')->willReturnCallback(static function (object $model) use ($types): array {

            $columns = is_array($types[$model::class] ?? null) ? $types[$model::class] : $types;

            return array_map(static fn (string $type): ColumnDefinition => new ColumnDefinition('column', $type, false), array_filter($columns, is_string(...)));
        });

        return new ColumnLiteral($introspector);
    }

    /**
     * Build the scope argument schema builder.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\ScopeSchemaBuilder
     */
    private function scopeBuilder(): ScopeSchemaBuilder
    {
        return new ScopeSchemaBuilder(new RulesToSchemaTranslator(new RuleNormaliser, new FieldSchemaBuilder($this->enums)));
    }
}
