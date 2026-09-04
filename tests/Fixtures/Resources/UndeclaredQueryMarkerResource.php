<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;

/**
 * Fixture resource declaring query markers the compiled maps refuse to carry.
 *
 * Written as a raw schema array rather than through the field builder, which
 * cannot express the state: a filterable marker with no capability beside it
 * and a searchable marker with no strategy are both dropped from the compiled
 * column maps while the field keeps the declaration, so a reader of the surface
 * has a declaration the request-time gates do not hold. One dropped marker is
 * declared ahead of a column that survives, so skipping it cannot be mistaken
 * for abandoning the rest of the schema.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class UndeclaredQueryMarkerResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'undeclared_query_markers';

    /** @var array<int, string> */
    protected static array $default = ['id'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return [
            'email'  => ['filterable' => 'email'],
            'id'     => ['sortable' => 'id'],
            'handle' => ['searchable' => 'handle'],
        ];
    }
}
