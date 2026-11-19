<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;
use SineMacula\Http\Enums\HttpStatus;

/**
 * Fixture exception that reports a status but carries no error code, so the
 * handler cannot render it and documenting it fails the export.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[ResponseHeader('X-Hold-Id', 'The identifier of the hold that blocked the release.')]
final class CodelessApiException extends ApiException
{
    /** @var \SineMacula\Http\Enums\HttpStatus The HTTP status code */
    public const HttpStatus HTTP_STATUS = HttpStatus::CONFLICT;
}
