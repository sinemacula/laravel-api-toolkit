<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture user resource that serves at most two records a page.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CappedPageUserResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'capped_page_users';

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
            Field::scalar('id')->sortable(),
            Field::scalar('name')->filterable(Capability::EXACT)->sortable(),
        );
    }

    /**
     * Return the largest page size this resource serves.
     *
     * @return int
     */
    #[\Override]
    public static function maxLimit(): int
    {
        return 2;
    }
}
