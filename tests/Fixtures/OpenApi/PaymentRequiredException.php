<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;
use SineMacula\Http\Enums\HttpStatus;
use Tests\Fixtures\Enums\AppErrorCode;

/**
 * Fixture application exception answering 402 when a subscription has lapsed.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[ResponseHeader('X-Upgrade-Url', 'The page where the subscription can be renewed.')]
final class PaymentRequiredException extends ApiException
{
    /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface The internal error code */
    public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = AppErrorCode::PAYMENT_REQUIRED;

    /** @var \SineMacula\Http\Enums\HttpStatus The HTTP status code */
    public const HttpStatus HTTP_STATUS = HttpStatus::PAYMENT_REQUIRED;
}
