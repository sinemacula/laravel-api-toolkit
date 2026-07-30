<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources\V3;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\OpenApi\Attributes\SchemaName;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture v3 user resource sharing its basename but overriding its name.
 *
 * Its basename would collide with the v1 and v2 resources, but the
 * #[SchemaName] override renames its component so it survives alongside them.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[SchemaName('UserV3')]
final class UserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'users';

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
        );
    }
}
