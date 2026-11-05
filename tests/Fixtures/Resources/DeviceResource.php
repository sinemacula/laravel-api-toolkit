<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture device resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DeviceResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'devices';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'platform'];

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
            Field::scalar('name')->filterable(Capability::EXACT),
            Field::scalar('platform')->filterable(Capability::ENUM),
            Field::scalar('status')->filterable(Capability::ENUM),
        );
    }
}
