<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Facades;

use Illuminate\Support\Facades\Facade;
use SineMacula\ApiToolkit\Config\HttpSettings;

/**
 * API query facade.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @method static array<int, string>|null getFields(string $resource)
 * @method static array<int, string>|null getAggregates(string $resource)
 * @method static array<array-key, array<int, string>> getAllAggregates()
 * @method static array<string, mixed>|null getFilters()
 * @method static \SineMacula\ApiToolkit\Search\SearchTerm|null getSearch()
 * @method static array<string, 'asc'|'desc'> getOrder()
 * @method static int|null getLimit()
 * @method static int getResolvedLimit()
 * @method static bool isCursorPaginated()
 * @method static int|null getPage()
 * @method static string|null getCursor()
 * @method static \SineMacula\ApiToolkit\Enums\TrashedState getTrashed()
 * @method static void parse(\Illuminate\Http\Request $request)
 *
 * @see         \SineMacula\ApiToolkit\ApiQueryParser
 */
final class ApiQuery extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return HttpSettings::parserAlias();
    }
}
