<?php

namespace SineMacula\ApiToolkit\Exceptions;

/**
 * The base API exception type.
 *
 * Define all the core exception information.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
class ApiExceptionType
{
    /*
    |----------------------------------------------------------------------------
    | General Errors
    |----------------------------------------------------------------------------
    */
    public const array GENERAL_ERROR = ['code' => 10001, 'status' => 500];

    /*
    |----------------------------------------------------------------------------
    | HTTP Errors
    |----------------------------------------------------------------------------
    */
    public const array BAD_REQUEST       = ['code' => 10100, 'status' => 400];
    public const array UNAUTHORIZED      = ['code' => 10101, 'status' => 401];
    public const array FORBIDDEN         = ['code' => 10102, 'status' => 403];
    public const array NOT_FOUND         = ['code' => 10103, 'status' => 404];
    public const array NOT_ALLOWED       = ['code' => 10104, 'status' => 405];
    public const array TOKEN_MISMATCH    = ['code' => 10105, 'status' => 419];
    public const array INVALID_INPUT     = ['code' => 10106, 'status' => 422];
    public const array TOO_MANY_ATTEMPTS = ['code' => 10107, 'status' => 429];

    /*
    |----------------------------------------------------------------------------
    | API Errors
    |----------------------------------------------------------------------------
    */
    public const array MAINTENANCE_MODE    = ['code' => 10200, 'status' => 503];
    public const array MODEL_NOT_PARSEABLE = ['code' => 10201, 'status' => 500];

    /*
    |----------------------------------------------------------------------------
    | File Errors
    |----------------------------------------------------------------------------
    */
    public const array FILE_UPLOAD_ERROR = ['code' => 10300, 'status' => 500];
    public const array INVALID_IMAGE     = ['code' => 10301, 'status' => 422];

    /*
    |----------------------------------------------------------------------------
    | Notification Errors
    |----------------------------------------------------------------------------
    */
    public const array INVALID_NOTIFICATION = ['code' => 10400, 'status' => 500];
    public const array FAILED_TO_SEND_SMS   = ['code' => 10401, 'status' => 500];

}
