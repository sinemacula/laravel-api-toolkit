<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture resource whose filter, sort and search all need the catalogue.
 *
 * `emial` misspells the `email` column and nothing indexes `label`, so against
 * a migrated table both are defects, and against an unread one both are
 * unverified.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CatalogueRowResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'catalogue_rows';

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
            Field::scalar('emial')->filterable(Capability::EXACT),
            Field::scalar('label')->sortable(),
            Field::scalar('title')->searchable(SearchStrategy::SUBSTRING),
        );
    }
}
