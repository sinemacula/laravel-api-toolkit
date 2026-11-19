<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Pagination;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Str;

/**
 * A cursor page whose cursors carry each ordered column as the row stores it.
 *
 * The seek compares stored values, which a cast can misstate: a date-time casts
 * to a string without its fraction, and an enum or value object may not encode.
 *
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @extends \Illuminate\Pagination\CursorPaginator<TKey, TValue>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RawCursorPaginator extends CursorPaginator
{
    /**
     * Take over the given page as it stands.
     *
     * @param  \Illuminate\Pagination\CursorPaginator<int, mixed>  $page
     * @return self<int, mixed>
     */
    public static function of(CursorPaginator $page): self
    {
        // Built without the cursor, which would reverse a backward page again
        $raw = new self($page->getCollection(), $page->perPage(), null, $page->getOptions());

        $raw->cursor  = $page->cursor();
        $raw->hasMore = $page->hasMore;

        return $raw;
    }

    /**
     * Get the cursor parameters for the given item, read from a model's stored
     * attributes.
     *
     * @param  \ArrayAccess|\stdClass  $item
     * @return array<string, mixed>
     *
     * @phpstan-ignore missingType.generics
     */
    #[\Override]
    public function getParametersForItem(mixed $item): array
    {
        $model = $item instanceof JsonResource ? $item->resource : $item;

        if (!$model instanceof Model) {
            return parent::getParametersForItem($item);
        }

        $stored     = $model->getRawOriginal();
        $parameters = [];

        foreach (array_filter($this->parameters, is_string(...)) as $column) {
            $parameters[$column] = $stored[$column] ?? $stored[Str::afterLast($column, '.')] ?? null;
        }

        return $parameters;
    }
}
