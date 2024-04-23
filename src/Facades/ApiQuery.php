<?php

namespace SineMacula\ApiToolkit\Facades;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;

/**
 * API query facade.
 *
 * @method static array getFields(?string $resource = null)
 * @method static array|null getFilters()
 * @method static array getOrder()
 * @method static int getLimit()
 * @method static int|null getPage()
 * @method static void parse()
 *
 * @see         \SineMacula\ApiToolkit\ApiQueryParser
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
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
