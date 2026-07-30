<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Exceptions;

use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\Http\Enums\HttpStatus;
use Tests\Fixtures\Enums\AppErrorCode;

/**
 * Fixture application exception carrying an application-defined error code.
 *
 * Stands in for an ApiException a consuming application or module would declare
 * outside the toolkit namespace, so the error catalogue can be shown to
 * document exceptions beyond the toolkit's own.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class WidgetFailureException extends ApiException
{
    /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface The internal error code */
    public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = AppErrorCode::WIDGET_FAILURE;

    /** @var \SineMacula\Http\Enums\HttpStatus The HTTP status code */
    public const HttpStatus HTTP_STATUS = HttpStatus::BAD_GATEWAY;
}
