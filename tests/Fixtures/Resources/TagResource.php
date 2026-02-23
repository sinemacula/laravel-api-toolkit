<?php

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Schema\Field;

/**
 * Fixture tag resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class TagResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'tags';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name']; // @phpstan-ignore property.phpDocType

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
            Field::timestamp('created_at'),
            Field::timestamp('updated_at'),
        );
    }
}
