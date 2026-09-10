<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture resource declaring only sortable columns an ordered index leads with.
 *
 * `label` leads an index of its own and `status` leads a composite one, so the
 * declaration is the one a connection naming index kinds has to accept. It is
 * the acceptance half of the sort backing proof: were an engine to report the
 * ordered kind under another name, this resource would stop validating.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SortCatalogueBackedResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'sort_catalogue_backed';

    /** @var array<int, string> */
    protected static array $default = ['id', 'label', 'status'];

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
            Field::scalar('label')->sortable(),
            Field::scalar('status')->sortable(),
        );
    }
}
