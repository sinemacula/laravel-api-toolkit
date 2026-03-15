<?php

namespace SineMacula\ApiToolkit\Exceptions;

use SineMacula\ApiToolkit\Enums\ErrorCode;

/**
 * Token mismatch exception.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class TokenMismatchException extends ApiException
{
    /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface The internal error code */
    public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::TOKEN_MISMATCH;

    /**
     * Get HTTP status code.
     *
     * Overrides the base method to return the non-standard 419 status
     * code directly, as this code has no corresponding case in the
     * shared HttpStatus enum.
     *
     * @return int
     */
    public static function getHttpStatusCode(): int
    {
        return 419;
    }
}
