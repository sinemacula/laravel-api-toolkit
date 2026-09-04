<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture resource that declares a column the users table does not carry.
 *
 * Nothing in the resource contradicts the declaration: `nickname` is a scalar
 * field read straight off the model, so the only authority on whether it exists
 * is the table itself, and the defect is provable from the column listing
 * alone.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MissingColumnQueryableResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'missing_column_queryable';

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
        return Field::set(
            Field::scalar('id'),
            Field::scalar('nickname')->filterable(Capability::EXACT),
        );
    }
}
