<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Discovery\Primary;

use SineMacula\ApiToolkit\Attributes\ForModel;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use Tests\Fixtures\Models\User;

/**
 * Fixture resource discovered via the ForModel attribute.
 *
 * @inheritable Extended by a child fixture proving attributes are not
 *              inherited by subclasses.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[ForModel(User::class)]
class DiscoveredUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'discovered_users';

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
