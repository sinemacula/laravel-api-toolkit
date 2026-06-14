<?php

namespace Tests\Unit\OpenApi\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;

/**
 * Tests for the ErrorResponseBuilder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ErrorResponseBuilder::class)]
class ErrorResponseBuilderTest extends TestCase
{
    /**
     * Test that exactly one response component is emitted per error descriptor.
     *
     * @return void
     */
    public function testEmitsOneResponsePerErrorCode(): void
    {
        $responses = $this->makeBuilder([
            new ErrorDescriptor(code: 10103, httpStatus: 404, title: 'Not Found', detail: 'The resource was not found.'),
            new ErrorDescriptor(code: 10102, httpStatus: 403, title: 'Forbidden', detail: 'Access is denied.'),
        ])->build();

        static::assertCount(2, $responses);
        static::assertArrayHasKey('ErrorResponse10103', $responses);
        static::assertArrayHasKey('ErrorResponse10102', $responses);
    }

    /**
     * Test that each response carries the code's HTTP status, so the documented
     * status agrees with the error catalogue.
     *
     * @return void
     */
    public function testResponseCarriesTheCodesHttpStatus(): void
    {
        $responses = $this->makeBuilder([
            new ErrorDescriptor(code: 10103, httpStatus: 404, title: 'Not Found', detail: 'The resource was not found.'),
        ])->build();

        static::assertSame(404, $responses['ErrorResponse10103']['x-status']);
        static::assertSame(10103, $responses['ErrorResponse10103']['x-code']);
    }

    /**
     * Test that each response references the shared error-envelope schema rather
     * than inlining the envelope shape.
     *
     * @return void
     */
    public function testResponseReferencesTheSharedEnvelopeSchema(): void
    {
        $responses = $this->makeBuilder([
            new ErrorDescriptor(code: 10103, httpStatus: 404, title: 'Not Found', detail: 'Missing.'),
        ])->build();

        $schema = $responses['ErrorResponse10103']['content']['application/json']['schema'];

        static::assertSame(['$ref' => '#/components/schemas/ErrorEnvelope'], $schema);
    }

    /**
     * Test that the response description is sourced from the descriptor title,
     * falling back to the detail when no title is defined.
     *
     * @return void
     */
    public function testResponseDescriptionFallsBackToDetailWhenTitleAbsent(): void
    {
        $responses = $this->makeBuilder([
            new ErrorDescriptor(code: 10113, httpStatus: 500, title: null, detail: 'A generic error occurred.'),
        ])->build();

        static::assertSame('A generic error occurred.', $responses['ErrorResponse10113']['description']);
    }

    /**
     * Test that the response carries an example payload mirroring the runtime
     * error envelope shape.
     *
     * @return void
     */
    public function testResponseCarriesAnEnvelopeShapedExample(): void
    {
        $responses = $this->makeBuilder([
            new ErrorDescriptor(code: 10103, httpStatus: 404, title: 'Not Found', detail: 'Missing.'),
        ])->build();

        $example = $responses['ErrorResponse10103']['content']['application/json']['example'];

        static::assertSame(404, $example['error']['status']);
        static::assertSame(10103, $example['error']['code']);
        static::assertSame('Not Found', $example['error']['title']);
        static::assertSame('Missing.', $example['error']['detail']);
    }

    /**
     * Test that a null title is omitted from the example envelope.
     *
     * @return void
     */
    public function testExampleOmitsNullTitle(): void
    {
        $responses = $this->makeBuilder([
            new ErrorDescriptor(code: 10113, httpStatus: 500, title: null, detail: 'Generic.'),
        ])->build();

        $example = $responses['ErrorResponse10113']['content']['application/json']['example'];

        static::assertArrayNotHasKey('title', $example['error']);
    }

    /**
     * Test that the shared envelope schema describes the toolkit's error
     * payload, with the error object's required keys.
     *
     * @return void
     */
    public function testEnvelopeSchemaDescribesTheErrorPayload(): void
    {
        $envelope = $this->makeBuilder([])->buildEnvelopeSchema();

        static::assertSame('object', $envelope['type']);
        static::assertContains('error', $envelope['required']);

        $error = $envelope['properties']['error'];

        static::assertSame('object', $error['type']);
        static::assertSame(['type' => 'integer'], $error['properties']['status']);
        static::assertSame(['type' => 'integer'], $error['properties']['code']);
        static::assertSame(['type' => 'string'], $error['properties']['title']);
        static::assertSame(['type' => 'string'], $error['properties']['detail']);
        static::assertSame(['type' => 'object', 'additionalProperties' => true], $error['properties']['meta']);
        static::assertSame(['status', 'code', 'detail'], $error['required']);
    }

    /**
     * Test that the envelope schema component name is the conventional name
     * referenced by every response.
     *
     * @return void
     */
    public function testEnvelopeSchemaNameIsErrorEnvelope(): void
    {
        static::assertSame('ErrorEnvelope', ErrorResponseBuilder::ENVELOPE_SCHEMA_NAME);
    }

    /**
     * Build an ErrorResponseBuilder backed by a stub returning the given
     * descriptors.
     *
     * @param  array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>  $descriptors
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\ErrorResponseBuilder
     */
    private function makeBuilder(array $descriptors): ErrorResponseBuilder
    {
        $catalogue = static::createStub(MetadataCatalogue::class);
        $catalogue->method('getErrorCatalogue')->willReturn($descriptors);

        return new ErrorResponseBuilder($catalogue);
    }
}
