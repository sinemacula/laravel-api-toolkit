<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests as BaseThrottleRequests;
use SineMacula\ApiToolkit\Http\Middleware\Traits\ThrottleRequestsTrait;

/**
 * Throttle requests middleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @inheritable
 */
class ThrottleRequests extends BaseThrottleRequests
{
    use ThrottleRequestsTrait;
}
