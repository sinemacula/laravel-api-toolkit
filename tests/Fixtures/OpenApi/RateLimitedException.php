<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;
use SineMacula\Http\Enums\HttpStatus;

/**
 * Fixture base exception declaring the header every rate-limited refusal sets.
 *
 * Its subclasses declare nothing or redeclare the header, so the header
 * documentation can be shown to reach a subclass through its parent.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[ResponseHeader('Retry-After', 'The number of seconds to wait before retrying.', ['type' => 'integer'])]
abstract class RateLimitedException extends ApiException
{
    /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface The internal error code */
    public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::TOO_MANY_REQUESTS;

    /** @var \SineMacula\Http\Enums\HttpStatus The HTTP status code */
    public const HttpStatus HTTP_STATUS = HttpStatus::TOO_MANY_REQUESTS;
}
