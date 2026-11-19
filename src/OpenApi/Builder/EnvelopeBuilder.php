<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

/**
 * Builds the shared response-envelope components once.
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

    /** The component name of the reusable entity-tag response header */
    public const string ETAG_HEADER_NAME = 'ETag';

    /** The path prefix under which schema components are referenced */
    private const string SCHEMA_REF_PREFIX = '#/components/schemas/';

    /** The page link the pagination component examples show */
    private const string EXAMPLE_PAGE = 'https://example.com/resources?page=1';

    /** The cursor link the pagination component examples show */
    private const string EXAMPLE_CURSOR_PAGE = 'https://example.com/resources';

    /** The path prefix under which header components are referenced */
    private const string HEADER_REF_PREFIX = '#/components/headers/';

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
            self::ETAG_HEADER_NAME        => $this->buildEtagHeader(),
        ];
    }

    /**
     * Build the headers of a response that may be a length-aware page.
     *
     * @return array<string, array{'$ref': string}>
     */
    public function collectionHeaders(): array
    {
        return [self::TOTAL_COUNT_HEADER_NAME => ['$ref' => self::HEADER_REF_PREFIX . self::TOTAL_COUNT_HEADER_NAME]];
    }

    /**
     * Build the single-resource envelope wrapping the given schema reference.
     *
     * @param  string  $schemaRef
     * @return array<string, mixed>
     */
    public function singleEnvelope(string $schemaRef): array
    {
        return $this->singleEnvelopeFor(['$ref' => $schemaRef]);
    }

    /**
     * Build the single-resource envelope wrapping the given data schema node.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function singleEnvelopeFor(array $data): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'data' => $data,
            ],
            'required' => ['data'],
        ];
    }

    /**
     * Build the single envelope wrapping an opaque, undocumented body.
     *
     * @return array<string, mixed>
     */
    public function undocumentedEnvelope(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'data' => ['x-undocumented' => true],
            ],
            'required' => ['data'],
        ];
    }

    /**
     * Build the length-aware collection envelope for the given schema
     * reference.
     *
     * @param  string  $schemaRef
     * @return array<string, mixed>
     */
    public function collectionEnvelope(string $schemaRef): array
    {
        return $this->collectionEnvelopeFor(['$ref' => $schemaRef]);
    }

    /**
     * Build the cursor collection envelope for the given schema reference.
     *
     * @param  string  $schemaRef
     * @return array<string, mixed>
     */
    public function cursorCollectionEnvelope(string $schemaRef): array
    {
        return $this->cursorCollectionEnvelopeFor(['$ref' => $schemaRef]);
    }

    /**
     * Build the envelope of a collection of the given item schema node that is
     * a length-aware page, a cursor page or an unpaginated list.
     *
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    public function anyCollectionEnvelopeFor(array $items): array
    {
        return [
            'anyOf' => [
                $this->collectionEnvelopeFor($items),
                $this->cursorCollectionEnvelopeFor($items),
                [
                    'title'      => 'Unpaginated list',
                    'type'       => 'object',
                    'properties' => [
                        'data' => ['type' => 'array', 'items' => $items],
                    ],
                    'required' => ['data'],
                ],
            ],
        ];
    }

    /**
     * Build the length-aware collection envelope for the given item schema
     * node.
     *
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    private function collectionEnvelopeFor(array $items): array
    {
        return [
            'title'      => 'Length-aware page',
            'type'       => 'object',
            'properties' => [
                'data'  => ['type' => 'array', 'items' => $items],
                'meta'  => ['$ref' => self::SCHEMA_REF_PREFIX . self::PAGINATION_META_SCHEMA_NAME],
                'links' => ['$ref' => self::SCHEMA_REF_PREFIX . self::PAGINATION_LINKS_SCHEMA_NAME],
            ],
            'required' => ['data', 'meta', 'links'],
        ];
    }

    /**
     * Build the cursor collection envelope for the given item schema node.
     *
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    private function cursorCollectionEnvelopeFor(array $items): array
    {
        return [
            'title'      => 'Cursor page',
            'type'       => 'object',
            'properties' => [
                'data'  => ['type' => 'array', 'items' => $items],
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
                'total' => [
                    'type'        => 'integer',
                    'description' => 'The records the query matches, or only those offset pagination can reach when the total is capped.',
                ],
                'total_capped' => [
                    'type'        => 'boolean',
                    'description' => 'Whether more records match than the total reports; read past it with cursor pagination.',
                ],
                'count'    => ['type' => 'integer'],
                'continue' => ['type' => 'boolean'],
            ],
            'required' => ['total', 'total_capped', 'count', 'continue'],
            'examples' => [['total' => 1, 'total_capped' => false, 'count' => 1, 'continue' => false]],
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
            'examples' => [['self' => self::EXAMPLE_PAGE, 'first' => self::EXAMPLE_PAGE, 'prev' => null, 'next' => null, 'last' => self::EXAMPLE_PAGE]],
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
            'examples' => [['continue' => false]],
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
            'examples' => [['self' => self::EXAMPLE_CURSOR_PAGE, 'prev' => null, 'next' => null]],
        ];
    }

    /**
     * Build the total-count header emitted on length-aware collections.
     *
     * @return array<string, mixed>
     */
    private function buildTotalCountHeader(): array
    {
        return [
            'description' => 'The total number of records across all pages of the length-aware collection, absent when the total is capped.',
            'schema'      => ['type' => 'integer', 'minimum' => 0],
        ];
    }

    /**
     * Build the entity-tag header emitted on a versioned resource.
     *
     * @return array<string, mixed>
     */
    private function buildEtagHeader(): array
    {
        return [
            'description' => 'The weak entity tag of the stored resource, to send in If-Match on a write that must not overwrite a newer state.',
            'schema'      => ['type' => 'string'],
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
     * OpenAPI 3.0's `nullable` is an inert unknown keyword under 3.1.
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
