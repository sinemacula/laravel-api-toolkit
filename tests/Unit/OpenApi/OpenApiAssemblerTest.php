<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ExceptionMap;
use SineMacula\ApiToolkit\OpenApi\Builder\DefinedSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\EnumSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorExampleBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\PathParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\SuccessResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Docs\DocManualAssembler;
use SineMacula\ApiToolkit\OpenApi\Docs\OperationDescriptions;
use SineMacula\ApiToolkit\OpenApi\Docs\TagList;
use SineMacula\ApiToolkit\OpenApi\Exceptions\OrphanedDocumentationException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\SchemaNameCollisionException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ServerConfigurationException;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorCatalogueReader;
use SineMacula\ApiToolkit\OpenApi\OpenApiAssembler;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ClassNameResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentableRouteFilter;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRouteCollector;
use SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RequestBodyResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseHeaderResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseSchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseStatusResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteKeySchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\TagResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ThrowsErrorResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\DefinedSchemaRegistry;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use SineMacula\ApiToolkit\OpenApi\Schema\FieldSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Schema\RoutePatternTranslator;
use SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser;
use SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeMapper;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeResolver;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector;
use Tests\Concerns\WritesDocsDirectories;
use Tests\Fixtures\Enums\SelfDescribingPlan;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\Alternate\SignedInShape as AlternateSignedInShape;
use Tests\Fixtures\OpenApi\EnvelopeNamedShape;
use Tests\Fixtures\OpenApi\MfaChallengeShape;
use Tests\Fixtures\OpenApi\NumericNamedShape;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\OpenApi\PathRequestBodyController;
use Tests\Fixtures\OpenApi\PathResponseSchemaController;
use Tests\Fixtures\OpenApi\PathResponseStatusController;
use Tests\Fixtures\OpenApi\PathSchemaNameCollisionController;
use Tests\Fixtures\OpenApi\SignedInShape;
use Tests\Fixtures\OpenApi\StatusNamedShape;
use Tests\Fixtures\OpenApi\UserNamedShape;
use Tests\Fixtures\OpenApi\WidgetShape;
use Tests\Fixtures\Resources\EnvelopeNamedResource;
use Tests\Fixtures\Resources\ErrorNamedResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the OpenApiAssembler.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OpenApiAssembler::class)]
final class OpenApiAssemblerTest extends TestCase
{
    use WritesDocsDirectories;

    /** @var array<class-string, class-string> The default resource map. */
    private const array RESOURCE_MAP = [
        User::class         => UserResource::class,
        Organization::class => OrganizationResource::class,
    ];

    /**
     * Point the docs directory somewhere absent so no manual leaks in.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->config()->set('api-toolkit.openapi.docs_path', sys_get_temp_dir() . '/api-toolkit-absent-' . uniqid('', true));
    }

    /**
     * Test that the assembled document declares a 3.1.x OpenAPI version.
     *
     * @return void
     */
    public function testDocumentDeclaresOpenApiThreeOne(): void
    {
        $document = $this->assemble();

        self::assertArrayHasKey('openapi', $document);
        self::assertIsString($document['openapi']);
        self::assertStringStartsWith('3.1', $document['openapi']);
    }

    /**
     * Test that the document carries a minimal info block.
     *
     * @return void
     */
    public function testDocumentCarriesAMinimalInfoBlock(): void
    {
        $document = $this->assemble();

        self::assertIsArray($document['info']);
        self::assertArrayHasKey('title', $document['info']);
        self::assertArrayHasKey('version', $document['info']);
    }

    /**
     * Test that the info block reflects the resolved audience's info.
     *
     * @return void
     */
    public function testInfoReflectsPerAudienceConfiguration(): void
    {
        $this->config()->set('api-toolkit.openapi.default_audience', 'internal');
        $this->config()->set('api-toolkit.openapi.audiences', [
            'internal' => [
                'info' => [
                    'title'       => 'Internal API',
                    'version'     => '3.4.5',
                    'description' => 'For staff only.',
                ],
            ],
        ]);

        $info = $this->assemble()['info'];

        self::assertSame('Internal API', $info['title']);
        self::assertSame('3.4.5', $info['version']);
        self::assertSame('For staff only.', $info['description']);
    }

    /**
     * Test that the info block falls back to the top-level default.
     *
     * @return void
     */
    public function testInfoFallsBackToTopLevelDefault(): void
    {
        $this->config()->set('api-toolkit.openapi.info', [
            'title'   => 'Acme API',
            'version' => '2.0.0',
        ]);
        $this->config()->set('api-toolkit.openapi.audiences', ['public' => []]);

        $info = $this->assemble()['info'];

        self::assertSame('Acme API', $info['title']);
        self::assertSame('2.0.0', $info['version']);
        self::assertIsArray($info);
        self::assertArrayNotHasKey('description', $info);
    }

    /**
     * Test that the manual follows the configured info description.
     *
     * @return void
     */
    public function testManualIsAppendedAfterConfiguredDescription(): void
    {
        $this->useDocsDirectory(['10-intro.md' => "# Intro\n\nWelcome."]);
        $this->config()->set('api-toolkit.openapi.audiences', [
            'public' => ['info' => ['description' => 'Preface.']],
        ]);

        $description = $this->assemble()['info']['description'];

        self::assertSame("Preface.\n\n# Intro\n\nWelcome.", $description);
    }

    /**
     * Test that the manual is the whole description when none is configured.
     *
     * @return void
     */
    public function testManualBecomesDescriptionWhenNoneConfigured(): void
    {
        $this->useDocsDirectory(['20-body.md' => "# Body\n\nContent."]);

        $info = $this->assemble()['info'];

        self::assertSame("# Body\n\nContent.", $info['description']);
    }

    /**
     * Test that info has no description without a configured one or docs.
     *
     * @return void
     */
    public function testDescriptionOmittedWhenManualAndConfigEmpty(): void
    {
        $this->config()->set('api-toolkit.openapi.docs_path', sys_get_temp_dir() . '/api-toolkit-absent-' . uniqid('', true));

        $info = $this->assemble()['info'];

        self::assertIsArray($info);
        self::assertArrayNotHasKey('description', $info);
    }

    /**
     * Test that the document declares no path operations.
     *
     * @return void
     */
    public function testDocumentDeclaresNoPaths(): void
    {
        $document = $this->assembleEmptyAudience();

        self::assertArrayHasKey('paths', $document);
        self::assertSame([], (array) $document['paths']);
    }

    /**
     * Test that an empty paths value serialises to a JSON object.
     *
     * @return void
     */
    public function testPathsSerialiseToAnEmptyObject(): void
    {
        $document = $this->assembleEmptyAudience();

        self::assertSame('{}', json_encode($document['paths']));
    }

    /**
     * Test that an unconstrained request field serialises to a JSON object.
     *
     * @return void
     */
    public function testUnconstrainedSchemasSerialiseToEmptyObjects(): void
    {
        $this->router()->post('accounts', [PathRequestBodyController::class, 'restricted']);

        $properties = $this->assemble()['paths']['/accounts']['post']['requestBody']['content']['application/json']['schema']['properties'];

        self::assertSame('{}', json_encode($properties['zone']));
        self::assertSame('{"type":"array","items":{}}', json_encode($properties['labels']));
    }

    /**
     * Test that the components block is populated.
     *
     * @return void
     */
    public function testComponentsBlockIsPopulated(): void
    {
        $this->registerUserRoutes();

        $components = $this->assemble()['components'];

        self::assertIsArray($components);
        self::assertNotEmpty($components['schemas']);
        self::assertNotEmpty($components['parameters']);
        self::assertNotEmpty($components['examples']);
        self::assertArrayNotHasKey('responses', $components);
    }

    /**
     * Test that reachable resource schemas appear beside the envelope of each
     * referenced error code, and no shared error envelope.
     *
     * @return void
     */
    public function testResourceAndErrorEnvelopeSchemasArePresent(): void
    {
        $this->registerUserRoutes();

        $schemas = $this->assemble()['components']['schemas'];

        self::assertIsArray($schemas);
        self::assertArrayHasKey('User', $schemas);
        self::assertArrayHasKey('Organization', $schemas);
        self::assertArrayHasKey('Error10001', $schemas);
        self::assertArrayNotHasKey('ErrorEnvelope', $schemas);
    }

    /**
     * Test that a reachable enum is emitted as its own typed component.
     *
     * @return void
     */
    public function testReferencedEnumComponentIsPresentAndReachable(): void
    {
        $this->registerUserRoutes();

        $schemas = $this->assemble()['components']['schemas'];

        self::assertSame([
            '$ref'            => '#/components/schemas/UserStatus',
            'x-query-surface' => [
                'filter' => ['key' => 'status', 'capability' => 'enum', 'operators' => ['$eq', '$neq']],
                'sort'   => ['key' => 'status', 'indexed' => true],
            ],
        ], $schemas['User']['properties']['status']);
        self::assertSame(['type' => 'string', 'enum' => ['active', 'inactive', 'banned']], $schemas['UserStatus']);
    }

    /**
     * Test that the pagination envelope schemas are emitted.
     *
     * @return void
     */
    public function testEnvelopeSchemasArePresent(): void
    {
        $schemas = $this->assemble()['components']['schemas'];

        self::assertIsArray($schemas);
        self::assertArrayHasKey(EnvelopeBuilder::PAGINATION_META_SCHEMA_NAME, $schemas);
        self::assertArrayHasKey(EnvelopeBuilder::PAGINATION_LINKS_SCHEMA_NAME, $schemas);
        self::assertArrayHasKey(EnvelopeBuilder::CURSOR_PAGINATION_META_SCHEMA_NAME, $schemas);
        self::assertArrayHasKey(EnvelopeBuilder::CURSOR_PAGINATION_LINKS_SCHEMA_NAME, $schemas);
    }

    /**
     * Test that an index offers both pagination envelopes, both retained.
     *
     * @return void
     */
    public function testIndexOffersBothPaginationShapesAndKeepsCursorSchemas(): void
    {
        $this->registerUserRoutes();

        $document = $this->assemble();
        $schema   = $document['paths']['/users']['get']['responses']['200']['content']['application/json']['schema'];
        $envelope = new EnvelopeBuilder;

        self::assertSame(
            [
                'anyOf' => [
                    $envelope->collectionEnvelope('#/components/schemas/User'),
                    $envelope->cursorCollectionEnvelope('#/components/schemas/User'),
                ],
            ],
            $schema,
        );

        $schemas = $document['components']['schemas'];

        self::assertIsArray($schemas);
        self::assertArrayHasKey(EnvelopeBuilder::CURSOR_PAGINATION_META_SCHEMA_NAME, $schemas);
        self::assertArrayHasKey(EnvelopeBuilder::CURSOR_PAGINATION_LINKS_SCHEMA_NAME, $schemas);
    }

    /**
     * Test that securitySchemes carries exactly the referenced schemes.
     *
     * @return void
     */
    public function testSecuritySchemesReflectReferencedSchemes(): void
    {
        $this->configureGuards();

        $router = $this->router();
        $router->get('users', [PathFixtureController::class, 'index'])->middleware('auth:api');
        $router->get('admins', [PathFixtureController::class, 'index'])->middleware('auth:admin');
        $router->get('sessions', [PathFixtureController::class, 'index'])->middleware('auth:web');

        $schemes = $this->assemble()['components']['securitySchemes'];

        self::assertIsArray($schemes);
        self::assertSame(['bearerAuth', 'basicAuth', 'cookieAuth'], array_keys($schemes));
        self::assertSame(['type' => 'http', 'scheme' => 'bearer'], $schemes['bearerAuth']);
        self::assertSame(['type' => 'http', 'scheme' => 'basic'], $schemes['basicAuth']);
        self::assertSame('apiKey', $schemes['cookieAuth']['type']);
        self::assertSame('cookie', $schemes['cookieAuth']['in']);
    }

    /**
     * Test that securitySchemes is omitted when no path references a scheme.
     *
     * @return void
     */
    public function testSecuritySchemesOmittedWhenNoRouteIsAuthenticated(): void
    {
        $this->registerUserRoutes();

        $components = $this->assemble()['components'];

        self::assertIsArray($components);
        self::assertArrayNotHasKey('securitySchemes', $components);
    }

    /**
     * Test that the total-count header is emitted under components.headers.
     *
     * @return void
     */
    public function testTotalCountHeaderIsPresent(): void
    {
        $headers = $this->assemble()['components']['headers'];

        self::assertIsArray($headers);
        self::assertArrayHasKey(EnvelopeBuilder::TOTAL_COUNT_HEADER_NAME, $headers);
    }

    /**
     * Test that the query-parameter vocabulary is emitted once.
     *
     * @return void
     */
    public function testSharedParametersAreEmittedOnce(): void
    {
        $parameters = $this->assemble()['components']['parameters'];

        self::assertIsArray($parameters);
        self::assertArrayHasKey('Filters', $parameters);
        self::assertArrayHasKey('Fields', $parameters);
        self::assertArrayHasKey('Order', $parameters);
        self::assertArrayHasKey('Trashed', $parameters);
    }

    /**
     * Test that the error examples are pruned to those the paths reference.
     *
     * @return void
     */
    public function testErrorExamplesArePrunedToThoseReferenced(): void
    {
        $this->registerUserRoutes();

        $examples = $this->assemble()['components']['examples'];

        self::assertIsArray($examples);
        self::assertSame(['Error10001', 'Error10102', 'Error10103', 'Error10106', 'Error10108'], array_keys($examples));
        self::assertSame(
            ['summary' => 'Not Found', 'value' => ['error' => ['status' => 404, 'code' => 10103, 'title' => 'Not Found', 'detail' => 'The requested resource could not be found']]],
            $examples['Error10103'],
        );
    }

    /**
     * Test that a document without paths carries no error example.
     *
     * @return void
     */
    public function testDocumentWithoutPathsCarriesNoErrorExample(): void
    {
        $components = $this->assemble()['components'];

        self::assertIsArray($components);
        self::assertArrayNotHasKey('examples', $components);
        self::assertArrayNotHasKey('responses', $components);
    }

    /**
     * Test that the assembled document serialises to valid JSON.
     *
     * @return void
     */
    public function testDocumentSerialisesToJson(): void
    {
        $json = json_encode($this->assemble(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        self::assertIsString($json);
        self::assertStringContainsString('"openapi"', $json);
    }

    /**
     * Test that a non-empty audience argument is honoured verbatim.
     *
     * @return void
     */
    public function testExplicitAudienceIsHonouredOverDefault(): void
    {
        $this->config()->set('api-toolkit.openapi.default_audience', 'public');
        $this->config()->set('api-toolkit.openapi.audiences', [
            'public'   => ['info' => ['title' => 'Public API', 'version' => '1.0.0']],
            'internal' => ['info' => ['title' => 'Internal API', 'version' => '9.9.9']],
        ]);

        $info = $this->makeAssembler()->assemble('internal')['info'];

        self::assertSame('Internal API', $info['title']);
        self::assertSame('9.9.9', $info['version']);
    }

    /**
     * Test that two security requirements contribute both schemes.
     *
     * @return void
     */
    public function testSecuritySchemesAccumulateAcrossRequirements(): void
    {
        $this->configureGuards();

        $this->router()->get('reports', [PathFixtureController::class, 'index'])->middleware('auth:api,web');

        $schemes = $this->assemble()['components']['securitySchemes'];

        self::assertIsArray($schemes);
        self::assertArrayHasKey('bearerAuth', $schemes);
        self::assertArrayHasKey('cookieAuth', $schemes);
    }

    /**
     * Test that a document without paths carries no error envelope.
     *
     * @return void
     */
    public function testDocumentWithoutPathsCarriesNoErrorEnvelope(): void
    {
        $schemas = $this->assembleEmptyAudience()['components']['schemas'];

        self::assertIsArray($schemas);
        self::assertSame([], preg_grep('/^Error/', array_map(strval(...), array_keys($schemas))));
    }

    /**
     * Test that a resource named like an error envelope collides only once a
     * path references its code.
     *
     * @return void
     */
    public function testResourceNamedLikeAnErrorEnvelopeCollidesOnceItsCodeIsReferenced(): void
    {
        $this->router()->get('users/{user}', [PathFixtureController::class, 'show']);

        $this->makeAssembler([Organization::class => ErrorNamedResource::class])->assemble();

        $this->router()->post('users', [PathRequestBodyController::class, 'formRequest']);

        $this->expectCollision('Error10106', ErrorNamedResource::class, ErrorResponseBuilder::class);

        $this->makeAssembler([Organization::class => ErrorNamedResource::class])->assemble();
    }

    /**
     * Test that configured servers are exported in order, intact.
     *
     * @return void
     */
    public function testConfiguredServersAreExportedInOrder(): void
    {
        $servers = [
            ['url' => 'https://api.example.com', 'description' => 'Production'],
            [
                'url'         => 'https://{region}.api.example.com',
                'description' => 'Regional',
                'variables'   => ['region' => ['default' => 'eu', 'enum' => ['eu', 'us']]],
            ],
        ];

        $this->config()->set('api-toolkit.openapi.servers', $servers);

        $document = $this->assemble();

        self::assertSame($servers, $document['servers']);
        self::assertSame(['openapi', 'info', 'servers', 'paths', 'components'], array_keys($document));
    }

    /**
     * Test that an audience's own servers replace the global list.
     *
     * @return void
     */
    public function testAudienceServersReplaceTheGlobalListForThatAudienceOnly(): void
    {
        $global  = [['url' => 'https://api.example.com'], ['url' => 'https://internal.example.com']];
        $partner = [['url' => 'https://partners.example.com', 'description' => 'Partners']];

        $this->config()->set('api-toolkit.openapi.servers', $global);
        $this->config()->set('api-toolkit.openapi.audiences', [
            'public'  => [],
            'partner' => ['servers' => $partner],
        ]);

        $assembler = $this->makeAssembler();

        self::assertSame($partner, $assembler->assemble('partner')['servers']);
        self::assertSame($global, $assembler->assemble('public')['servers']);
    }

    /**
     * Test that no servers key is exported when the list is empty.
     *
     * @return void
     */
    public function testNoServersKeyWithoutConfiguredServers(): void
    {
        self::assertArrayNotHasKey('servers', $this->assemble());

        $this->config()->set('api-toolkit.openapi.servers', [['url' => 'https://api.example.com']]);
        $this->config()->set('api-toolkit.openapi.audiences', ['public' => ['servers' => []]]);

        self::assertArrayNotHasKey('servers', $this->makeAssembler()->assemble('public'));
    }

    /**
     * Test that a server without a string url fails the export.
     *
     * @return void
     */
    public function testServerWithoutAStringUrlFailsTheExport(): void
    {
        $this->config()->set('api-toolkit.openapi.servers', [['description' => 'Production']]);

        $this->expectException(ServerConfigurationException::class);
        $this->expectExceptionMessage('Unable to export the global OpenAPI servers: server [0] must declare a non-empty string url.');

        $this->assemble();
    }

    /**
     * Test that a resource named like an envelope schema fails the export.
     *
     * @return void
     */
    public function testResourceNamedLikeASharedEnvelopeFailsTheExport(): void
    {
        $this->expectException(SchemaNameCollisionException::class);
        $this->expectExceptionMessage(sprintf(
            'The OpenAPI component schema name "PaginationMeta" is derived by both [%s] and [%s].'
                . ' Disambiguate one with the #[SchemaName] attribute.',
            EnvelopeNamedResource::class,
            EnvelopeBuilder::class,
        ));

        $this->makeAssembler([Organization::class => EnvelopeNamedResource::class])->assemble();
    }

    /**
     * Test that a declared single shape is exported as one titled component.
     *
     * @return void
     */
    public function testDeclaredShapeIsExportedAsATitledComponent(): void
    {
        $this->router()->get('widget', [PathResponseSchemaController::class, 'widget']);

        $schemas = $this->assemble()['components']['schemas'];

        self::assertIsArray($schemas);
        self::assertSame(['title' => 'WidgetShape', ...WidgetShape::schema()], $schemas['WidgetShape']);
    }

    /**
     * Test that every member of a declared union is exported as a titled
     * component.
     *
     * @return void
     */
    public function testDeclaredUnionMembersAreExportedAsTitledComponents(): void
    {
        $this->router()->get('session', [PathResponseSchemaController::class, 'named']);

        $schemas = $this->assemble()['components']['schemas'];

        self::assertIsArray($schemas);
        self::assertSame(['title' => 'SignedInShape', ...SignedInShape::schema()], $schemas['SignedInShape']);
        self::assertSame(['title' => 'MfaChallenge', ...MfaChallengeShape::schema()], $schemas['MfaChallenge']);
    }

    /**
     * Test that a shape declared by two operations references one component.
     *
     * @return void
     */
    public function testShapeDeclaredTwiceReferencesOneComponent(): void
    {
        $this->router()->get('widget', [PathResponseSchemaController::class, 'widget']);
        $this->router()->post('jobs', [PathResponseStatusController::class, 'queue']);

        $document  = $this->assemble();
        $reference = ['$ref' => '#/components/schemas/WidgetShape'];

        self::assertSame($reference, $document['paths']['/widget']['get']['responses']['200']['content']['application/json']['schema']['properties']['data']);
        self::assertSame($reference, $document['paths']['/jobs']['post']['responses']['202']['content']['application/json']['schema']['properties']['data']);
        self::assertSame(['title' => 'WidgetShape', ...WidgetShape::schema()], $document['components']['schemas']['WidgetShape']);
    }

    /**
     * Test that a shape with a numeric component name keeps the component its
     * reference resolves to.
     *
     * @return void
     */
    public function testNumericallyNamedShapeKeepsItsComponent(): void
    {
        $this->router()->get('numeric', [PathResponseSchemaController::class, 'numeric']);

        $document = $this->assemble();
        $response = $document['paths']['/numeric']['get']['responses']['200'];

        self::assertSame(['$ref' => '#/components/schemas/123'], $response['content']['application/json']['schema']['properties']['data']);
        self::assertSame(['title' => '123', ...NumericNamedShape::schema()], $document['components']['schemas']['123']);
    }

    /**
     * Test that a shape named like a resource fails the export.
     *
     * @return void
     */
    public function testShapeNamedLikeAResourceFailsTheExport(): void
    {
        $this->router()->get('user', [PathSchemaNameCollisionController::class, 'user']);

        $this->expectCollision('User', UserResource::class, UserNamedShape::class);

        $this->assemble();
    }

    /**
     * Test that a shape named like a shared envelope fails the export.
     *
     * @return void
     */
    public function testShapeNamedLikeASharedEnvelopeFailsTheExport(): void
    {
        $this->router()->get('envelope', [PathSchemaNameCollisionController::class, 'envelope']);

        $this->expectCollision('PaginationMeta', EnvelopeBuilder::class, EnvelopeNamedShape::class);

        $this->assemble();
    }

    /**
     * Test that two shapes sharing a short name fail the export.
     *
     * @return void
     */
    public function testShapesSharingAShortNameFailTheExport(): void
    {
        $this->router()->get('session', [PathResponseSchemaController::class, 'union']);
        $this->router()->get('alternate', [PathSchemaNameCollisionController::class, 'alternate']);

        $this->expectCollision('SignedInShape', SignedInShape::class, AlternateSignedInShape::class);

        $this->assemble();
    }

    /**
     * Test that an enum named like a shape fails the export.
     *
     * @return void
     */
    public function testEnumNamedLikeAShapeFailsTheExport(): void
    {
        $this->router()->get('status', [PathSchemaNameCollisionController::class, 'status']);

        $this->expectCollision('UserStatus', StatusNamedShape::class, UserStatus::class);

        $this->assemble();
    }

    /**
     * Test that an enum shape no enum reference claims is exported as a
     * component.
     *
     * @return void
     */
    public function testEnumShapeIsExportedAsATitledComponent(): void
    {
        $this->router()->get('plan', [PathResponseSchemaController::class, 'enum']);

        $schemas = $this->assemble()['components']['schemas'];

        self::assertIsArray($schemas);
        self::assertSame(['title' => 'SelfDescribingPlan', ...SelfDescribingPlan::schema()], $schemas['SelfDescribingPlan']);
    }

    /**
     * Test that an enum named as a shape and referenced as an enum fails the
     * export.
     *
     * @return void
     */
    public function testEnumShapeReferencedAsAnEnumFailsTheExport(): void
    {
        $this->router()->post('plan', [PathSchemaNameCollisionController::class, 'plan']);

        $this->expectException(SchemaNameCollisionException::class);
        $this->expectExceptionMessage(sprintf(
            'The OpenAPI component schema name "SelfDescribingPlan" is derived by [%s] both as an enum and as a'
                . ' #[ResponseSchema] shape. Declare the response shape on a class that is not an enum.',
            SelfDescribingPlan::class,
        ));

        $this->assemble();
    }

    /**
     * Test that the shapes one audience names are not claimed in the next.
     *
     * @return void
     */
    public function testShapesClaimedByOneAudienceDoNotCollideInAnother(): void
    {
        $this->config()->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => ['posture' => 'allowlist'],
        ]);
        $this->router()->get('session', [PathResponseSchemaController::class, 'union']);
        $this->router()->get('alternate', [PathSchemaNameCollisionController::class, 'alternateUnion']);

        $assembler = $this->makeAssembler();
        $public    = $assembler->assemble('public')['components']['schemas'];
        $internal  = $assembler->assemble('internal')['components']['schemas'];

        self::assertIsArray($public);
        self::assertIsArray($internal);
        self::assertSame(['title' => 'SignedInShape', ...SignedInShape::schema()], $public['SignedInShape']);
        self::assertSame(['title' => 'SignedInShape', ...AlternateSignedInShape::schema()], $internal['SignedInShape']);
    }

    /**
     * Test that nothing configured adds no operationId, summary or top-level
     * tags.
     *
     * @return void
     */
    public function testNothingConfiguredAddsNoOperationDocumentation(): void
    {
        $this->registerUserRoutes();

        $document = $this->assemble();

        self::assertArrayNotHasKey('tags', $document);
        self::assertIsArray($document['paths']);

        foreach ($document['paths'] as $operations) {
            self::assertIsArray($operations);

            foreach ($operations as $operation) {
                self::assertIsArray($operation);
                self::assertArrayNotHasKey('operationId', $operation);
                self::assertArrayNotHasKey('summary', $operation);
            }
        }
    }

    /**
     * Test that an operation description reaches its operation, and the tag
     * list follows the components.
     *
     * @return void
     */
    public function testDescriptionsAndTagsReachTheDocument(): void
    {
        $this->config()->set('api-toolkit.openapi.operation_ids', true);
        $this->config()->set('api-toolkit.openapi.tags', ['User']);

        $this->useDocsDirectory(['operations/path-fixture.index.md' => 'Lists the users.']);
        $this->registerUserRoutes();

        $document = $this->assemble();

        self::assertSame('Lists the users.', $document['paths']['/users']['get']['description']);
        self::assertSame('path-fixture.index', $document['paths']['/users']['get']['operationId']);
        self::assertSame(['openapi', 'info', 'paths', 'components', 'tags'], array_keys($document));
        self::assertSame([['name' => 'User']], $document['tags']);
    }

    /**
     * Test that one audience's export fails on another audience's orphaned
     * file.
     *
     * @return void
     */
    public function testExportFailsOnAnotherAudiencesOrphanedFile(): void
    {
        $this->config()->set('api-toolkit.openapi.audiences', ['public' => [], 'internal' => []]);

        $dir = $this->useDocsDirectory(['audiences/internal/tags/Ghost.md' => 'Ghost.']);

        $this->registerUserRoutes();

        $this->expectException(OrphanedDocumentationException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote(
            sprintf('The tag description [%s/audiences/internal/tags/Ghost.md] matches no tag in the [internal] audience.', $dir),
            '/',
        ) . '$/');

        $this->makeAssembler()->assemble('public');
    }

    /**
     * Test that the undeclared default audience's files pass on another
     * audience's export.
     *
     * @return void
     */
    public function testUndeclaredDefaultAudiencesFilesPassOnAnotherExport(): void
    {
        $this->config()->set('api-toolkit.openapi.audiences', ['internal' => []]);

        $this->useDocsDirectory(['audiences/public/tags/User.md' => 'Accounts.']);
        $this->registerUserRoutes();

        self::assertSame([['name' => 'User']], $this->makeAssembler()->assemble('internal')['tags']);
        self::assertSame([['name' => 'User', 'description' => 'Accounts.']], $this->makeAssembler()->assemble()['tags']);
    }

    /**
     * Expect the export to fail on a component name derived by two sources.
     *
     * @param  string  $name
     * @param  string  $first
     * @param  string  $second
     * @return void
     */
    private function expectCollision(string $name, string $first, string $second): void
    {
        $this->expectException(SchemaNameCollisionException::class);
        $this->expectExceptionMessage(sprintf(
            'The OpenAPI component schema name "%s" is derived by both [%s] and [%s].'
                . ' Disambiguate one with the #[SchemaName] attribute.',
            $name,
            $first,
            $second,
        ));
    }

    /**
     * Assemble a document from real builders over a stubbed catalogue.
     *
     * @return array<string, mixed>
     */
    private function assemble(): array
    {
        return $this->makeAssembler()->assemble();
    }

    /**
     * Assemble a document for an allowlist audience no route opts into.
     *
     * @return array<string, mixed>
     */
    private function assembleEmptyAudience(): array
    {
        $this->config()->set('api-toolkit.openapi.audiences', [
            'partner' => ['posture' => 'allowlist'],
        ]);

        return $this->makeAssembler()->assemble('partner');
    }

    /**
     * Build an assembler from the real builders over the resource map.
     *
     * @param  array<class-string, class-string>  $resourceMap
     * @return \SineMacula\ApiToolkit\OpenApi\OpenApiAssembler
     */
    private function makeAssembler(array $resourceMap = self::RESOURCE_MAP): OpenApiAssembler
    {
        $catalogue = $this->makeCatalogue($resourceMap);

        assert($this->app !== null);

        $enums          = new EnumSchemaRegistry;
        $definedSchemas = new DefinedSchemaRegistry;
        $errors         = new ErrorResponseBuilder(
            new RouteMiddlewareResolver($this->router()),
            new ThrowsErrorResolver(new ClassNameResolver),
            new ResponseHeaderResolver,
            new ErrorExampleBuilder($catalogue),
            ExceptionMap::fromConfig(),
        );

        return new OpenApiAssembler(
            new ResourceSchemaBuilder($catalogue, $this->resolver($enums), $this->app->make(SchemaIntrospector::class)),
            new QueryParameterBuilder($catalogue),
            $errors,
            new PathBuilder(
                new EnvelopeBuilder,
                new RequestBodyResolver(new RulesToSchemaTranslator(new RuleNormaliser, new FieldSchemaBuilder($enums))),
                new SecuritySchemeResolver(new SecuritySchemeMapper, new RouteMiddlewareResolver($this->router())),
                new QueryParameterBuilder($catalogue),
                new PathParameterBuilder(
                    $this->router(),
                    new RoutePatternTranslator,
                    new RouteKeySchemaResolver($catalogue, $this->resolver($enums), new RoutePatternTranslator, $enums, $this->app),
                    $enums,
                ),
                $errors,
                new SuccessResponseBuilder(
                    new EnvelopeBuilder,
                    new ResponseSchemaResolver($catalogue, new EnvelopeBuilder, $definedSchemas),
                    new ResponseHeaderResolver,
                    new ResponseStatusResolver,
                ),
            ),
            new SecuritySchemeResolver(new SecuritySchemeMapper, new RouteMiddlewareResolver($this->router())),
            new EnumSchemaBuilder,
            $enums,
            new DocManualAssembler,
            $definedSchemas,
            new DefinedSchemaBuilder,
            new ErrorExampleBuilder($catalogue),
            new DocumentedRouteCollector($this->router(), $catalogue, new AudienceResolver, new AudienceConfiguration, new DocumentableRouteFilter, new TagResolver),
            new OperationDescriptions,
            new TagList,
        );
    }

    /**
     * Register a full REST route set for the user resource.
     *
     * @return void
     */
    private function registerUserRoutes(): void
    {
        $router = $this->router();

        $router->get('users', [PathFixtureController::class, 'index']);
        $router->post('users', [PathFixtureController::class, 'store']);
        $router->get('users/{user}', [PathFixtureController::class, 'show']);
        $router->match(['PUT', 'PATCH'], 'users/{user}', [PathFixtureController::class, 'update']);
        $router->delete('users/{user}', [PathFixtureController::class, 'destroy']);
    }

    /**
     * Configure the auth guards the security schemes are derived from.
     *
     * @return void
     */
    private function configureGuards(): void
    {
        $config = $this->config();

        $config->set('auth.defaults.guard', 'web');
        $config->set('auth.guards.api', ['driver' => 'jwt']);
        $config->set('auth.guards.web', ['driver' => 'session']);
        $config->set('auth.guards.admin', ['driver' => 'basic']);
    }

    /**
     * Get the config repository instance.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function config(): ConfigRepository
    {
        assert($this->app !== null);

        /** @var \Illuminate\Contracts\Config\Repository */
        return $this->app->make('config');
    }

    /**
     * Resolve the application router singleton.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        assert($this->app !== null);

        return $this->app->make(Router::class);
    }

    /**
     * Build a stubbed catalogue with resources, operators and one error.
     *
     * @param  array<class-string, class-string>  $resourceMap
     * @return \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue
     */
    private function makeCatalogue(array $resourceMap): MetadataCatalogue
    {
        assert($this->app !== null);

        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn($resourceMap);
        $catalogue->method('getOperatorTokens')->willReturn(['$eq', '$neq']);
        $catalogue->method('getStructuralOperators')->willReturn(['$and', '$or']);
        $catalogue->method('getErrorCatalogue')->willReturn($this->app->make(ErrorCatalogueReader::class)->read());

        return $catalogue;
    }

    /**
     * Build a real field-type resolver against the bound introspector.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry  $enums
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver
     */
    private function resolver(EnumSchemaRegistry $enums): FieldTypeResolver
    {
        assert($this->app !== null);

        return new FieldTypeResolver($this->app->make(SchemaIntrospector::class), new ColumnTypeMapper, $enums);
    }
}
