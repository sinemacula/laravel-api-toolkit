<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi;

use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\OpenApi\Builder\EnumSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\PathBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Docs\DocManualAssembler;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\ExportResult;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;
use SineMacula\ApiToolkit\OpenApi\OpenApiAssembler;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentableRouteFilter;
use SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RequestBodyResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseHeaderResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseSchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\TagResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ThrowsErrorResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use SineMacula\ApiToolkit\OpenApi\Schema\FieldSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser;
use SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeMapper;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeResolver;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\PathResponseSchemaController;
use Tests\Fixtures\Resources\OrganizationResource;
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

        self::assertStringStartsWith('3.1', $result->document['openapi']);
        self::assertArrayHasKey('components', $result->document);
    }

    /**
     * Test that the summary resource count equals the registered resource
     * count.
     *
     * @return void
     */
    public function testSummaryResourceCountMatchesTheRegistry(): void
    {
        $result = $this->export();

        self::assertSame(2, $result->resourceCount);
    }

    /**
     * Test that the summary parameter and response counts reflect the assembled
     * components.
     *
     * @return void
     */
    public function testSummaryParameterAndResponseCountsReflectComponents(): void
    {
        $result = $this->export();

        self::assertSame(
            count($result->document['components']['parameters']),
            $result->parameterCount,
        );
        self::assertSame(
            count($result->document['components']['responses']),
            $result->responseCount,
        );
        self::assertSame(1, $result->responseCount);
    }

    /**
     * Test that the summary operation count sums every verb across every path,
     * so a route matching two verbs counts twice.
     *
     * @return void
     */
    public function testSummaryOperationCountSumsAcrossPathsAndVerbs(): void
    {
        assert($this->app !== null);

        $router = $this->app->make(Router::class);
        $router->get('widget', [PathResponseSchemaController::class, 'widget']);
        $router->match(['PUT', 'PATCH'], 'widgets/{widget}', [PathResponseSchemaController::class, 'widget']);

        self::assertSame(3, $this->export()->operationCount);
    }

    /**
     * Test that the summary operation count is zero when no route is
     * documented.
     *
     * @return void
     */
    public function testSummaryOperationCountIsZeroWithoutRoutes(): void
    {
        self::assertSame(0, $this->export()->operationCount);
    }

    /**
     * Provide documents whose paths object is absent or not an array, or holds
     * a path item that is not an array, with the operation count expected.
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
     * Test that a paths object or path item that is not an array counts as no
     * operations rather than raising a type error.
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
     * Provide documents whose components section is absent, non-array, or holds
     * a non-array section value.
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
     * Test that a components section that is missing or not an array counts as
     * zero rather than raising a type error.
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
     * Run the use case against real builders, a real resolver, and a stubbed
     * catalogue.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\ExportResult
     */
    private function export(): ExportResult
    {
        $catalogue = $this->makeCatalogue();

        assert($this->app !== null);

        $introspector = $this->app->make(SchemaIntrospector::class);
        $router       = $this->app->make(Router::class);
        $enums        = new EnumSchemaRegistry;

        $assembler = new OpenApiAssembler(
            new ResourceSchemaBuilder($catalogue, new FieldTypeResolver($introspector, new ColumnTypeMapper, $enums), $introspector),
            new QueryParameterBuilder($catalogue),
            new ErrorResponseBuilder($catalogue),
            new PathBuilder(
                $router,
                $catalogue,
                new AudienceResolver,
                new DocumentableRouteFilter,
                new EnvelopeBuilder,
                new TagResolver,
                new ResponseSchemaResolver($catalogue, new EnvelopeBuilder),
                new RequestBodyResolver(new RulesToSchemaTranslator(new RuleNormaliser, new FieldSchemaBuilder($enums))),
                new SecuritySchemeResolver(new SecuritySchemeMapper, new RouteMiddlewareResolver($router)),
                new QueryParameterBuilder($catalogue),
                new RouteMiddlewareResolver($router),
                new ThrowsErrorResolver(new ResponseHeaderResolver),
                new ResponseHeaderResolver,
            ),
            new SecuritySchemeResolver(new SecuritySchemeMapper, new RouteMiddlewareResolver($router)),
            new EnumSchemaBuilder,
            $enums,
            new DocManualAssembler,
        );

        return (new ExportOpenApiComponents($assembler, $catalogue))->export();
    }

    /**
     * Build a stubbed catalogue exposing two resources and a single error
     * descriptor.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue
     */
    private function makeCatalogue(): MetadataCatalogue
    {
        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn([
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
        ]);
        $catalogue->method('getOperatorTokens')->willReturn(['$eq', '$neq']);
        $catalogue->method('getStructuralOperators')->willReturn(['$and', '$or']);
        $catalogue->method('getErrorCatalogue')->willReturn([
            new ErrorDescriptor(code: 10103, httpStatus: 404, title: 'Not Found', detail: 'Missing.'),
        ]);

        return $catalogue;
    }
}
