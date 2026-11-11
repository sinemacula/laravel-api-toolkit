<?php

declare(strict_types = 1);

use SineMacula\ApiToolkit\Enums\ErrorCode;

return [

    // General errors
    ErrorCode::UNHANDLED_ERROR->getCode() => [
        'title'  => 'Unknown Error',
        'detail' => 'Oh no! Something has gone wrong!',
    ],

    // HTTP errors
    ErrorCode::BAD_REQUEST->getCode() => [
        'title'  => 'Bad Request',
        'detail' => 'There was an issue with the request, please try again',
    ],

    ErrorCode::UNAUTHENTICATED->getCode() => [
        'title'  => 'Unauthenticated',
        'detail' => 'This request requires authentication',
    ],

    ErrorCode::FORBIDDEN->getCode() => [
        'title'  => 'Forbidden',
        'detail' => 'You do not have the necessary privileges to perform this action',
    ],

    ErrorCode::NOT_FOUND->getCode() => [
        'title'  => 'Not Found',
        'detail' => 'The requested resource could not be found',
    ],

    ErrorCode::NOT_ALLOWED->getCode() => [
        'title'  => 'Not Allowed',
        'detail' => 'This request is not permitted',
    ],

    ErrorCode::TOKEN_MISMATCH->getCode() => [
        'title'  => 'CSRF token mismatch',
        'detail' => 'The provided CSRF token is invalid or expired, and the request cannot be processed',
    ],

    ErrorCode::INVALID_INPUT->getCode() => [
        'title'  => 'Invalid Input',
        'detail' => 'The information supplied was invalid',
    ],

    ErrorCode::TOO_MANY_REQUESTS->getCode() => [
        'title'  => 'Too Many Requests',
        'detail' => 'The resource was requested too frequently',
    ],

    ErrorCode::CONFLICT->getCode() => [
        'title'  => 'Conflict',
        'detail' => 'The request could not be completed due to a conflict with the current state of the resource',
    ],

    ErrorCode::GONE->getCode() => [
        'title'  => 'Gone',
        'detail' => 'The requested resource is no longer available',
    ],

    ErrorCode::PAYLOAD_TOO_LARGE->getCode() => [
        'title'  => 'Payload Too Large',
        'detail' => 'The request payload exceeds the maximum permitted size',
    ],

    ErrorCode::LOCKED->getCode() => [
        'title'  => 'Locked',
        'detail' => 'The requested resource is locked',
    ],

    ErrorCode::SERVICE_UNAVAILABLE->getCode() => [
        'title'  => 'Service Unavailable',
        'detail' => 'The service is temporarily unavailable, please try again a little later',
    ],

    // No title: the handler derives it from the runtime HTTP status
    ErrorCode::HTTP_ERROR->getCode() => [
        'detail' => 'The request could not be completed',
    ],

    // Application errors
    ErrorCode::MAINTENANCE_MODE->getCode() => [
        'title'  => 'Maintenance Mode',
        'detail' => (is_string($name = config('app.name')) ? $name : 'The application') . ' is currently in maintenance mode, please try again a little later',
    ],

    ErrorCode::QUERY_TOO_EXPENSIVE->getCode() => [
        'title'  => 'Query Too Expensive',
        'detail' => 'The query exceeds a limit on how much work a single request may ask for, please narrow it and try again',
    ],

    // Generic HTTP error titles, keyed by status code (e.g. 404 => 'Not Found')
    // to override the status-derived default; 'unknown' covers a status with no
    // enum case
    'http' => [
        'unknown' => 'Unknown Error',
    ],

];
