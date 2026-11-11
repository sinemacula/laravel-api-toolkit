<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\Http\Enums\HttpStatus;

/**
 * Generic HTTP exception carrying a runtime status, such as abort(409).
 *
 * HTTP_STATUS only satisfies the base contract; rendering uses the runtime
 * status.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class HttpException extends ApiException
{
    /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface The internal error code */
    public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::HTTP_ERROR;

    /** @var \SineMacula\Http\Enums\HttpStatus The HTTP status code */
    public const HttpStatus HTTP_STATUS = HttpStatus::INTERNAL_SERVER_ERROR;

    /**
     * Constructor.
     *
     * @param  \SineMacula\Http\Enums\HttpStatus  $status
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, mixed>|null  $headers
     * @param  \Throwable|null  $previous
     */
    public function __construct(

        /** The runtime HTTP status */
        private readonly HttpStatus $status,

        // The error metadata
        ?array $meta = null,

        // The response headers
        ?array $headers = null,

        // The previous throwable
        ?\Throwable $previous = null,
    ) {
        parent::__construct($meta, $headers, $previous);
    }

    /**
     * Get the HTTP status code for this exception instance.
     *
     * @return int
     */
    #[\Override]
    public function getStatusCode(): int
    {
        return $this->status->getCode();
    }

    /**
     * Get the HTTP status for this exception instance.
     *
     * @return \SineMacula\Http\Enums\HttpStatus
     */
    #[\Override]
    public function getStatus(): HttpStatus
    {
        return $this->status;
    }
}
