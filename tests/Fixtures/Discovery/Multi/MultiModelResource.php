<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Discovery\Multi;

use SineMacula\ApiToolkit\Attributes\ForModel;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;

/**
 * Fixture resource bound to two models via a repeated ForModel attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[ForModel(User::class)]
#[ForModel(Post::class)]
final class MultiModelResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'multi_models';

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
