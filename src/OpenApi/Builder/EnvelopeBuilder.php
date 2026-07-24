<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

/**
 * Builds the shared response-envelope components once.
 *
 * Mirrors the toolkit's runtime envelope contract as reusable components: the
 * length-aware and cursor pagination `meta`/`links` schemas and the
 * `Total-Count` response header emitted on length-aware collections. Nullable
 * link fields use the JSON Schema 2020-12 `{"type": "null"}` union member
 * rather than the OpenAPI 3.0 `nullable` keyword, and url/link strings carry
 * the `uri` format. The wrapper helpers assemble single and collection
 * envelopes around a resource reference for path operations to consume; the
 * shared components are never duplicated per endpoint.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class EnvelopeBuilder
{
    /** The component name of the length-aware pagination meta schema */
    public const string PAGINATION_META_SCHEMA_NAME = 'PaginationMeta';

    /** The component name of the length-aware pagination links schema */
    public const string PAGINATION_LINKS_SCHEMA_NAME = 'PaginationLinks';

    /** The component name of the cursor pagination meta schema */
    public const string CURSOR_PAGINATION_META_SCHEMA_NAME = 'CursorPaginationMeta';

    /** The component name of the cursor pagination links schema */
    public const string CURSOR_PAGINATION_LINKS_SCHEMA_NAME = 'CursorPaginationLinks';

    /** The component name of the reusable total-count response header */
    public const string TOTAL_COUNT_HEADER_NAME = 'Total-Count';

    /** The path prefix under which schema components are referenced */
    private const string SCHEMA_REF_PREFIX = '#/components/schemas/';

    /**
     * Build the shared envelope schemas, keyed by component name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function buildSchemas(): array
    {
        return [
            self::PAGINATION_META_SCHEMA_NAME         => $this->buildPaginationMeta(),
            self::PAGINATION_LINKS_SCHEMA_NAME        => $this->buildPaginationLinks(),
            self::CURSOR_PAGINATION_META_SCHEMA_NAME  => $this->buildCursorPaginationMeta(),
            self::CURSOR_PAGINATION_LINKS_SCHEMA_NAME => $this->buildCursorPaginationLinks(),
        ];
    }

    /**
     * Build the shared response headers, keyed by component name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function buildHeaders(): array
    {
        return [
            self::TOTAL_COUNT_HEADER_NAME => $this->buildTotalCountHeader(),
        ];
    }

    /**
     * Build the single-resource envelope wrapping the given schema reference in
     * the inherited `data` wrapper.
     *
     * @param  string  $schemaRef
     * @return array<string, mixed>
     */
    public function singleEnvelope(string $schemaRef): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'data' => ['$ref' => $schemaRef],
            ],
            'required' => ['data'],
        ];
    }

    /**
     * Build the length-aware collection envelope wrapping an array of the given
     * schema reference alongside the pagination meta and links components.
     *
     * @param  string  $schemaRef
     * @return array<string, mixed>
     */
    public function collectionEnvelope(string $schemaRef): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'data'  => ['type' => 'array', 'items' => ['$ref' => $schemaRef]],
                'meta'  => ['$ref' => self::SCHEMA_REF_PREFIX . self::PAGINATION_META_SCHEMA_NAME],
                'links' => ['$ref' => self::SCHEMA_REF_PREFIX . self::PAGINATION_LINKS_SCHEMA_NAME],
            ],
            'required' => ['data', 'meta', 'links'],
        ];
    }

    /**
     * Build the cursor collection envelope wrapping an array of the given
     * schema reference alongside the cursor pagination meta and links
     * components.
     *
     * @param  string  $schemaRef
     * @return array<string, mixed>
     */
    public function cursorCollectionEnvelope(string $schemaRef): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'data'  => ['type' => 'array', 'items' => ['$ref' => $schemaRef]],
                'meta'  => ['$ref' => self::SCHEMA_REF_PREFIX . self::CURSOR_PAGINATION_META_SCHEMA_NAME],
                'links' => ['$ref' => self::SCHEMA_REF_PREFIX . self::CURSOR_PAGINATION_LINKS_SCHEMA_NAME],
            ],
            'required' => ['data', 'meta', 'links'],
        ];
    }

    /**
     * Build the length-aware pagination meta schema.
     *
     * @return array<string, mixed>
     */
    private function buildPaginationMeta(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'total'    => ['type' => 'integer'],
                'count'    => ['type' => 'integer'],
                'continue' => ['type' => 'boolean'],
            ],
            'required' => ['total', 'count', 'continue'],
        ];
    }

    /**
     * Build the length-aware pagination links schema.
     *
     * @return array<string, mixed>
     */
    private function buildPaginationLinks(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'self'  => $this->uri(),
                'first' => $this->uri(),
                'prev'  => $this->nullableUri(),
                'next'  => $this->nullableUri(),
                'last'  => $this->uri(),
            ],
            'required' => ['self', 'first', 'prev', 'next', 'last'],
        ];
    }

    /**
     * Build the cursor pagination meta schema.
     *
     * @return array<string, mixed>
     */
    private function buildCursorPaginationMeta(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'continue' => ['type' => 'boolean'],
            ],
            'required' => ['continue'],
        ];
    }

    /**
     * Build the cursor pagination links schema.
     *
     * @return array<string, mixed>
     */
    private function buildCursorPaginationLinks(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'self' => $this->uri(),
                'prev' => $this->nullableUri(),
                'next' => $this->nullableUri(),
            ],
            'required' => ['self', 'prev', 'next'],
        ];
    }

    /**
     * Build the reusable total-count response header emitted on length-aware
     * collection responses.
     *
     * @return array<string, mixed>
     */
    private function buildTotalCountHeader(): array
    {
        return [
            'description' => 'The total number of records across all pages of the length-aware collection.',
            'schema'      => ['type' => 'integer', 'minimum' => 0],
        ];
    }

    /**
     * Build a uri-formatted string schema.
     *
     * @return array<string, mixed>
     */
    private function uri(): array
    {
        return ['type' => 'string', 'format' => 'uri'];
    }

    /**
     * Build a nullable uri-formatted string schema.
     *
     * Nullability is expressed with a JSON Schema 2020-12 `{"type": "null"}`
     * union member rather than the OpenAPI 3.0 `nullable` keyword, which is an
     * inert unknown keyword under 3.1 / JSON Schema 2020-12.
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
