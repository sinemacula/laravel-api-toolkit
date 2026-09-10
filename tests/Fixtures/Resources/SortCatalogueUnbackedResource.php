<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;

/**
 * Fixture resource declaring sortable the two columns an index covers without
 * being able to order by them.
 *
 * `ranking` is named second in a composite index, so the order it is read in is
 * decided by the column before it, and `body` carries only an index of a kind
 * that holds no order at all. Both are covered, and neither can answer an
 * ordered read, which is what separates a leading-column check over ordered
 * kinds from a membership check.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SortCatalogueUnbackedResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'sort_catalogue_unbacked';

    /** @var array<int, string> */
    protected static array $default = ['id', 'ranking', 'body'];

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
            Field::scalar('ranking')->sortable(),
            Field::scalar('body')->sortable(),
        );
    }
}
