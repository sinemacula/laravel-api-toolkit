<?php

use SineMacula\ApiToolkit\Exceptions\ApiExceptionType;

return [

    /*
    |---------------------------------------------------------------------------
    | General Errors
    |---------------------------------------------------------------------------
    */

    ApiExceptionType::GENERAL_ERROR['code'] => [
        'title'  => 'Unknown Error',
        'detail' => 'Oh no! Something has gone wrong!'
    ],

    /*
    |---------------------------------------------------------------------------
    | HTTP Errors
    |---------------------------------------------------------------------------
    */

    ApiExceptionType::BAD_REQUEST['code'] => [
        'title'  => 'Bad Request',
        'detail' => 'There was an issue with the request, please try again'
    ],

    ApiExceptionType::UNAUTHORIZED['code'] => [
        'title'  => 'Unauthorized',
        'detail' => 'You are not authorized to view this resource'
    ],

    ApiExceptionType::FORBIDDEN['code'] => [
        'title'  => 'Forbidden',
        'detail' => 'You do not have the necessary privileges to perform this action'
    ],

    ApiExceptionType::NOT_FOUND['code'] => [
        'title'  => 'Not Found',
        'detail' => 'The requested resource could not be found'
    ],

    ApiExceptionType::NOT_ALLOWED['code'] => [
        'title'  => 'Not Allowed',
        'detail' => 'This request is not permitted'
    ],

    ApiExceptionType::TOKEN_MISMATCH['code'] => [
        'title'  => 'CSRF token mismatch',
        'detail' => 'The provided CSRF token is invalid or expired, and the request cannot be processed'
    ],

    ApiExceptionType::INVALID_INPUT['code'] => [
        'title'  => 'Invalid Input',
        'detail' => 'The information supplied was invalid'
    ],

    ApiExceptionType::TOO_MANY_ATTEMPTS['code'] => [
        'title'  => 'Too Many Attempts',
        'detail' => 'The resource was requested too frequently'
    ],

    /*
    |---------------------------------------------------------------------------
    | App Errors
    |---------------------------------------------------------------------------
    */

    ApiExceptionType::MAINTENANCE_MODE['code'] => [
        'title'  => 'Maintenance Mode',
        'detail' => config('app.name') . ' is currently in maintenance mode, please try again a little later'
    ],

    ApiExceptionType::MODEL_NOT_PARSEABLE['code'] => [
        'title'  => 'Model not Parseable',
        'detail' => 'The supplied model is not compatible with the API query parser'
    ],

    /*
    |---------------------------------------------------------------------------
    | File Errors
    |---------------------------------------------------------------------------
    */

    ApiExceptionType::FILE_UPLOAD_ERROR['code'] => [
        'title'  => 'File Upload Error',
        'detail' => 'There was an error whilst uploading the file, please try again'
    ],

    ApiExceptionType::INVALID_IMAGE['code'] => [
        'title'  => 'Invalid Image Supplied',
        'detail' => 'The supplied image was not a valid image file'
    ],

    /*
    |---------------------------------------------------------------------------
    | Notification Errors
    |---------------------------------------------------------------------------
    */

    ApiExceptionType::INVALID_NOTIFICATION['code'] => [
        'title'  => 'Invalid Notification',
        'detail' => 'The supplied notification is invalid'
    ],

    ApiExceptionType::FAILED_TO_SEND_SMS['code'] => [
        'title'  => 'Failed to Send SMS',
        'detail' => 'There was a problem sending the SMS'
    ]

];
