<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture resource that declares a sensitive column filterable.
 *
 * The column is real and backs the field, so the only defect is the query
 * declaration itself: `password` is a configured sensitive column and may never
 * reach the filter surface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SensitiveQueryableResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'sensitive_queryable';

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
            Field::scalar('id')->filterable(Capability::RANGE),
            Field::scalar('password')->filterable(Capability::EXACT),
        );
    }
}
