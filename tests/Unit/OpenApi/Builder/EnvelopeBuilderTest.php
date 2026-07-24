<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use Tests\TestCase;

/**
 * Tests for the EnvelopeBuilder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(EnvelopeBuilder::class)]
final class EnvelopeBuilderTest extends TestCase
{
    /**
     * Test that the length-aware pagination meta schema carries the exact
     * total/count/continue shape with all keys required.
     *
     * @return void
     */
    public function testPaginationMetaShape(): void
    {
        $schema = (new EnvelopeBuilder)->buildSchemas()[EnvelopeBuilder::PAGINATION_META_SCHEMA_NAME];

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'total'    => ['type' => 'integer'],
                'count'    => ['type' => 'integer'],
                'continue' => ['type' => 'boolean'],
            ],
            'required' => ['total', 'count', 'continue'],
        ], $schema);
    }

    /**
     * Test that the length-aware pagination links schema carries uri-formatted
     * self/first/last and nullable prev/next links, all required.
     *
     * @return void
     */
    public function testPaginationLinksShape(): void
    {
        $schema = (new EnvelopeBuilder)->buildSchemas()[EnvelopeBuilder::PAGINATION_LINKS_SCHEMA_NAME];

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'self'  => ['type' => 'string', 'format' => 'uri'],
                'first' => ['type' => 'string', 'format' => 'uri'],
                'prev'  => $this->nullableUri(),
                'next'  => $this->nullableUri(),
                'last'  => ['type' => 'string', 'format' => 'uri'],
            ],
            'required' => ['self', 'first', 'prev', 'next', 'last'],
        ], $schema);
    }

    /**
     * Test that the cursor pagination meta schema carries only the required
     * continue flag.
     *
     * @return void
     */
    public function testCursorPaginationMetaShape(): void
    {
        $schema = (new EnvelopeBuilder)->buildSchemas()[EnvelopeBuilder::CURSOR_PAGINATION_META_SCHEMA_NAME];

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'continue' => ['type' => 'boolean'],
            ],
            'required' => ['continue'],
        ], $schema);
    }

    /**
     * Test that the cursor pagination links schema carries a uri-formatted self
     * link and nullable prev/next links, all required.
     *
     * @return void
     */
    public function testCursorPaginationLinksShape(): void
    {
        $schema = (new EnvelopeBuilder)->buildSchemas()[EnvelopeBuilder::CURSOR_PAGINATION_LINKS_SCHEMA_NAME];

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'self' => ['type' => 'string', 'format' => 'uri'],
                'prev' => $this->nullableUri(),
                'next' => $this->nullableUri(),
            ],
            'required' => ['self', 'prev', 'next'],
        ], $schema);
    }

    /**
     * Test that nullable links use the 2020-12 `{"type": "null"}` union member
     * rather than the OpenAPI 3.0 `nullable` keyword.
     *
     * @return void
     */
    public function testNullableLinksUseTheNullUnionMember(): void
    {
        $prev = (new EnvelopeBuilder)->buildSchemas()[EnvelopeBuilder::PAGINATION_LINKS_SCHEMA_NAME]['properties']['prev'];

        self::assertSame(['type' => 'string', 'format' => 'uri'], $prev['oneOf'][0]);
        self::assertSame(['type' => 'null'], $prev['oneOf'][1]);
        self::assertArrayNotHasKey('nullable', $prev);
    }

    /**
     * Test that the reusable total-count header carries a non-negative integer
     * schema and a description.
     *
     * @return void
     */
    public function testTotalCountHeaderShape(): void
    {
        $header = (new EnvelopeBuilder)->buildHeaders()[EnvelopeBuilder::TOTAL_COUNT_HEADER_NAME];

        self::assertArrayHasKey('description', $header);
        self::assertSame(['type' => 'integer', 'minimum' => 0], $header['schema']);
    }

    /**
     * Test that the single envelope wraps the given reference in a required
     * data property.
     *
     * @return void
     */
    public function testSingleEnvelopeWrapsTheReference(): void
    {
        $envelope = (new EnvelopeBuilder)->singleEnvelope('#/components/schemas/User');

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'data' => ['$ref' => '#/components/schemas/User'],
            ],
            'required' => ['data'],
        ], $envelope);
    }

    /**
     * Test that the collection envelope wraps an array of the given reference
     * alongside the length-aware pagination meta and links references.
     *
     * @return void
     */
    public function testCollectionEnvelopeWrapsAnArrayWithPaginationComponents(): void
    {
        $envelope = (new EnvelopeBuilder)->collectionEnvelope('#/components/schemas/User');

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'data'  => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/User']],
                'meta'  => ['$ref' => '#/components/schemas/PaginationMeta'],
                'links' => ['$ref' => '#/components/schemas/PaginationLinks'],
            ],
            'required' => ['data', 'meta', 'links'],
        ], $envelope);
    }

    /**
     * Test that the cursor collection envelope wraps an array of the given
     * reference alongside the cursor pagination meta and links references.
     *
     * @return void
     */
    public function testCursorCollectionEnvelopeReferencesCursorComponents(): void
    {
        $envelope = (new EnvelopeBuilder)->cursorCollectionEnvelope('#/components/schemas/User');

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'data'  => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/User']],
                'meta'  => ['$ref' => '#/components/schemas/CursorPaginationMeta'],
                'links' => ['$ref' => '#/components/schemas/CursorPaginationLinks'],
            ],
            'required' => ['data', 'meta', 'links'],
        ], $envelope);
    }

    /**
     * The expected nullable uri-formatted string schema fragment.
     *
     * @return array<string, mixed>
     */
    private function nullableUri(): array
    {
        return [
            'oneOf' => [
                ['type' => 'string', 'format' => 'uri'],
                ['type' => 'null'],
            ],
        ];
    }
}
