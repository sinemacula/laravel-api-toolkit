<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator as RequestValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Formats\UriFormats;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Resolvers\FormatResolver;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Console\ExportOpenApiCommand;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder;
use SineMacula\ApiToolkit\OpenApi\Docs\DocManualAssembler;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseStatusConflictException;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\ExportResult;
use SineMacula\ApiToolkit\OpenApi\OpenApiAssembler;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration;
use SineMacula\ApiToolkit\OpenApi\Resolution\ReachableSchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ServerListValidator;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Fixtures\Enums\AppErrorCode;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\ColumnlessIntrospectionProvider;
use Tests\Fixtures\OpenApi\KeyedArrayRequestInput;
use Tests\Fixtures\OpenApi\NumericKeyRequestInput;
use Tests\Fixtures\OpenApi\PathArticleController;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\OpenApi\PathHeaderedUserController;
use Tests\Fixtures\OpenApi\PathInvokableController;
use Tests\Fixtures\OpenApi\PathKeyedArrayController;
use Tests\Fixtures\OpenApi\PathNumericKeyController;
use Tests\Fixtures\OpenApi\PathOrganizationController;
use Tests\Fixtures\OpenApi\PathRequestBodyController;
use Tests\Fixtures\OpenApi\PathResponseHeaderController;
use Tests\Fixtures\OpenApi\PathResponseSchemaController;
use Tests\Fixtures\OpenApi\PathResponseStatusController;
use Tests\Fixtures\OpenApi\PathStatusConflictController;
use Tests\Fixtures\OpenApi\PathStatusUserController;
use Tests\Fixtures\OpenApi\PathTagInternalController;
use Tests\Fixtures\OpenApi\SignedInShape;
use Tests\Fixtures\OpenApi\WidgetShape;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * End-to-end validity test for the OpenAPI exporter.
 *
 * The emitted document must validate against the official OpenAPI 3.1
 * meta-schema.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportOpenApiComponents::class)]
#[CoversClass(ExportOpenApiCommand::class)]
#[CoversClass(OpenApiAssembler::class)]
#[CoversClass(PathBuilder::class)]
#[CoversClass(ReachableSchemaResolver::class)]
#[CoversClass(AudienceConfiguration::class)]
#[CoversClass(DocManualAssembler::class)]
#[CoversClass(ServerListValidator::class)]
final class OpenApiExporterValidityTest extends TestCase
{
    /** @var string The identifier of the OpenAPI 3.1 meta-schema that also validates every embedded schema. */
    private const string META_SCHEMA_ID = 'https://spec.openapis.org/oas/3.1/schema-base/2022-10-07';

    /** @var string The schema a Schema Object's dynamic `#meta` reference resolves to under that meta-schema. */
    private const string SCHEMA_OBJECT_REF = self::META_SCHEMA_ID . '#/$defs/schema';

    /** @var list<string> The bundled meta-schema fixtures, each registered under its own `$id`. */
    private const array META_SCHEMA_FIXTURES = [
        'json-schema-2020-12.json',
        'json-schema-2020-12-meta-core.json',
        'json-schema-2020-12-meta-applicator.json',
        'json-schema-2020-12-meta-unevaluated.json',
        'json-schema-2020-12-meta-validation.json',
        'json-schema-2020-12-meta-meta-data.json',
        'json-schema-2020-12-meta-format-annotation.json',
        'json-schema-2020-12-meta-content.json',
        'openapi-3.1-dialect-base.json',
        'openapi-3.1-meta-base.json',
        'openapi-3.1-schema.json',
        'openapi-3.1-schema-base.json',
    ];

    /** @var string The identifier under which an exported document is registered as a schema. */
    private const string EXPORTED_DOCUMENT_ID = 'https://api.example.com/openapi.json';

    /** @var list<string> The temporary docs directories to clean up. */
    private array $docsDirs = [];

    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        SchemaCompiler::clearCache();

        $this->registerResourceMap();
    }

    /**
     * Tear down each test, removing any temporary docs directories.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->docsDirs as $dir) {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }

        $this->docsDirs = [];

        parent::tearDown();
    }

    /**
     * Test that the docs manual lands in info.description and stays valid.
     *
     * @return void
     */
    public function testDocsManualIsInjectedIntoDescriptionAndStaysValid(): void
    {
        $this->registerUserRoutes();

        $dir = $this->makeDocsDir([
            '20-querying.md'   => "# Advanced Querying\n\nFilter with operators.",
            '30-pagination.md' => "# Pagination\n\nPage through records.",
        ]);

        $this->getConfig()->set('api-toolkit.openapi.docs_path', $dir);

        $document = $this->export()->document;

        self::assertIsString($document['info']['description']);
        self::assertStringContainsString('# Advanced Querying', $document['info']['description']);
        self::assertIsString($document['info']['description']);
        self::assertStringContainsString('# Pagination', $document['info']['description']);
        self::assertIsString($document['info']['description']);
        self::assertLessThan(
            strpos($document['info']['description'], '# Pagination'),
            strpos($document['info']['description'], '# Advanced Querying'),
        );

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document carrying the docs manual must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that the emitted document validates as OpenAPI 3.1.
     *
     * @return void
     */
    public function testEmittedDocumentValidatesAsOpenApiThreeOne(): void
    {
        $this->registerUserRoutes();

        $document = $this->export()->document;

        $result = $this->validateAgainstMetaSchema($document);

        self::assertTrue(
            $result->isValid(),
            'Emitted document is not valid OpenAPI 3.1: ' . $this->formatErrors($result),
        );
    }

    /**
     * Test that a document carrying configured servers stays valid.
     *
     * @return void
     */
    public function testDocumentWithServersValidatesAsOpenApiThreeOne(): void
    {
        $this->registerUserRoutes();

        $this->getConfig()->set('api-toolkit.openapi.servers', [
            ['url' => 'https://api.example.com', 'description' => 'Production'],
            [
                'url'         => 'https://{region}.api.example.com/{version}',
                'description' => 'Regional',
                'variables'   => [
                    'region'  => ['default' => 'eu', 'enum' => ['eu', 'us'], 'description' => 'The hosting region.'],
                    'version' => ['default' => 'v1'],
                ],
            ],
            ['url' => 'http://localhost:{port}', 'variables' => ['port' => ['default' => '8000']]],
            ['url' => '/', 'variables' => [], 'x-environment' => 'local'],
        ]);
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'  => [],
            'partner' => ['servers' => [['url' => 'https://partners.example.com']]],
        ]);

        foreach (['public' => 4, 'partner' => 1] as $audience => $count) {

            $document = $this->export($audience)->document;
            $result   = $this->validateAgainstMetaSchema($document);

            self::assertIsArray($document['servers']);
            self::assertCount($count, $document['servers']);
            self::assertTrue($result->isValid(), 'A document carrying servers must validate as OpenAPI 3.1: ' . $this->formatErrors($result));
        }
    }

    /**
     * Test that the document declares a 3.1.x version and populated components.
     *
     * @return void
     */
    public function testDocumentDeclaresThreeOneAndPopulatesComponents(): void
    {
        $document = $this->export()->document;

        self::assertIsString($document['openapi']);
        self::assertStringStartsWith('3.1', $document['openapi']);

        $components = $document['components'];

        self::assertNotEmpty($components['schemas']);
        self::assertNotEmpty($components['parameters']);
        self::assertNotEmpty($components['responses']);
    }

    /**
     * Test that an audience with no routes emits an empty paths object.
     *
     * An empty array would make the document schema-invalid.
     *
     * @return void
     */
    public function testAudienceWithNoDocumentedRoutesEmitsEmptyPathsObject(): void
    {
        // The partner allowlist has no routes opted in, so it documents nothing
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'  => [],
            'partner' => ['posture' => 'allowlist'],
        ]);

        $document = $this->export('partner')->document;

        self::assertArrayHasKey('paths', $document);
        self::assertSame('{}', json_encode($document['paths']));

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document with an empty paths object must validate as OpenAPI 3.1.',
        );
    }

    /**
     * Test that one component schema is emitted per registered resource.
     *
     * @return void
     */
    public function testOneSchemaPerRegisteredResource(): void
    {
        $this->registerUserRoutes();

        $result = $this->export();

        self::assertSame(4, $result->resourceCount);

        $schemas = $result->document['components']['schemas'];

        self::assertIsArray($schemas);

        foreach (['User', 'Organization', 'Post', 'Tag'] as $name) {
            self::assertArrayHasKey($name, $schemas);
        }
    }

    /**
     * Test that every operator appears in the filter parameter.
     *
     * @return void
     */
    public function testEveryOperatorAppearsInTheFilterParameter(): void
    {
        $document  = $this->export()->document;
        $operators = $document['components']['parameters']['Filters']['schema']['x-operators'];

        $expected = [
            '$eq', '$neq', '$gt', '$lt', '$ge', '$le', '$in',
            '$between', '$contains', '$null', '$notNull',
            '$and', '$or', '$has', '$hasnt',
        ];

        foreach ($expected as $operator) {
            self::assertIsIterable($operators);
            self::assertContains($operator, $operators);
        }

        self::assertNotContains('$like', $operators);
    }

    /**
     * Test that the index operation references the shared query parameters.
     *
     * @return void
     */
    public function testIndexOperationReferencesTheSharedQueryParameters(): void
    {
        $this->registerUserRoutes();

        /** @var array<string, array<string, array<string, mixed>>> $paths */
        $paths = $this->export()->document['paths'];

        self::assertSame(
            ['Fields', 'Counts', 'Sums', 'Averages', 'Filters', 'Search', 'Order', 'Limit', 'Page', 'Cursor', 'Pagination'],
            $this->referencedParameterNames($paths['/users']['get']),
        );
    }

    /**
     * Test that soft-delete visibility reaches only soft-deleting model reads.
     *
     * @return void
     */
    public function testVisibilityReachesOnlyTheReadsOfASoftDeletingModel(): void
    {
        $this->registerUserRoutes();
        $this->registerSoftDeletingRoutes();

        /** @var array<string, array<string, array<string, mixed>>> $paths */
        $paths = $this->export()->document['paths'];

        self::assertContains('Trashed', $this->referencedParameterNames($paths['/articles']['get']));
        self::assertContains('Trashed', $this->referencedParameterNames($paths['/articles/{article}']['get']));
        self::assertNotContains('Trashed', $this->referencedParameterNames($paths['/users']['get']));
        self::assertNotContains('Trashed', $this->referencedParameterNames($paths['/users/{user}']['get']));
    }

    /**
     * Test that every parameter reference resolves to a defined component.
     *
     * @return void
     */
    public function testEveryParameterReferenceResolvesToAComponent(): void
    {
        $this->registerUserRoutes();

        $document = $this->export()->document;

        /** @var array<string, mixed> $parameters */
        $parameters = $document['components']['parameters'];

        /** @var array<string, array<string, mixed>> $paths */
        $paths = $document['paths'];

        $defined = array_keys($parameters);
        $seen    = 0;

        foreach ($paths as $operations) {
            foreach ($operations as $operation) {

                if (!is_array($operation)) {
                    continue;
                }

                foreach ($this->referencedParameterNames($operation) as $name) {
                    self::assertContains($name, $defined);
                    $seen++;
                }
            }
        }

        self::assertGreaterThan(0, $seen);
    }

    /**
     * Test that every reference in the document resolves to a component.
     *
     * @return void
     */
    public function testEveryReferenceResolvesToAComponentThatExists(): void
    {
        $this->registerUserRoutes();
        $this->registerOrganizationRoute();
        $this->registerTagInternalRoute();

        $this->assertReferencesResolve($this->export()->document);

        // References can only dangle in the per-audience reduced documents
        $this->registerTwoAudiences();

        $this->assertReferencesResolve($this->export('public')->document);
        $this->assertReferencesResolve($this->export('internal')->document);
    }

    /**
     * Test that a relation to an unregistered resource leaves no dangling ref.
     *
     * @return void
     */
    public function testARelationToAnUnregisteredResourceLeavesNoDanglingReference(): void
    {
        $this->registerUserRoutes();

        // Dropping both related resources leaves two relations unbuilt
        $this->getConfig()->set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);

        SchemaCompiler::clearCache();

        $document   = $this->export()->document;
        $properties = $document['components']['schemas']['User']['properties'];

        self::assertIsArray($document['components']['schemas']);
        self::assertArrayNotHasKey('Organization', $document['components']['schemas']);
        self::assertIsArray($properties['organization']);
        self::assertArrayNotHasKey('$ref', $properties['organization']);
        self::assertTrue($properties['organization']['x-undocumented']);

        $this->assertReferencesResolve($document);
        self::assertTrue($this->validateAgainstMetaSchema($document)->isValid());
    }

    /**
     * Test that one error response is emitted per defined error code.
     *
     * Application exceptions discovered outside the toolkit count too.
     *
     * @return void
     */
    public function testOneResponsePerErrorCode(): void
    {
        $document   = $this->export()->document;
        $responses  = $document['components']['responses'];
        $errorCodes = ErrorCode::cases();

        // The toolkit's codes plus the discovered application exception
        self::assertIsArray($responses);
        self::assertCount(count($errorCodes) + 1, $responses);

        self::assertIsArray($responses);

        foreach ($errorCodes as $code) {
            self::assertArrayHasKey('ErrorResponse' . $code->getCode(), $responses);
        }

        self::assertArrayHasKey('ErrorResponse' . AppErrorCode::WIDGET_FAILURE->getCode(), $responses);
    }

    /**
     * Test that an unannotated timestamp field is inferred as date-time.
     *
     * @return void
     */
    public function testCreatedAtIsEmittedAsADateTimeString(): void
    {
        $this->registerUserRoutes();

        $document  = $this->export()->document;
        $createdAt = $document['components']['schemas']['User']['properties']['created_at'];

        // The column is nullable, so the type-array form is emitted
        self::assertContains('string', (array) $createdAt['type']);
        self::assertSame('date-time', $createdAt['format']);
    }

    /**
     * Test that an opaque compute field is flagged x-undocumented.
     *
     * @return void
     */
    public function testComputeFieldIsFlaggedUndocumented(): void
    {
        $this->registerUserRoutes();

        $document  = $this->export()->document;
        $fullLabel = $document['components']['schemas']['User']['properties']['full_label'];

        self::assertTrue($fullLabel['x-undocumented']);
        self::assertIsArray($fullLabel);
        self::assertArrayNotHasKey('type', $fullLabel);
    }

    /**
     * Test that the shared envelope components are emitted and stay valid.
     *
     * @return void
     */
    public function testEnvelopeComponentsArePresentAndValid(): void
    {
        $document   = $this->export()->document;
        $components = $document['components'];

        self::assertIsArray($components['schemas']);

        foreach (['PaginationMeta', 'PaginationLinks', 'CursorPaginationMeta', 'CursorPaginationLinks'] as $name) {
            self::assertArrayHasKey($name, $components['schemas']);
        }

        self::assertIsArray($components['headers']);
        self::assertArrayHasKey('Total-Count', $components['headers']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'Emitted document with envelope components is not valid OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that each resource schema carries a required `_type` discriminator.
     *
     * @return void
     */
    public function testResourceSchemaCarriesTypeDiscriminator(): void
    {
        $this->registerUserRoutes();

        $user = $this->export()->document['components']['schemas']['User'];

        self::assertSame(['type' => 'string', 'const' => 'users'], $user['properties']['_type']);
        self::assertIsIterable($user['required']);
        self::assertContains('_type', $user['required']);
    }

    /**
     * Test that a to-one relation emits a single reference.
     *
     * @return void
     */
    public function testToOneRelationEmitsSingleReference(): void
    {
        $this->registerUserRoutes();

        $document     = $this->export()->document;
        $organization = $document['components']['schemas']['User']['properties']['organization'];

        self::assertSame(['$ref' => '#/components/schemas/Organization'], $organization);
    }

    /**
     * Test that regenerating after a surface change produces a diff.
     *
     * @return void
     */
    public function testRegenerateAfterChangeShowsADiff(): void
    {
        $this->registerUserRoutes();

        $before = $this->export()->document['components']['schemas']['Organization'];

        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        SchemaCompiler::clearCache();

        $after = $this->export()->document;

        self::assertIsArray($after['components']['schemas']);
        self::assertArrayNotHasKey('Organization', $after['components']['schemas']);
        self::assertNotEmpty($before);

        self::assertTrue(
            $this->validateAgainstMetaSchema($after)->isValid(),
            'Regenerated document is not valid OpenAPI 3.1.',
        );
    }

    /**
     * Test that the command writes a valid document and exits zero.
     *
     * @return void
     */
    public function testCommandWritesAValidDocumentWithExitZero(): void
    {
        $this->registerUserRoutes();

        $path = sys_get_temp_dir() . '/api-toolkit-openapi-' . uniqid('', true) . '.json';

        try {

            $exitCode = Artisan::call(ExportOpenApiCommand::class, ['--output' => $path]);

            self::assertSame(0, $exitCode);
            self::assertStringContainsString('Exported 6 operation(s), 4 resource schema(s)', Artisan::output());
            self::assertFileExists($path);

            $contents = file_get_contents($path);

            self::assertIsString($contents);
            self::assertNotSame('', $contents);

            self::assertTrue(
                $this->validateJson($contents)->isValid(),
                'Document written by the command is not valid OpenAPI 3.1.',
            );

        } finally {
            @unlink($path);
        }
    }

    /**
     * Test that the opis-adapted meta-schema still rejects invalid documents.
     *
     * Guards the in-test transforms from silently hollowing out the validity
     * check.
     *
     * @return void
     */
    public function testAdaptedMetaSchemaStillRejectsInvalidDocuments(): void
    {
        $valid = $this->export()->document;

        self::assertTrue(
            $this->validateAgainstMetaSchema($valid)->isValid(),
            'The real emitted document must validate as OpenAPI 3.1.',
        );

        $threeZero            = $valid;
        $threeZero['openapi'] = '3.0.3';

        self::assertFalse(
            $this->validateAgainstMetaSchema($threeZero)->isValid(),
            'A 3.0 version string must be rejected by the 3.1 meta-schema.',
        );

        $missingInfo = $valid;
        unset($missingInfo['info']);

        self::assertFalse(
            $this->validateAgainstMetaSchema($missingInfo)->isValid(),
            'A document missing the required info object must be rejected.',
        );

        $malformedParameter                                       = $valid;
        $malformedParameter['components']['parameters']['Broken'] = ['description' => 'no name or in'];

        self::assertFalse(
            $this->validateAgainstMetaSchema($malformedParameter)->isValid(),
            'A parameter missing its required name/in must be rejected.',
        );

        $arraySchema                                    = $valid;
        $arraySchema['components']['schemas']['Broken'] = ['type' => 'object', 'properties' => ['field' => []]];

        self::assertFalse(
            $this->validateAgainstMetaSchema($arraySchema)->isValid(),
            'An embedded schema encoded as an array must be rejected.',
        );

        $malformedServer            = $valid;
        $malformedServer['servers'] = [['url' => 'https://{region}.api.example.com/a path']];

        self::assertFalse(
            $this->validateAgainstMetaSchema($malformedServer)->isValid(),
            'A templated server url that is not a uri-reference must be rejected.',
        );
    }

    /**
     * Test that registered routes surface as path operations for the audience.
     *
     * @return void
     */
    public function testRoutesSurfaceAsPathOperationsForAudience(): void
    {
        $this->registerUserRoutes();

        $document = $this->export()->document;
        $paths    = $document['paths'];

        self::assertIsArray($paths['/users']);
        self::assertSame(['get', 'post'], array_keys($paths['/users']));
        self::assertIsArray($paths['/users/{user}']);
        self::assertSame(['get', 'put', 'patch', 'delete'], array_keys($paths['/users/{user}']));

        self::assertSame(['User'], $paths['/users']['get']['tags']);
        self::assertIsArray($paths['/users']['get']['responses']);
        self::assertArrayHasKey('200', $paths['/users']['get']['responses']);
        self::assertArrayHasKey(
            'Total-Count',
            $paths['/users']['get']['responses']['200']['headers'],
        );

        // The whole document is validated below, proving the anyOf is valid
        $indexSchema = $paths['/users']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertCount(2, $indexSchema['anyOf']);
        self::assertSame(
            '#/components/schemas/PaginationMeta',
            $indexSchema['anyOf'][0]['properties']['meta']['$ref'],
        );
        self::assertSame(
            '#/components/schemas/CursorPaginationMeta',
            $indexSchema['anyOf'][1]['properties']['meta']['$ref'],
        );
        self::assertIsArray($paths['/users']['post']['responses']);
        self::assertArrayHasKey('201', $paths['/users']['post']['responses']);
        self::assertIsArray($paths['/users/{user}']['delete']['responses']);
        self::assertArrayHasKey('204', $paths['/users/{user}']['delete']['responses']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document with path operations must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that both pagination shapes validate against the index 200 schema.
     *
     * @return void
     */
    public function testIndexSchemaAcceptsBothPaginationShapes(): void
    {
        $this->registerUserRoutes();

        $document = $this->export()->document;
        $user     = [
            '_type'           => 'users',
            'id'              => 1,
            'name'            => 'Ada',
            'email'           => 'ada@example.com',
            'status'          => 'active',
            'organization_id' => null,
            'created_at'      => '2026-01-01T09:00:00Z',
            'updated_at'      => '2026-01-02T09:00:00Z',
            'full_label'      => 'Ada <ada@example.com>',
            'display_label'   => 'Ada <ada@example.com>',
        ];

        $lengthAware = [
            'data'  => [$user],
            'meta'  => ['total' => 30, 'count' => 1, 'continue' => true],
            'links' => [
                'self'  => 'https://api.example.com/users?page=2',
                'first' => 'https://api.example.com/users?page=1',
                'prev'  => 'https://api.example.com/users?page=1',
                'next'  => 'https://api.example.com/users?page=3',
                'last'  => 'https://api.example.com/users?page=30',
            ],
        ];

        $cursor = [
            'data'  => [$user],
            'meta'  => ['continue' => false],
            'links' => [
                'self' => 'https://api.example.com/users?cursor=eyJpZCI6MX0',
                'prev' => null,
                'next' => null,
            ],
        ];

        foreach (['length-aware' => $lengthAware, 'cursor' => $cursor] as $shape => $page) {

            $result = $this->validateIndexPage($document, $page);

            self::assertTrue(
                $result->isValid(),
                sprintf('A %s page must validate against the index 200 schema: %s', $shape, $this->formatErrors($result)),
            );
        }

        self::assertFalse(
            $this->validateIndexPage($document, ['data' => [$user], 'meta' => ['total' => 30]])->isValid(),
            'A page matching neither pagination shape must be rejected.',
        );
    }

    /**
     * Test that a non-resource route emits a valid x-undocumented operation.
     *
     * @return void
     */
    public function testUndocumentedRouteEmitsValidOperation(): void
    {
        $this->router()->get('status', PathInvokableController::class);

        $document = $this->export()->document;
        $schema   = $document['paths']['/status']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertTrue($schema['properties']['data']['x-undocumented']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document with an undocumented operation must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that a declared resource response keeps its referenced component.
     *
     * @return void
     */
    public function testDeclaredResponseSchemaKeepsReferencedResource(): void
    {
        $this->router()->get('single', [PathResponseSchemaController::class, 'single']);

        $document = $this->export()->document;
        $schema   = $document['paths']['/single']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame('#/components/schemas/User', $schema['properties']['data']['$ref']);
        self::assertIsArray($document['components']['schemas']);
        self::assertArrayHasKey('User', $document['components']['schemas']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document referencing a declared response resource must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that a declared response union keeps its referenced component.
     *
     * @return void
     */
    public function testDeclaredResponseUnionKeepsReferencedResource(): void
    {
        $this->router()->get('session', [PathResponseSchemaController::class, 'mixed']);

        $document = $this->export()->document;
        $schema   = $document['paths']['/session']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame(
            [['$ref' => '#/components/schemas/User'], SignedInShape::schema()],
            $schema['properties']['data']['oneOf'],
        );
        self::assertIsArray($document['components']['schemas']);
        self::assertArrayHasKey('User', $document['components']['schemas']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document declaring a response union must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that a write route emits a valid requestBody from its rules.
     *
     * @return void
     */
    public function testWriteRouteEmitsValidRequestBody(): void
    {
        $this->router()->post('articles', [PathRequestBodyController::class, 'store']);

        $document = $this->export()->document;
        $body     = $document['paths']['/articles']['post']['requestBody'];

        $properties = $body['content']['application/json']['schema']['properties'];

        self::assertTrue($body['required']);
        self::assertIsArray($properties);
        self::assertArrayHasKey('title', $properties);
        self::assertSame(['type' => 'string', 'enum' => ['draft', 'published']], $properties['visibility']);
        self::assertSame(['type' => 'integer', 'enum' => [1, 2, 3]], $properties['level']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document with a requestBody must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that prohibited and unconstrained fields emit valid schemas.
     *
     * @return void
     */
    public function testProhibitedAndUnconstrainedFieldsEmitValidSchemas(): void
    {
        $this->router()->post('accounts', [PathRequestBodyController::class, 'restricted']);

        $document = $this->export()->document;
        $schema   = $document['paths']['/accounts']['post']['requestBody']['content']['application/json']['schema'];

        self::assertSame(['role'], $schema['required']);
        self::assertSame(
            '{"name":{"type":"string","maxLength":255},"email":false,"role":{"type":"string"},"nickname":{"type":"string"},"terms":{},"labels":{"type":"array","items":{}}}',
            json_encode($schema['properties'], JSON_THROW_ON_ERROR),
        );

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document with prohibited and unconstrained fields must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that presence rules emit valid requestBody constraints.
     *
     * @return void
     */
    public function testPresenceRulesEmitValidRequestBodyConstraints(): void
    {
        $this->router()->post('members', [PathRequestBodyController::class, 'presence']);

        $document = $this->export()->document;
        $schema   = $document['paths']['/members']['post']['requestBody']['content']['application/json']['schema'];
        $pointer  = '/paths/~1members/post/requestBody/content/application~1json/schema';
        $uuid     = '7b6f0c1e-5d3a-4c2b-9a8e-1f2d3c4b5a69';

        self::assertSame(['password', 'password_confirmation'], $schema['required']);
        self::assertSame(['pin' => ['pin_repeat'], 'credential' => ['member_id']], $schema['dependentRequired']);
        self::assertSame([
            ['anyOf' => [['required' => ['member_id']], ['required' => ['group_id']]]],
            ['if' => ['required' => ['credential', 'group_id']], 'then' => ['required' => ['address']]],
            ['anyOf' => [['required' => ['email']], ['required' => ['member_id']], ['required' => ['group_id']]]],
        ], $schema['allOf']);
        self::assertSame([['required' => ['id']], ['required' => ['name']]], $schema['properties']['contacts']['items']['anyOf']);

        self::assertTrue($this->validateAgainstExported($document, $pointer, ['password' => 'x', 'password_confirmation' => 'x', 'member_id' => $uuid])->isValid());
        self::assertFalse($this->validateAgainstExported($document, $pointer, ['password' => 'x', 'password_confirmation' => 'x'])->isValid());
        self::assertFalse($this->validateAgainstExported($document, $pointer, ['password' => 'x', 'member_id' => $uuid])->isValid());

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document with presence constraints must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that enum-validated request fields reference enum components.
     *
     * @return void
     */
    public function testRequestEnumFieldsReferenceComponentsListingTheirValues(): void
    {
        $this->router()->post('articles', [PathRequestBodyController::class, 'store']);

        $document   = $this->export()->document;
        $properties = $document['paths']['/articles']['post']['requestBody']['content']['application/json']['schema']['properties'];
        $schemas    = $document['components']['schemas'];

        self::assertSame(['$ref' => '#/components/schemas/UserStatus'], $properties['status']);
        self::assertSame(['anyOf' => [['$ref' => '#/components/schemas/Platform'], ['type' => 'null']]], $properties['platform']);
        self::assertSame(['type' => 'string', 'enum' => ['active', 'inactive', 'banned']], $schemas['UserStatus']);
        self::assertSame(['type' => 'string', 'enum' => ['web', 'mobile_app', 'desktop']], $schemas['Platform']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document with enum components must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that only()/except()-narrowed enum fields list their values inline.
     *
     * @return void
     */
    public function testNarrowedEnumRequestFieldsListTheValuesTheyAccept(): void
    {
        $this->router()->post('articles', [PathRequestBodyController::class, 'store']);

        $document   = $this->export()->document;
        $properties = $document['paths']['/articles']['post']['requestBody']['content']['application/json']['schema']['properties'];

        self::assertSame(['type' => 'integer', 'enum' => [2, 3]], $properties['rank']);
        self::assertSame(['type' => ['string', 'null'], 'enum' => ['active', 'banned', null]], $properties['standing']);
        self::assertSame(['type' => 'string', 'enum' => []], $properties['retired']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document with narrowed enum fields must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that keyed array fields emit valid request bodies.
     *
     * @return void
     */
    public function testKeyedArrayFieldsEmitValidRequestBodies(): void
    {
        $this->router()->post('keyed', [PathKeyedArrayController::class, 'store']);

        $document = $this->export()->document;
        $schema   = $document['paths']['/keyed']['post']['requestBody']['content']['application/json']['schema'];

        self::assertSame(
            '{"type":"object","properties":{'
            . '"tags":{"type":["object","array"],"minItems":1,"properties":{"name":{"type":"string"}},"minProperties":1},'
            . '"address":{"type":"object","properties":{"city":{"type":"string"}},"required":["city"]},'
            . '"labels":{"type":["object","array","null"],"properties":{"primary":{},"secondary":{}},"additionalProperties":false,"maxItems":0},'
            . '"items":{}},"required":["tags","address"]}',
            json_encode($schema, JSON_THROW_ON_ERROR),
        );

        $payloads = [
            '{"tags":["a"],"address":{"city":"x"},"labels":null,"items":["a"]}'   => true,
            '{"tags":{"name":"x"},"address":{"city":"x"},"labels":{"primary":1}}' => true,
            '{"tags":["a"],"address":{"city":"x"},"labels":[],"items":[]}'        => true,
            '{"tags":{"name":1},"address":{"city":"x"}}'                          => false,
            '{"tags":["a"],"address":["x"]}'                                      => false,
            '{"tags":["a"],"address":{"city":"x"},"labels":{"other":1}}'          => false,
            '{"tags":["a"],"address":{"city":"x"},"items":{"0":"a"}}'             => true,
            '{"tags":[],"address":{"city":"x"}}'                                  => false,
            '{"tags":{},"address":{"city":"x"}}'                                  => false,
            '{"tags":["a"]}'                                                      => false,
            '{"tags":["a"],"address":null}'                                       => false,
        ];

        foreach ($payloads as $payload => $accepted) {
            self::assertSame($accepted, RequestValidator::make(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), KeyedArrayRequestInput::rules())->passes(), $payload);
            self::assertSame($accepted, $this->validateRequestBody($document, '/keyed', $payload)->isValid(), $payload);
        }

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document with keyed array fields must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that numeric rule keys emit valid request bodies.
     *
     * @return void
     */
    public function testNumericRuleKeysEmitValidRequestBodies(): void
    {
        $this->router()->post('numbered', [PathNumericKeyController::class, 'store']);
        $this->router()->post('indexed', [PathNumericKeyController::class, 'indexed']);

        $document = $this->export()->document;
        $numbered = $document['paths']['/numbered']['post']['requestBody']['content']['application/json']['schema'];
        $indexed  = $document['paths']['/indexed']['post']['requestBody']['content']['application/json']['schema'];

        self::assertSame(
            '{"type":"object","properties":{"0":{"type":"string"},"tags":{"type":"array","minItems":1},'
            . '"meta":{"type":"object","properties":{"0":{"type":"string"}},"required":["0"]}},"required":["0","tags","meta"]}',
            json_encode($numbered, JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            '{"type":"object","properties":{"0":{"type":"string"},"1":{"type":"integer"}},"required":["0"]}',
            json_encode($indexed, JSON_THROW_ON_ERROR),
        );

        $payload = '{"0":"first","tags":["a",1],"meta":{"0":"x"}}';

        self::assertTrue(RequestValidator::make(json_decode($payload, true, 512, JSON_THROW_ON_ERROR), NumericKeyRequestInput::rules())->passes());
        self::assertTrue($this->validateRequestBody($document, '/numbered', $payload)->isValid());
        self::assertFalse($this->validateRequestBody($document, '/numbered', '{"tags":["a"]}')->isValid());

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document with numeric rule keys must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that a route excluded from an audience is absent from its paths.
     *
     * @return void
     */
    public function testAudienceScopingFiltersRoutesBetweenAudiences(): void
    {
        $this->registerTwoAudiences();
        $this->registerOrganizationRoute();
        $this->registerTagInternalRoute();

        $public = $this->export('public')->document['paths'];

        self::assertIsArray($public);
        self::assertArrayHasKey('/organizations', $public);
        self::assertArrayNotHasKey('/tags', $public);

        $internal = $this->export('internal')->document['paths'];

        self::assertIsArray($internal);
        self::assertArrayHasKey('/organizations', $internal);
        self::assertArrayHasKey('/tags', $internal);
    }

    /**
     * Test that audience schemas are filtered to reachable resources.
     *
     * @return void
     */
    public function testAudienceSchemasAreFilteredToReachableResources(): void
    {
        $this->registerTwoAudiences();
        $this->registerOrganizationRoute();
        $this->registerTagInternalRoute();

        $public = $this->export('public')->document['components']['schemas'];

        self::assertIsArray($public);
        self::assertArrayHasKey('Organization', $public);
        self::assertArrayNotHasKey('Tag', $public);
        self::assertArrayNotHasKey('User', $public);

        $internal = $this->export('internal')->document['components']['schemas'];

        self::assertIsArray($internal);
        self::assertArrayHasKey('Tag', $internal);
        self::assertArrayHasKey('Organization', $internal);
    }

    /**
     * Test that shared pagination and error schemas survive audience filtering.
     *
     * @return void
     */
    public function testSharedSchemasSurviveAudienceFiltering(): void
    {
        $this->registerOrganizationRoute();

        $schemas = $this->export('public')->document['components']['schemas'];

        self::assertIsArray($schemas);

        foreach (['PaginationMeta', 'PaginationLinks', 'CursorPaginationMeta', 'CursorPaginationLinks', 'ErrorEnvelope'] as $name) {
            self::assertArrayHasKey($name, $schemas);
        }

        self::assertArrayNotHasKey('User', $schemas);
        self::assertArrayNotHasKey('Post', $schemas);
        self::assertArrayNotHasKey('Tag', $schemas);
    }

    /**
     * Test that --all writes one valid document per configured audience.
     *
     * @return void
     */
    public function testCommandAllFlagWritesOneDocumentPerAudience(): void
    {
        $this->registerTwoAudiences();
        $this->registerOrganizationRoute();
        $this->registerTagInternalRoute();

        $base     = sys_get_temp_dir() . '/api-toolkit-all-' . uniqid('', true) . '.json';
        $public   = $this->audiencePath($base, 'public');
        $internal = $this->audiencePath($base, 'internal');

        try {

            $exitCode = Artisan::call(ExportOpenApiCommand::class, ['--output' => $base, '--all' => true]);

            self::assertSame(0, $exitCode);
            self::assertFileExists($public);
            self::assertFileExists($internal);

            $publicPaths   = $this->decodePaths($public);
            $internalPaths = $this->decodePaths($internal);

            self::assertArrayHasKey('/organizations', $publicPaths);
            self::assertArrayNotHasKey('/tags', $publicPaths);
            self::assertArrayHasKey('/tags', $internalPaths);

            self::assertTrue($this->validateJson($this->read($public))->isValid(), 'The public document must be valid.');
            self::assertTrue($this->validateJson($this->read($internal))->isValid(), 'The internal document must be valid.');

        } finally {
            @unlink($public);
            @unlink($internal);
        }
    }

    /**
     * Test that --audience exports the named audience to the resolved path.
     *
     * @return void
     */
    public function testCommandExportsNamedAudienceToResolvedPath(): void
    {
        $this->registerTwoAudiences();
        $this->registerOrganizationRoute();
        $this->registerTagInternalRoute();

        $path = sys_get_temp_dir() . '/api-toolkit-named-' . uniqid('', true) . '.json';

        try {

            $exitCode = Artisan::call(ExportOpenApiCommand::class, ['--output' => $path, '--audience' => 'internal']);

            self::assertSame(0, $exitCode);
            self::assertFileExists($path);

            self::assertArrayHasKey('/tags', $this->decodePaths($path));

        } finally {
            @unlink($path);
        }
    }

    /**
     * Test that an unknown audience fails with a clear error and non-zero exit.
     *
     * @return void
     */
    public function testCommandFailsOnUnknownAudience(): void
    {
        $this->registerUserRoutes();

        $exitCode = Artisan::call(ExportOpenApiCommand::class, ['--audience' => 'nonexistent']);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('Unknown audience', Artisan::output());
    }

    /**
     * Test that an authenticated route emits valid security components.
     *
     * @return void
     */
    public function testAuthenticatedRouteEmitsValidSecurity(): void
    {
        $this->configureGuards();
        $this->router()->get('users', [PathFixtureController::class, 'index'])->middleware('auth:api,admin');

        $document = $this->export()->document;

        self::assertSame(
            [['bearerAuth' => []], ['basicAuth' => []]],
            $document['paths']['/users']['get']['security'],
        );

        $schemes = $document['components']['securitySchemes'];

        self::assertSame(['type' => 'http', 'scheme' => 'bearer'], $schemes['bearerAuth']);
        self::assertSame(['type' => 'http', 'scheme' => 'basic'], $schemes['basicAuth']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document with security requirements must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Test that the resource REST responses survive full assembly.
     *
     * @return void
     */
    public function testResourceResponseBodiesSurviveAssembly(): void
    {
        $this->registerUserRoutes();

        $paths    = $this->export()->document['paths'];
        $envelope = (new EnvelopeBuilder)->singleEnvelope('#/components/schemas/User');

        self::assertSame(
            $envelope,
            $paths['/users/{user}']['get']['responses']['200']['content']['application/json']['schema'],
        );
        self::assertSame(
            $envelope,
            $paths['/users']['post']['responses']['201']['content']['application/json']['schema'],
        );

        $destroy = $paths['/users/{user}']['delete']['responses']['204'];

        self::assertSame(['description' => 'The resource was deleted.'], $destroy);
        self::assertArrayNotHasKey('content', $destroy);
    }

    /**
     * Test that @throws-derived error responses survive assembly.
     *
     * @return void
     */
    public function testThrowsDerivedErrorResponsesSurviveAssembly(): void
    {
        $this->registerUserRoutes();

        $paths = $this->export()->document['paths'];

        $notFound = $paths['/users/{user}']['get']['responses']['404']['content']['application/json'];

        self::assertSame('#/components/schemas/ErrorEnvelope', $notFound['schema']['$ref']);
        self::assertSame(
            ['NotFoundException' => ['value' => ['error' => ['status' => 404, 'code' => 10103]]]],
            $notFound['examples'],
        );

        $conflict = $paths['/users']['post']['responses']['409']['content']['application/json'];

        self::assertSame('#/components/schemas/ErrorEnvelope', $conflict['schema']['$ref']);
        self::assertSame(
            ['ConflictException' => ['value' => ['error' => ['status' => 409, 'code' => 10108]]]],
            $conflict['examples'],
        );
    }

    /**
     * Test that declared response headers survive assembly and stay valid.
     *
     * @return void
     */
    public function testDeclaredResponseHeadersSurviveAssemblyAndStayValid(): void
    {
        $this->router()->post('sign-in', [PathResponseHeaderController::class, 'signIn']);
        $this->router()->post('quota', [PathResponseHeaderController::class, 'quota'])->middleware('throttle:60,1');
        $this->router()->get('headered-users', [PathHeaderedUserController::class, 'index']);
        $this->router()->delete('headered-users/{user}', [PathHeaderedUserController::class, 'destroy']);

        $document = $this->export()->document;
        $paths    = $document['paths'];

        self::assertIsArray($paths['/sign-in']['post']['responses']['200']['headers']);
        self::assertSame(['Set-Cookie', 'X-Session-Id'], array_keys($paths['/sign-in']['post']['responses']['200']['headers']));
        self::assertIsArray($paths['/sign-in']['post']['responses']['429']['headers']);
        self::assertSame(['Retry-After'], array_keys($paths['/sign-in']['post']['responses']['429']['headers']));
        self::assertIsArray($paths['/quota']['post']['responses']['429']['headers']);
        self::assertSame(
            ['retry-after', 'X-Quota-Reset', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
            array_keys($paths['/quota']['post']['responses']['429']['headers']),
        );
        self::assertIsArray($paths['/headered-users']['get']['responses']['200']['headers']);
        self::assertSame(['Total-Count', 'Link'], array_keys($paths['/headered-users']['get']['responses']['200']['headers']));
        self::assertIsArray($paths['/headered-users/{user}']['delete']['responses']['204']['headers']);
        self::assertSame(['X-Deleted-At'], array_keys($paths['/headered-users/{user}']['delete']['responses']['204']['headers']));
        self::assertIsArray($paths['/sign-in']['post']['responses']['409']);
        self::assertArrayNotHasKey('headers', $paths['/sign-in']['post']['responses']['409']);

        $result = $this->validateAgainstMetaSchema($document);

        self::assertTrue($result->isValid(), 'A document carrying declared headers must validate as OpenAPI 3.1: ' . $this->formatErrors($result));
        $this->assertReferencesResolve($document);
    }

    /**
     * Test that declared response statuses survive assembly and stay valid.
     *
     * @return void
     */
    public function testDeclaredResponseStatusesSurviveAssemblyAndStayValid(): void
    {
        $this->router()->get('status-users', [PathStatusUserController::class, 'index']);
        $this->router()->put('status-users/{user}', [PathStatusUserController::class, 'update']);
        $this->router()->delete('status-users/{user}', [PathStatusUserController::class, 'destroy']);
        $this->router()->delete('sessions', [PathResponseStatusController::class, 'destroyAll']);
        $this->router()->post('jobs', [PathResponseStatusController::class, 'queue']);
        $this->router()->post('reset', [PathResponseStatusController::class, 'reset']);

        $document = $this->export()->document;
        $paths    = $document['paths'];

        self::assertIsArray($paths['/status-users/{user}']['put']['responses']['204']);
        self::assertSame(['description', 'headers'], array_keys($paths['/status-users/{user}']['put']['responses']['204']));
        self::assertIsArray($paths['/status-users/{user}']['put']['responses']);
        self::assertArrayNotHasKey('200', $paths['/status-users/{user}']['put']['responses']);
        self::assertIsArray($paths['/sessions']['delete']['responses']['204']);
        self::assertSame(['description', 'headers'], array_keys($paths['/sessions']['delete']['responses']['204']));
        self::assertIsArray($paths['/sessions']['delete']['responses']);
        self::assertArrayNotHasKey('200', $paths['/sessions']['delete']['responses']);
        self::assertIsArray($paths['/reset']['post']['responses']['205']);
        self::assertSame(['description', 'headers'], array_keys($paths['/reset']['post']['responses']['205']));
        self::assertSame(
            WidgetShape::schema(),
            $paths['/jobs']['post']['responses']['202']['content']['application/json']['schema']['properties']['data'],
        );
        self::assertIsArray($paths['/status-users']['get']['responses']['206']['headers']);
        self::assertSame(['Total-Count', 'X-Request-Id'], array_keys($paths['/status-users']['get']['responses']['206']['headers']));
        self::assertIsArray($paths['/status-users/{user}']['delete']['responses']['200']);
        self::assertArrayHasKey('content', $paths['/status-users/{user}']['delete']['responses']['200']);

        $result = $this->validateAgainstMetaSchema($document);

        self::assertTrue($result->isValid(), 'A document carrying declared statuses must validate as OpenAPI 3.1: ' . $this->formatErrors($result));
        $this->assertReferencesResolve($document);
    }

    /**
     * Test that a body declared beside a no-content status fails the export.
     *
     * @return void
     */
    public function testNoContentStatusBesideABodyFailsTheExport(): void
    {
        $this->router()->put('status-users/{user}', [PathStatusConflictController::class, 'update']);

        $this->expectException(ResponseStatusConflictException::class);
        $this->expectExceptionMessage(sprintf(
            'The 204 status declared for %s::update answers with no content and cannot carry a #[ResponseSchema] body',
            PathStatusConflictController::class,
        ));

        $this->export();
    }

    /**
     * Test that the baseline error statuses attach per REST action.
     *
     * @return void
     */
    public function testBaselineErrorStatusesAttachPerAction(): void
    {
        $this->router()->group(['middleware' => 'auth'], function (): void {
            $this->registerUserRoutes();
        });

        $paths = $this->export()->document['paths'];

        self::assertIsArray($paths['/users']['get']['responses']);
        self::assertSame([200, 401, 403, 500], array_keys($paths['/users']['get']['responses']));
        self::assertIsArray($paths['/users']['post']['responses']);
        self::assertSame([201, 401, 403, 409, 422, 500], array_keys($paths['/users']['post']['responses']));
        self::assertIsArray($paths['/users/{user}']['get']['responses']);
        self::assertSame([200, 401, 403, 404, 500], array_keys($paths['/users/{user}']['get']['responses']));
        self::assertIsArray($paths['/users/{user}']['put']['responses']);
        self::assertSame([200, 401, 403, 404, 422, 500], array_keys($paths['/users/{user}']['put']['responses']));
        self::assertIsArray($paths['/users/{user}']['delete']['responses']);
        self::assertSame([204, 401, 403, 404, 500], array_keys($paths['/users/{user}']['delete']['responses']));
    }

    /**
     * Test that a to-many relation emits an array of references.
     *
     * @return void
     */
    public function testToManyRelationEmitsArrayOfReferencesAndKeepsChild(): void
    {
        $this->registerUserRoutes();

        $document = $this->export()->document;
        $posts    = $document['components']['schemas']['User']['properties']['posts'];

        self::assertSame(
            ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Post']],
            $posts,
        );

        self::assertIsArray($document['components']['schemas']);
        self::assertArrayHasKey('Post', $document['components']['schemas']);
    }

    /**
     * Test that a nullable field emits the 2020-12 nullable type union.
     *
     * @return void
     */
    public function testNullableFieldEmitsTypeUnion(): void
    {
        $this->registerUserRoutes();

        $document  = $this->export()->document;
        $createdAt = $document['components']['schemas']['User']['properties']['created_at'];

        self::assertSame(['string', 'null'], $createdAt['type']);
        self::assertSame('date-time', $createdAt['format']);
    }

    /**
     * Test that the export runs without live column introspection.
     *
     * @return void
     */
    public function testExportSurvivesWithoutColumnIntrospection(): void
    {
        $this->registerUserRoutes();

        $inner = $this->makeApplication()->make(SchemaIntrospectionProvider::class);

        $this->makeApplication()->instance(
            SchemaIntrospectionProvider::class,
            new ColumnlessIntrospectionProvider($inner),
        );

        SchemaCompiler::clearCache();

        $document = $this->export()->document;
        $schemas  = $document['components']['schemas'];

        self::assertSame(['type' => 'boolean'], $schemas['Post']['properties']['published']);

        self::assertSame([
            'type'            => ['string', 'null'],
            'format'          => 'date-time',
            'x-query-surface' => ['sort' => ['key' => 'created_at', 'indexed' => true]],
        ], $schemas['User']['properties']['created_at']);

        // A backed-enum cast still references its emitted named component
        self::assertSame([
            '$ref'            => '#/components/schemas/UserStatus',
            'x-query-surface' => [
                'filter' => [
                    'key'        => 'status',
                    'capability' => 'enum',
                    'operators'  => ['$eq', '$in', '$neq', '$null', '$notNull'],
                ],
                'sort' => ['key' => 'status', 'indexed' => true],
            ],
        ], $schemas['User']['properties']['status']);
        self::assertSame(['type' => 'string', 'enum' => ['active', 'inactive', 'banned']], $schemas['UserStatus']);

        self::assertTrue(
            $this->validateAgainstMetaSchema($document)->isValid(),
            'A document exported without column introspection must validate as OpenAPI 3.1: ' . $this->formatErrors($this->validateAgainstMetaSchema($document)),
        );
    }

    /**
     * Create a temporary docs directory seeded with the files.
     *
     * @param  array<string, string>  $files
     * @return string
     */
    private function makeDocsDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/api-toolkit-docs-' . uniqid('', true);

        mkdir($dir);

        foreach ($files as $name => $contents) {
            file_put_contents($dir . '/' . $name, $contents);
        }

        $this->docsDirs[] = $dir;

        return $dir;
    }

    /**
     * Configure the auth guards the security derivation reads drivers from.
     *
     * @return void
     */
    private function configureGuards(): void
    {
        $config = $this->getConfig();

        $config->set('auth.defaults.guard', 'web');
        $config->set('auth.guards.api', ['driver' => 'jwt']);
        $config->set('auth.guards.admin', ['driver' => 'basic']);
    }

    /**
     * Run the export use case against the container-resolved graph.
     *
     * @param  string|null  $audience
     * @return \SineMacula\ApiToolkit\OpenApi\ExportResult
     */
    private function export(?string $audience = null): ExportResult
    {
        /** @var \SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents $exporter */
        $exporter = $this->makeApplication()->make(ExportOpenApiComponents::class);

        return $exporter->export($audience);
    }

    /**
     * Validate a document against the opis-adapted OpenAPI 3.1 meta-schema.
     *
     * @param  array<string, mixed>  $document
     * @return \Opis\JsonSchema\ValidationResult
     */
    private function validateAgainstMetaSchema(array $document): ValidationResult
    {
        return $this->validator()->validate(Helper::toJSON($document), self::META_SCHEMA_ID);
    }

    /**
     * Validate a payload against the exported /users index 200 schema.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $page
     * @return \Opis\JsonSchema\ValidationResult
     */
    private function validateIndexPage(array $document, array $page): ValidationResult
    {
        return $this->validateAgainstExported($document, '/paths/~1users/get/responses/200/content/application~1json/schema', $page);
    }

    /**
     * Validate a payload against the exported schema at a JSON pointer.
     *
     * @param  array<string, mixed>  $document
     * @param  string  $pointer
     * @param  array<string, mixed>  $payload
     * @return \Opis\JsonSchema\ValidationResult
     */
    private function validateAgainstExported(array $document, string $pointer, array $payload): ValidationResult
    {
        $validator = new Validator;
        $resolver  = $validator->resolver();

        assert($resolver instanceof SchemaResolver);

        $exported = Helper::toJSON($document);

        self::assertIsObject($exported);

        $resolver->registerRaw($exported, self::EXPORTED_DOCUMENT_ID);

        return $validator->validate(Helper::toJSON((object) $payload), self::EXPORTED_DOCUMENT_ID . '#' . $pointer);
    }

    /**
     * Validate a JSON payload against the path's exported POST body schema.
     *
     * @param  array<string, mixed>  $document
     * @param  string  $path
     * @param  string  $payload
     * @return \Opis\JsonSchema\ValidationResult
     */
    private function validateRequestBody(array $document, string $path, string $payload): ValidationResult
    {
        $validator = new Validator;
        $resolver  = $validator->resolver();

        assert($resolver instanceof SchemaResolver);

        $exported = Helper::toJSON($document);

        self::assertIsObject($exported);

        $resolver->registerRaw($exported, self::EXPORTED_DOCUMENT_ID);

        return $validator->validate(
            json_decode($payload, false, 512, JSON_THROW_ON_ERROR),
            self::EXPORTED_DOCUMENT_ID . '#/paths/' . str_replace('/', '~1', $path) . '/post/requestBody/content/application~1json/schema',
        );
    }

    /**
     * Validate a raw JSON document string against the bundled meta-schema.
     *
     * Decoding to objects keeps an empty `paths` object distinct from an empty
     * array.
     *
     * @param  string  $json
     * @return \Opis\JsonSchema\ValidationResult
     */
    private function validateJson(string $json): ValidationResult
    {
        return $this->validator()->validate(json_decode($json, false, 512, JSON_THROW_ON_ERROR), self::META_SCHEMA_ID);
    }

    /**
     * Build a validator with every bundled meta-schema registered and adapted.
     *
     * A uri-reference is checked after substituting server variable templates
     * with a name, or a digit so a templated port holds.
     *
     * @return \Opis\JsonSchema\Validator
     */
    private function validator(): Validator
    {
        $validator = new Validator;
        $resolver  = $validator->resolver();
        $formats   = $validator->parser()->getFormatResolver();

        assert($resolver instanceof SchemaResolver);
        assert($formats instanceof FormatResolver);

        foreach (self::META_SCHEMA_FIXTURES as $fixture) {
            /** @var array<string, mixed> $schema */
            $schema = json_decode($this->fixture($fixture), true, 512, JSON_THROW_ON_ERROR);

            $this->adaptForOpis($schema);

            assert(is_string($schema['$id']));

            $resolver->registerRaw(json_encode($schema, JSON_THROW_ON_ERROR), $schema['$id']);
        }

        $formats->registerCallable('string', 'uri-reference', static function (string $value): bool {
            foreach (['variable', '0'] as $substitute) {
                if (UriFormats::uriReference((string) preg_replace('/\{[^{}]+\}/', $substitute, $value))) {
                    return true;
                }
            }

            return false;
        });

        return $validator;
    }

    /**
     * Apply the two opis/json-schema compatibility transforms in place.
     *
     * Rewrites `$dynamicRef: "#meta"` to a static `$ref` and relaxes
     * `unevaluatedProperties: false` to `true`, working around opis gaps
     * without weakening any validity constraint; see the fixtures README.
     *
     * @param  array<array-key, mixed>  $node
     * @return void
     */
    private function adaptForOpis(array &$node): void
    {
        if (($node['$dynamicRef'] ?? null) === '#meta') {
            unset($node['$dynamicRef']);
            $node['$ref'] = self::SCHEMA_OBJECT_REF;
        }

        if (($node['unevaluatedProperties'] ?? null) === false) {
            $node['unevaluatedProperties'] = true;
        }

        foreach ($node as &$value) {
            if (!is_array($value)) {
                continue;
            }

            $this->adaptForOpis($value);
        }
    }

    /**
     * Read a bundled fixture file by name.
     *
     * @param  string  $name
     * @return string
     */
    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../../Fixtures/OpenApi/' . $name);

        assert(is_string($contents));

        return $contents;
    }

    /**
     * Format a validation result's errors for a failure message.
     *
     * @param  \Opis\JsonSchema\ValidationResult  $result
     * @return string
     */
    private function formatErrors(ValidationResult $result): string
    {
        $error = $result->error();

        if ($error === null) {
            return '(no error)';
        }

        return json_encode((new ErrorFormatter)->format($error), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * Assert every reference is local and resolves to an existing component.
     *
     * @param  array<string, mixed>  $document
     * @return void
     */
    private function assertReferencesResolve(array $document): void
    {
        $references = $this->collectReferences($document);

        self::assertNotEmpty($references);

        foreach ($references as $reference) {

            self::assertStringStartsWith('#/', $reference, 'Only a local reference can be resolved within the document.');
            self::assertNotNull(
                $this->resolveReference($document, $reference),
                sprintf('The reference "%s" resolves to no component.', $reference),
            );
        }
    }

    /**
     * Collect every distinct reference the node carries, at any depth.
     *
     * @param  mixed  $node
     * @return array<int, string>
     */
    private function collectReferences(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }

        $references = [];

        foreach ($node as $key => $value) {

            if ($key === '$ref' && is_string($value)) {

                $references[] = $value;
                continue;
            }

            $references = [...$references, ...$this->collectReferences($value)];
        }

        return array_values(array_unique($references));
    }

    /**
     * Resolve a local reference against the document, or null when missing.
     *
     * @param  array<string, mixed>  $document
     * @param  string  $reference
     * @return mixed
     */
    private function resolveReference(array $document, string $reference): mixed
    {
        $node = $document;

        foreach (explode('/', substr($reference, 2)) as $segment) {

            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * List the shared query-parameter component names the operation references.
     *
     * @param  array<array-key, mixed>  $operation
     * @return array<int, string>
     */
    private function referencedParameterNames(array $operation): array
    {
        $names      = [];
        $parameters = $operation['parameters'] ?? [];

        if (!is_array($parameters)) {
            return [];
        }

        foreach ($parameters as $parameter) {

            $reference = is_array($parameter) ? $parameter['$ref'] ?? null : null;

            if (!is_string($reference)) {
                continue;
            }

            $names[] = str_replace('#/components/parameters/', '', $reference);
        }

        return $names;
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
     * Register the read routes of a controller reading a soft-deleting model.
     *
     * @return void
     */
    private function registerSoftDeletingRoutes(): void
    {
        /** @var array<class-string, class-string> $map */
        $map = $this->getConfig()->get('api-toolkit.resources.resource_map');

        $this->getConfig()->set(
            'api-toolkit.resources.resource_map',
            [...$map, Article::class => ArticleResource::class],
        );

        SchemaCompiler::clearCache();

        $router = $this->router();

        $router->get('articles', [PathArticleController::class, 'index']);
        $router->get('articles/{article}', [PathArticleController::class, 'show']);
    }

    /**
     * Register a public organization route, a reachable leaf resource.
     *
     * @return void
     */
    private function registerOrganizationRoute(): void
    {
        $this->router()->get('organizations', [PathOrganizationController::class, 'index']);
    }

    /**
     * Register the internal-only tag route.
     *
     * @return void
     */
    private function registerTagInternalRoute(): void
    {
        $this->router()->get('tags', [PathTagInternalController::class, 'index']);
    }

    /**
     * Declare a public and an internal audience on the config repository.
     *
     * @return void
     */
    private function registerTwoAudiences(): void
    {
        $this->getConfig()->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => [],
        ]);
    }

    /**
     * Derive a per-audience output path the way the command does.
     *
     * @param  string  $base
     * @param  string  $audience
     * @return string
     */
    private function audiencePath(string $base, string $audience): string
    {
        return substr($base, 0, -strlen('.json')) . '.' . $audience . '.json';
    }

    /**
     * Decode the paths object from a written document by path.
     *
     * @param  string  $path
     * @return array<string, mixed>
     */
    private function decodePaths(string $path): array
    {
        /** @var array{paths?: array<string, mixed>} $document */
        $document = json_decode($this->read($path), true, 512, JSON_THROW_ON_ERROR);

        return $document['paths'] ?? [];
    }

    /**
     * Read a written document by path, asserting the read succeeds.
     *
     * @param  string  $path
     * @return string
     */
    private function read(string $path): string
    {
        $contents = file_get_contents($path);

        self::assertIsString($contents);

        return $contents;
    }

    /**
     * Resolve the application router singleton.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        return $this->makeApplication()->make(Router::class);
    }

    /**
     * Register the fixture resource map on the config repository.
     *
     * @return void
     */
    private function registerResourceMap(): void
    {
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
            Post::class         => PostResource::class,
            Tag::class          => TagResource::class,
        ]);
    }

    /**
     * Get the config repository instance.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function getConfig(): ConfigRepository
    {
        /** @var \Illuminate\Contracts\Config\Repository */
        return $this->makeApplication()->make('config');
    }

    /**
     * Get the application instance.
     *
     * @return \Illuminate\Foundation\Application
     */
    private function makeApplication(): Application
    {
        assert($this->app !== null);

        return $this->app;
    }
}
