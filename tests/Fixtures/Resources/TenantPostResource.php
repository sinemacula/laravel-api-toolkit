<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture resource over the tenant-scoped post.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class TenantPostResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'tenant_posts';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title'];

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
            Field::scalar('title')->filterable(Capability::EXACT),
            Field::scalar('words'),
        );
    }
}
