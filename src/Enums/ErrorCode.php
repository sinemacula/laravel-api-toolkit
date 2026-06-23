<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

use SineMacula\ApiToolkit\Contracts\ErrorCodeInterface;
use SineMacula\ApiToolkit\Enums\Traits\ProvidesCode;

/**
 * Error code enumeration.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum ErrorCode: int implements ErrorCodeInterface
{
    use ProvidesCode;

    /*
    |---------------------------------------------------------------------------
    | Server Errors
    |---------------------------------------------------------------------------
    */
    case UNHANDLED_ERROR = 10001;

    /*
    |---------------------------------------------------------------------------
    | HTTP Errors
    |---------------------------------------------------------------------------
    */
    case BAD_REQUEST         = 10100;
    case UNAUTHENTICATED     = 10101;
    case FORBIDDEN           = 10102;
    case NOT_FOUND           = 10103;
    case NOT_ALLOWED         = 10104;
    case TOKEN_MISMATCH      = 10105;
    case INVALID_INPUT       = 10106;
    case TOO_MANY_REQUESTS   = 10107;
    case CONFLICT            = 10108;
    case GONE                = 10109;
    case PAYLOAD_TOO_LARGE   = 10110;
    case LOCKED              = 10111;
    case SERVICE_UNAVAILABLE = 10112;
    case HTTP_ERROR          = 10113;

    /*
    |---------------------------------------------------------------------------
    | API Errors
    |---------------------------------------------------------------------------
    */
    case MAINTENANCE_MODE = 10200;

    /*
    |---------------------------------------------------------------------------
    | File Errors
    |---------------------------------------------------------------------------
    */
    case FILE_UPLOAD_ERROR = 10300;
    case INVALID_IMAGE     = 10301;

    /*
    |---------------------------------------------------------------------------
    | Notification Errors
    |---------------------------------------------------------------------------
    */
    case INVALID_NOTIFICATION = 10400;
    case FAILED_TO_SEND_SMS   = 10401;
}
