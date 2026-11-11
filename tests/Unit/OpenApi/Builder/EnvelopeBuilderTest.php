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
     * Test the length-aware pagination meta schema shape.
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
     * Test the length-aware pagination links schema shape.
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
     * Test that the cursor pagination meta carries only the continue flag.
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
     * Test the cursor pagination links schema shape.
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
     * Test that nullable links use a 2020-12 null type union member.
     *
     * @return void
     */
    public function testNullableLinksUseTheNullUnionMember(): void
    {
        $prev = (new EnvelopeBuilder)->buildSchemas()[EnvelopeBuilder::PAGINATION_LINKS_SCHEMA_NAME]['properties']['prev'];

        self::assertSame(['type' => 'string', 'format' => 'uri'], $prev['oneOf'][0]);
        self::assertSame(['type' => 'null'], $prev['oneOf'][1]);
        self::assertIsArray($prev);
        self::assertArrayNotHasKey('nullable', $prev);
    }

    /**
     * Test that the total-count header carries a non-negative integer schema.
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
     * Test that the single envelope wraps the reference in a data property.
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
     * Test that the undocumented envelope marks its body as x-undocumented.
     *
     * @return void
     */
    public function testUndocumentedEnvelopeWrapsAnUndocumentedBody(): void
    {
        $envelope = (new EnvelopeBuilder)->undocumentedEnvelope();

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'data' => ['x-undocumented' => true],
            ],
            'required' => ['data'],
        ], $envelope);
    }

    /**
     * Test that the collection envelope carries length-aware meta and links.
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
     * Test that the cursor collection envelope carries cursor meta and links.
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
