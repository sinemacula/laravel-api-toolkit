<?php

namespace SineMacula\ApiToolkit\Exceptions;

use SineMacula\ApiToolkit\Contracts\ErrorCodeInterface;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Enums\HttpStatus;

/**
 * Maintenance mode exception.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
class MaintenanceModeException extends ApiException
{
    /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface The internal error code */
    public const ErrorCodeInterface CODE = ErrorCode::MAINTENANCE_MODE;

    /** @var \SineMacula\ApiToolkit\Enums\HttpStatus The HTTP status code */
    public const HttpStatus HTTP_STATUS = HttpStatus::SERVICE_UNAVAILABLE;
}
