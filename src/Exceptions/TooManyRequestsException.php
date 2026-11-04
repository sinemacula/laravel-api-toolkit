<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;
use SineMacula\Http\Enums\HttpStatus;

/**
 * Too many requests exception.
 *
 * A request refused by the rate limiter carries the limit, the attempts left,
 * the seconds to wait before retrying, and the time the limit resets.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[ResponseHeader('Retry-After', 'The number of seconds to wait before retrying.', ['type' => 'integer'])]
#[ResponseHeader('X-RateLimit-Limit', 'The number of requests allowed in the current window.', ['type' => 'integer'])]
#[ResponseHeader('X-RateLimit-Remaining', 'The number of requests left in the current window.', ['type' => 'integer'])]
#[ResponseHeader('X-RateLimit-Reset', 'The Unix timestamp at which the current window resets.', ['type' => 'integer'])]
final class TooManyRequestsException extends ApiException
{
    /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface The internal error code */
    public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::TOO_MANY_REQUESTS;

    /** @var \SineMacula\Http\Enums\HttpStatus The HTTP status code */
    public const HttpStatus HTTP_STATUS = HttpStatus::TOO_MANY_REQUESTS;
}
