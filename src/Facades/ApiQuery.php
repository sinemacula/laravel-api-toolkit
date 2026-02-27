<?php

namespace SineMacula\ApiToolkit\Facades;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;

/**
 * API query facade.
 *
 * @method static array|null getFields(?string $resource = null)
 * @method static array|null getCounts(?string $resource = null)
 * @method static array|null getSums(?string $resource = null)
 * @method static array|null getAverages(?string $resource = null)
 * @method static array|null getFilters()
 * @method static array getOrder()
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
    protected static function getFacadeAccessor(): string
    {
        return Config::get('api-toolkit.parser.alias');
    }
}
