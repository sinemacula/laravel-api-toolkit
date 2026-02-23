<?php

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Schema\Count;
use SineMacula\ApiToolkit\Http\Resources\Schema\Field;
use SineMacula\ApiToolkit\Http\Resources\Schema\Relation;

/**
 * Fixture post resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class PostResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'posts';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title']; // @phpstan-ignore property.phpDocType

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id'),
            Field::scalar('title'),
            Field::scalar('body'),
            Field::scalar('published'),
            Field::timestamp('created_at'),
            Field::timestamp('updated_at'),
            Relation::to('user', UserResource::class),
            Relation::to('tags', TagResource::class),
            Count::of('tags'),
        );
    }
}
