<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture country resource filterable by its string key and its name.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QueryableCountryResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'queryable_countries';

    /** @var array<int, string> */
    protected static array $default = ['code', 'name'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('code')->filterable(Capability::EXACT),
            Field::scalar('name')->filterable(Capability::EXACT),
        );
    }
}
