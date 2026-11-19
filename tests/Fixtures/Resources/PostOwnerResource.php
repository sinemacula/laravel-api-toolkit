<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\Sum;

/**
 * Fixture user resource embedding, filtering on and aggregating posts whose
 * resource restricts them to the caller.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PostOwnerResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'post_owners';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name'];

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
            Relation::to('posts', OwnPostResource::class)->traversable(),
            Count::of('posts')->sortable('Fixture sort over a small table'),
            Sum::of('posts', 'id'),
        );
    }
}
