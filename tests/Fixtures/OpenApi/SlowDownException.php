<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\Http\Enums\HttpStatus;
use Tests\Fixtures\Enums\AppErrorCode;

/**
 * Fixture application exception answering 429 under its own error code.
 *
 * Declares no response header, so a mapping onto it shows the headers of the
 * exception it replaces are still documented. Its text is read from the
 * fixtures translation namespace.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SlowDownException extends ApiException
{
    /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface The internal error code */
    public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = AppErrorCode::SLOW_DOWN;

    /** @var \SineMacula\Http\Enums\HttpStatus The HTTP status code */
    public const HttpStatus HTTP_STATUS = HttpStatus::TOO_MANY_REQUESTS;

    /**
     * Get the namespace of the current exception.
     *
     * @return string
     */
    #[\Override]
    protected function getNamespace(): string
    {
        return 'fixtures';
    }
}
