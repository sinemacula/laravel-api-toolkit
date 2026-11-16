<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\ExportResult;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorCatalogueReader;
use SineMacula\ApiToolkit\OpenApi\OpenApiAssembler;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ClassNameResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentableRouteFilter;
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
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\InternalOnlyTagController;
use Tests\Fixtures\OpenApi\PathOrganizationController;
use Tests\Fixtures\OpenApi\PathResponseSchemaController;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\QueryableTagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ExportOpenApiComponents use case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportOpenApiComponents::class)]
#[CoversClass(ExportResult::class)]
final class ExportOpenApiComponentsTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /** @var array<class-string, class-string> The default resource map. */
    private const array RESOURCE_MAP = [
        User::class         => UserResource::class,
        Organization::class => OrganizationResource::class,
    ];

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
    }

    /**
     * Test that the use case returns the assembled document inside its result.
     *
     * @return void
     */
    public function testReturnsTheAssembledDocument(): void
    {
        $result = $this->export();

        self::assertIsString($result->document['openapi']);
        self::assertStringStartsWith('3.1', $result->document['openapi']);
        self::assertArrayHasKey('components', $result->document);
    }

    /**
     * Test that the resource count equals the registry when nothing is pruned.
     *
     * @return void
     */
    public function testSummaryResourceCountMatchesTheRegistryWhenNothingIsPruned(): void
    {
        $this->router()->get('users/{user}', [PathResponseSchemaController::class, 'single']);

        $result = $this->export();

        self::assertIsArray($result->document['components']['schemas']);
        self::assertArrayHasKey('User', $result->document['components']['schemas']);
        self::assertArrayHasKey('Organization', $result->document['components']['schemas']);
        self::assertSame(2, $result->resourceCount);
    }

    /**
     * Test that the resource count leaves out the envelope and defined schemas.
     *
     * @return void
     */
    public function testSummaryResourceCountExcludesTheSharedEnvelopeSchemas(): void
    {
        $this->router()->get('widget', [PathResponseSchemaController::class, 'widget']);

        $result = $this->export();

        self::assertIsArray($result->document['components']['schemas']);
        self::assertArrayHasKey('WidgetShape', $result->document['components']['schemas']);
        self::assertSame(0, $result->resourceCount);
    }

    /**
     * Test that each audience counts the resources its own document contains.
     *
     * @return void
     */
    public function testSummaryResourceCountMatchesThePrunedAudienceDocument(): void
    {
        $this->registerPublicAndInternalAudiences();

        $resourceMap = [
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
            Tag::class          => QueryableTagResource::class,
        ];

        $public   = $this->export('public', $resourceMap);
        $internal = $this->export('internal', $resourceMap);

        self::assertIsArray($public->document['components']['schemas']);
        self::assertArrayNotHasKey('QueryableTag', $public->document['components']['schemas']);
        self::assertSame(1, $public->resourceCount);

        self::assertIsArray($internal->document['components']['schemas']);
        self::assertArrayHasKey('QueryableTag', $internal->document['components']['schemas']);
        self::assertSame(2, $internal->resourceCount);
    }

    /**
     * Test that the parameter and error example counts reflect the components.
     *
     * @return void
     */
    public function testSummaryParameterAndErrorExampleCountsReflectComponents(): void
    {
        $this->router()->get('users/{user}', [PathResponseSchemaController::class, 'single']);

        $result = $this->export();

        self::assertIsArray($result->document['components']['parameters']);
        self::assertSame(
            count($result->document['components']['parameters']),
            $result->parameterCount,
        );
        self::assertIsArray($result->document['components']['examples']);
        self::assertSame(['Error10001', 'Error10103'], array_keys($result->document['components']['examples']));
        self::assertSame(2, $result->errorExampleCount);
    }

    /**
     * Test that the operation count sums every verb across every path.
     *
     * @return void
     */
    public function testSummaryOperationCountSumsAcrossPathsAndVerbs(): void
    {
        $router = $this->router();
        $router->get('widget', [PathResponseSchemaController::class, 'widget']);
        $router->match(['PUT', 'PATCH'], 'widgets/{widget}', [PathResponseSchemaController::class, 'widget']);

        self::assertSame(3, $this->export()->operationCount);
    }

    /**
     * Test that the operation count is zero when no route is documented.
     *
     * @return void
     */
    public function testSummaryOperationCountIsZeroWithoutRoutes(): void
    {
        self::assertSame(0, $this->export()->operationCount);
    }

    /**
     * Provide malformed paths documents with the operation count expected.
     *
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function malformedPathsProvider(): iterable
    {
        yield 'paths is absent' => [[], 0];
        yield 'paths is an empty object' => [['paths' => (object) []], 0];
        yield 'path item is not an array' => [['paths' => ['/a' => 'nope', '/b' => ['get' => [], 'post' => []]]], 2];
    }

    /**
     * Test that a non-array paths object or path item counts as nothing.
     *
     * @param  array<string, mixed>  $document
     * @param  int  $expected
     * @return void
     */
    #[DataProvider('malformedPathsProvider')]
    public function testCountOperationsToleratesMalformedPaths(array $document, int $expected): void
    {
        $useCase = (new \ReflectionClass(ExportOpenApiComponents::class))->newInstanceWithoutConstructor();

        self::assertSame($expected, $this->invokeMethod($useCase, 'countOperations', $document));
    }

    /**
     * Provide documents with an absent or malformed components section.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedComponentsProvider(): iterable
    {
        yield 'components is not an array' => [['components' => 'nope']];
        yield 'section is absent' => [['components' => []]];
        yield 'section is not an array' => [['components' => ['parameters' => 'nope']]];
    }

    /**
     * Test that a missing or non-array components section counts as zero.
     *
     * @param  array<string, mixed>  $document
     * @return void
     */
    #[DataProvider('malformedComponentsProvider')]
    public function testCountComponentsToleratesMalformedSections(array $document): void
    {
        $useCase = (new \ReflectionClass(ExportOpenApiComponents::class))->newInstanceWithoutConstructor();

        self::assertSame(0, $this->invokeMethod($useCase, 'countComponents', $document, 'parameters'));
    }

    /**
     * Declare a public and an internal audience with their routes.
     *
     * @return void
     */
    private function registerPublicAndInternalAudiences(): void
    {
        assert($this->app !== null);

        $this->app->make(ConfigRepository::class)->set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => [],
        ]);

        $this->router()->get('organizations', [PathOrganizationController::class, 'index']);
        $this->router()->get('tags', [InternalOnlyTagController::class, 'index']);
    }

    /**
     * Resolve the application router.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        assert($this->app !== null);

        return $this->app->make(Router::class);
    }

    /**
     * Run the use case for the audience against real builders.
     *
     * @param  string|null  $audience
     * @param  array<class-string, class-string>  $resourceMap
     * @return \SineMacula\ApiToolkit\OpenApi\ExportResult
     */
    private function export(?string $audience = null, array $resourceMap = self::RESOURCE_MAP): ExportResult
    {
        $catalogue = $this->makeCatalogue($resourceMap);

        assert($this->app !== null);

        $introspector   = $this->app->make(SchemaIntrospector::class);
        $router         = $this->router();
        $enums          = new EnumSchemaRegistry;
        $definedSchemas = new DefinedSchemaRegistry;

        $errors = new ErrorResponseBuilder(
            new RouteMiddlewareResolver($router),
            new ThrowsErrorResolver(new ClassNameResolver),
            new ResponseHeaderResolver,
            new ErrorExampleBuilder($catalogue),
            ExceptionMap::fromConfig(),
        );

        $assembler = new OpenApiAssembler(
            new ResourceSchemaBuilder($catalogue, new FieldTypeResolver($introspector, new ColumnTypeMapper, $enums), $introspector),
            new QueryParameterBuilder($catalogue),
            $errors,
            new PathBuilder(
                $router,
                $catalogue,
                new AudienceResolver,
                new DocumentableRouteFilter,
                new EnvelopeBuilder,
                new TagResolver,
                new RequestBodyResolver(new RulesToSchemaTranslator(new RuleNormaliser, new FieldSchemaBuilder($enums))),
                new SecuritySchemeResolver(new SecuritySchemeMapper, new RouteMiddlewareResolver($router)),
                new QueryParameterBuilder($catalogue),
                new PathParameterBuilder(
                    $router,
                    new RoutePatternTranslator,
                    new RouteKeySchemaResolver($catalogue, new FieldTypeResolver($introspector, new ColumnTypeMapper, $enums), new RoutePatternTranslator, $enums, $this->app),
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
            new SecuritySchemeResolver(new SecuritySchemeMapper, new RouteMiddlewareResolver($router)),
            new EnumSchemaBuilder,
            $enums,
            new DocManualAssembler,
            $definedSchemas,
            new DefinedSchemaBuilder,
            new ErrorExampleBuilder($catalogue),
        );

        return (new ExportOpenApiComponents($assembler, $catalogue))->export($audience);
    }

    /**
     * Build a stubbed catalogue exposing the resource map and the error
     * catalogue.
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
}
