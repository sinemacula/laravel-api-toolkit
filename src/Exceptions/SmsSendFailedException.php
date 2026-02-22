<?php

namespace SineMacula\ApiToolkit\Exceptions;

use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Enums\HttpStatus;

/**
 * SMS send failed exception.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class SmsSendFailedException extends ApiException
{
    /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface The internal error code */
    public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::FAILED_TO_SEND_SMS;

    /** @var \SineMacula\ApiToolkit\Enums\HttpStatus The HTTP status code */
    public const HttpStatus HTTP_STATUS = HttpStatus::INTERNAL_SERVER_ERROR;
}
