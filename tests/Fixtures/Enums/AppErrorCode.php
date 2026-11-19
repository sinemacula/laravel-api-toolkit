<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums;

use SineMacula\ApiToolkit\Contracts\ErrorCodeInterface;
use SineMacula\ApiToolkit\Enums\Concerns\ProvidesCode;

/**
 * Fixture application error code, distinct from the toolkit's ErrorCode.
 *
 * Stands in for a code an application or module would define outside the
 * toolkit, so error-catalogue discovery of application exceptions can be
 * exercised without borrowing a toolkit code.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum AppErrorCode: int implements ErrorCodeInterface
{
    use ProvidesCode;

    case WIDGET_FAILURE   = 20001;
    case SLOW_DOWN        = 20002;
    case PAYMENT_REQUIRED = 20003;
    case WIDGET_LOCKED    = 20004;
}
