<?php

namespace SineMacula\ApiToolkit\Facades;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;

/**
 * API query facade.
 *
 * @method static array<int, string>|null getFields(?string $resource = null)
 * @method static array<int, string>|null getCounts(?string $resource = null)
 * @method static array<string, mixed>|null getSums(?string $resource = null)
 * @method static array<string, mixed>|null getAverages(?string $resource = null)
 * @method static array<string, mixed>|null getFilters()
 * @method static array<string, string> getOrder()
 * @method static int|null getLimit()
 * @method static int|null getPage()
 * @method static string|null getCursor()
 * @method static void parse(\Illuminate\Http\Request $request)
 *
 * @see         \SineMacula\ApiToolkit\ApiQueryParser
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class ApiQuery extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        $alias = Config::get('api-toolkit.parser.alias');

        return is_string($alias) ? $alias : 'api.query';
    }
}
