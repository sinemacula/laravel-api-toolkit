<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Schema\Field;

class OrganizationResource extends ApiResource
{
    public const string RESOURCE_TYPE = 'organization';
    protected static array $default   = ['id', 'name'];

    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id'),
            Field::scalar('name'),
        );
    }
}
