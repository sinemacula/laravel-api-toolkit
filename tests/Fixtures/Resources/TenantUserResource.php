<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\Exists;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\Sum;

/**
 * Fixture resource over the tenant-scoped user, embedding and aggregating its
 * tenant-scoped posts.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TenantUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'tenant_users';

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
            Field::scalar('email')->searchable(SearchStrategy::EXACT),
            Relation::to('posts', TenantPostResource::class)->traversable(),
            Count::of('posts'),
            Exists::of('posts'),
            Sum::of('posts', 'words'),
        );
    }
}
