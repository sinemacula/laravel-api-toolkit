<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\Http\Enums\HttpStatus;

/**
 * Raised when a well-formed request exceeds a query cost cap.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QueryTooExpensiveException extends ApiException
{
    /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface The internal error code */
    public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::QUERY_TOO_EXPENSIVE;

    /** @var \SineMacula\Http\Enums\HttpStatus The HTTP status code */
    public const HttpStatus HTTP_STATUS = HttpStatus::UNPROCESSABLE_CONTENT;

    /**
     * Create the exception for a cap the request exceeded.
     *
     * @param  string  $parameter
     * @param  string  $pointer
     * @param  string  $reason
     * @param  int  $limit
     * @param  int  $actual
     * @return self
     */
    public static function exceeded(string $parameter, string $pointer, string $reason, int $limit, int $actual): self
    {
        return new self([
            'parameter' => $parameter,
            'pointer'   => $pointer,
            'reason'    => $reason,
            'limit'     => $limit,
            'actual'    => $actual,
        ]);
    }
}
