<?php

namespace Tests\Fixtures;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Schema\Field;

/**
 * Record resource fixture.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class RecordResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'record';

    /** @var array<int, string> */
    protected static array $default = ['name'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('name'),
        );
    }
}
