<?php

namespace SineMacula\ApiToolkit\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis as BaseThrottleRequestsWithRedis;
use SineMacula\ApiToolkit\Http\Middleware\Traits\ThrottleRequestsTrait;

/**
 * Throttle requests with redis middleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
class ThrottleRequestsWithRedis extends BaseThrottleRequestsWithRedis
{
    use ThrottleRequestsTrait;
}
