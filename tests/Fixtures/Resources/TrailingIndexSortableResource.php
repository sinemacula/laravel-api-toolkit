<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture log resource declaring a trailing index column sortable.
 *
 * The logs table carries one composite index over `level` and `created_at`, so
 * `created_at` appears in an index without leading one. Ordering by it alone
 * therefore reads the table, and the declaration is the case that separates a
 * leading-column check from a membership check.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TrailingIndexSortableResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'trailing-index-logs';

    /** @var array<int, string> */
    protected static array $default = ['id', 'created_at'];

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
            Field::scalar('created_at')->sortable(),
        );
    }
}
