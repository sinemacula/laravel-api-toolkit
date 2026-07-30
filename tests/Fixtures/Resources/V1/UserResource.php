<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources\V1;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture v1 user resource sharing its basename with the v2 resource.
 *
 * Two resources in distinct version namespaces carry the same UserResource
 * basename, exercising component-name derivation across API versions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
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
