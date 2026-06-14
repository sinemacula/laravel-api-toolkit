<?php

namespace Tests\Unit\OpenApi;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
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
 * Tests for the OpenApiAssembler.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OpenApiAssembler::class)]
class OpenApiAssemblerTest extends TestCase
{
    /**
     * Test that the assembled document declares a 3.1.x OpenAPI version.
     *
     * @return void
     */
    public function testDocumentDeclaresOpenApiThreeOne(): void
    {
        $document = $this->assemble();

        static::assertArrayHasKey('openapi', $document);
        static::assertStringStartsWith('3.1', $document['openapi']);
    }

    /**
     * Test that the document carries a minimal info block.
     *
     * @return void
     */
    public function testDocumentCarriesAMinimalInfoBlock(): void
    {
        $document = $this->assemble();

        static::assertArrayHasKey('title', $document['info']);
        static::assertArrayHasKey('version', $document['info']);
    }

    /**
     * Test that the document declares no path operations, so the package never
     * crosses the components/paths seam.
     *
     * @return void
     */
    public function testDocumentDeclaresNoPaths(): void
    {
        $document = $this->assemble();

        static::assertArrayHasKey('paths', $document);
        static::assertSame([], (array) $document['paths']);
    }

    /**
     * Test that the paths value serialises to an empty JSON object rather than
     * an array, keeping the document schema-valid.
     *
     * @return void
     */
    public function testPathsSerialiseToAnEmptyObject(): void
    {
        $document = $this->assemble();

        static::assertSame('{}', json_encode($document['paths']));
    }

    /**
     * Test that the components block is populated with schemas, parameters, and
     * responses.
     *
     * @return void
     */
    public function testComponentsBlockIsPopulated(): void
    {
        $components = $this->assemble()['components'];

        static::assertNotEmpty($components['schemas']);
        static::assertNotEmpty($components['parameters']);
        static::assertNotEmpty($components['responses']);
    }

    /**
     * Test that the resource schemas appear under components.schemas alongside
     * the shared error-envelope schema.
     *
     * @return void
     */
    public function testResourceAndEnvelopeSchemasArePresent(): void
    {
        $schemas = $this->assemble()['components']['schemas'];

        static::assertArrayHasKey('User', $schemas);
        static::assertArrayHasKey('Organization', $schemas);
        static::assertArrayHasKey(ErrorResponseBuilder::ENVELOPE_SCHEMA_NAME, $schemas);
    }

    /**
     * Test that the shared query-parameter vocabulary is emitted once under
     * components.parameters.
     *
     * @return void
     */
    public function testSharedParametersAreEmittedOnce(): void
    {
        $parameters = $this->assemble()['components']['parameters'];

        static::assertArrayHasKey('Filter', $parameters);
        static::assertArrayHasKey('Fields', $parameters);
        static::assertArrayHasKey('Order', $parameters);
    }

    /**
     * Test that each error code yields a reusable response under
     * components.responses.
     *
     * @return void
     */
    public function testErrorResponsesArePresent(): void
    {
        $responses = $this->assemble()['components']['responses'];

        static::assertArrayHasKey('ErrorResponse10103', $responses);
    }

    /**
     * Test that the assembled document serialises to valid JSON, proving it is
     * a plain associative array ready for the output port.
     *
     * @return void
     */
    public function testDocumentSerialisesToJson(): void
    {
        $json = json_encode($this->assemble(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        static::assertIsString($json);
        static::assertStringContainsString('"openapi"', $json);
    }

    /**
     * Assemble a document from real builders backed by a stubbed catalogue and
     * a real resolver against the live test schema.
     *
     * @return array<string, mixed>
     */
    private function assemble(): array
    {
        return $this->makeAssembler()->assemble();
    }

    /**
     * Build an assembler from the three real builders.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\OpenApiAssembler
     */
    private function makeAssembler(): OpenApiAssembler
    {
        $catalogue = $this->makeCatalogue();

        return new OpenApiAssembler(
            new ResourceSchemaBuilder($catalogue, $this->resolver()),
            new QueryParameterBuilder($catalogue),
            new ErrorResponseBuilder($catalogue),
        );
    }

    /**
     * Build a stubbed catalogue exposing a resource map, the operator
     * vocabulary, and a single error descriptor.
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

    /**
     * Build a real field-type resolver against the container-bound introspector.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver
     */
    private function resolver(): FieldTypeResolver
    {
        return new FieldTypeResolver(new SchemaIntrospector, new ColumnTypeMapper);
    }
}
