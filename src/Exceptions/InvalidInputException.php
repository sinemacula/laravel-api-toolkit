<?php

namespace SineMacula\ApiToolkit\Exceptions;

use SineMacula\ApiToolkit\Contracts\ErrorCodeInterface;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Enums\HttpStatus;

/**
 * Invalid input exception.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
class InvalidInputException extends ApiException
{
    /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface The internal error code */
    public const ErrorCodeInterface CODE = ErrorCode::INVALID_INPUT;

    /** @var \SineMacula\ApiToolkit\Enums\HttpStatus The HTTP status code */
    public const HttpStatus HTTP_STATUS = HttpStatus::UNPROCESSABLE_ENTITY;
}
