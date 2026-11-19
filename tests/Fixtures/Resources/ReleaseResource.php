<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture release resource, sortable by its pure enum platform.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ReleaseResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'releases';

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
            Field::scalar('name'),
            Field::scalar('platform')->sortable(),
        );
    }
}
