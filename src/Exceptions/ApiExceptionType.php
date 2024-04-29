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
    const array GENERAL_ERROR = ['code' => 10001, 'status' => 500];

    /*
    |----------------------------------------------------------------------------
    | HTTP Errors
    |----------------------------------------------------------------------------
    */
    const array BAD_REQUEST       = ['code' => 10100, 'status' => 400];
    const array UNAUTHORIZED      = ['code' => 10101, 'status' => 401];
    const array FORBIDDEN         = ['code' => 10102, 'status' => 403];
    const array NOT_FOUND         = ['code' => 10103, 'status' => 404];
    const array NOT_ALLOWED       = ['code' => 10104, 'status' => 405];
    const array INVALID_INPUT     = ['code' => 10105, 'status' => 422];
    const array TOO_MANY_ATTEMPTS = ['code' => 10106, 'status' => 429];

    /*
    |----------------------------------------------------------------------------
    | API Errors
    |----------------------------------------------------------------------------
    */
    const array MAINTENANCE_MODE    = ['code' => 10200, 'status' => 503];
    const array MODEL_NOT_PARSEABLE = ['code' => 10201, 'status' => 500];

    /*
    |----------------------------------------------------------------------------
    | File Errors
    |----------------------------------------------------------------------------
    */
    const array FILE_UPLOAD_ERROR = ['code' => 10300, 'status' => 500];
    const array INVALID_IMAGE     = ['code' => 10301, 'status' => 422];

    /*
    |----------------------------------------------------------------------------
    | Notification Errors
    |----------------------------------------------------------------------------
    */
    const array INVALID_NOTIFICATION = ['code' => 10400, 'status' => 500];
    const array FAILED_TO_SEND_SMS   = ['code' => 10401, 'status' => 500];

}
