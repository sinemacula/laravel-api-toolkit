<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;
use SineMacula\Http\Enums\HttpStatus;

/**
 * Maintenance mode exception.
 *
 * Carries the retry and refresh intervals the application was put into
 * maintenance with, when either was given.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[ResponseHeader('Retry-After', 'The number of seconds to wait before retrying.', ['type' => 'integer'])]
#[ResponseHeader('Refresh', 'The number of seconds after which the client may reload.', ['type' => 'integer'])]
final class MaintenanceModeException extends ApiException
{
    /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface The internal error code */
    public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::MAINTENANCE_MODE;

    /** @var \SineMacula\Http\Enums\HttpStatus The HTTP status code */
    public const HttpStatus HTTP_STATUS = HttpStatus::SERVICE_UNAVAILABLE;
}
