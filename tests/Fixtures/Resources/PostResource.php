<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Schema\Field;

class PostResource extends ApiResource
{
    public const string RESOURCE_TYPE = 'post';
    protected static array $default   = ['id', 'title'];

    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id'),
            Field::scalar('title'),
            Field::scalar('published'),
        );
    }
}
