<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Discovery\Partial;

use SineMacula\ApiToolkit\Attributes\ForModel;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use Tests\Fixtures\Models\Post;

/**
 * Fixture resource whose first binding names a missing model and whose second
 * binding is valid.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @phpstan-ignore argument.type
 */
#[ForModel('Tests\Fixtures\Models\DoesNotExist')]
#[ForModel(Post::class)]
final class PartlyBoundResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'partly_bound';

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
