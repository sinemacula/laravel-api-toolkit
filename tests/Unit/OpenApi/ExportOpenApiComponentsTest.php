<?php

namespace Tests\Unit\OpenApi;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\Concerns\SchemaCompiler;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\ExportResult;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;
use SineMacula\ApiToolkit\OpenApi\OpenApiAssembler;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver;
use SineMacula\ApiToolkit\Services\SchemaIntrospector;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\User;
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
class ExportOpenApiComponentsTest extends TestCase
{
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

        static::assertStringStartsWith('3.1', $result->document['openapi']);
        static::assertArrayHasKey('components', $result->document);
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

        static::assertSame(2, $result->resourceCount);
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

        static::assertSame(
            count($result->document['components']['parameters']),
            $result->parameterCount,
        );
        static::assertSame(
            count($result->document['components']['responses']),
            $result->responseCount,
        );
        static::assertSame(1, $result->responseCount);
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

        $assembler = new OpenApiAssembler(
            new ResourceSchemaBuilder($catalogue, new FieldTypeResolver(new SchemaIntrospector, new ColumnTypeMapper)),
            new QueryParameterBuilder($catalogue),
            new ErrorResponseBuilder($catalogue),
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
        $catalogue = static::createStub(MetadataCatalogue::class);
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
