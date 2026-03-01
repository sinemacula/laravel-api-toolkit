<?php

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Schema\Count;
use SineMacula\ApiToolkit\Http\Resources\Schema\Field;

/**
 * Fixture organization resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class OrganizationResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'organizations';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'slug'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id'),
            Field::scalar('name'),
            Field::scalar('slug'),
            Field::timestamp('created_at'),
            Field::timestamp('updated_at'),
            Count::of('users'),
        );
    }
}
