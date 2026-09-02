<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture log resource exposing a filterable JSON column.
 *
 * The `context` field maps to the JSON `context` column and is declared
 * filterable with the document capability, the only one the `$contains`
 * containment operator is served from, so that operator can be driven over
 * HTTP. SQLite's grammar rejects `whereJsonContains`, so tests exercising
 * `$contains` against this resource must run under MySQL or PostgreSQL.
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
            Field::scalar('level')->filterable(Capability::ENUM)->sortable(),
            Field::scalar('message'),
            Field::scalar('context')->filterable(Capability::DOCUMENT),
            Field::timestamp('created_at'),
        );
    }
}
