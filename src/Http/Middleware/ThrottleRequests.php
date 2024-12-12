<?php

namespace SineMacula\ApiToolkit\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests as BaseThrottleRequests;
use SineMacula\ApiToolkit\Http\Middleware\Traits\ThrottleRequestsTrait;

/**
 * Throttle requests middleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
class ThrottleRequests extends BaseThrottleRequests
{
    use ThrottleRequestsTrait;
}
