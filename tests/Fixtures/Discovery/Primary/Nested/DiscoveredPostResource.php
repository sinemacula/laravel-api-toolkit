<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Discovery\Primary\Nested;

use SineMacula\ApiToolkit\Attributes\ForModel;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use Tests\Fixtures\Models\Post;

/**
 * Fixture resource in a nested directory, proving recursive discovery.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[ForModel(Post::class)]
final class DiscoveredPostResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'discovered_posts';

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
