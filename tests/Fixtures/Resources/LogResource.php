<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture log resource exposing a filterable JSON column.
 *
 * The `context` field maps to the JSON `context` column and is declared
 * filterable so the `$contains` containment operator can be driven over HTTP.
 * SQLite's grammar rejects `whereJsonContains`, so tests exercising `$contains`
 * against this resource must run under MySQL or PostgreSQL.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LogResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'logs';

    /** @var array<int, string> */
    protected static array $default = ['id', 'level', 'message', 'context'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id'),
            Field::scalar('level')->filterable()->sortable(),
            Field::scalar('message'),
            Field::scalar('context')->filterable(),
            Field::timestamp('created_at'),
        );
    }
}
