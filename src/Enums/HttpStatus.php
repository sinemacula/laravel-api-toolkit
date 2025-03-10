<?php

namespace SineMacula\ApiToolkit\Enums;

use SineMacula\ApiToolkit\Enums\Traits\ProvidesCode;

/**
 * Provide an enum of HTTP status codes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
enum HttpStatus: int
{
    use ProvidesCode;

    /*
    |---------------------------------------------------------------------------
    | Informational responses
    |---------------------------------------------------------------------------
    */
    case CONTINUE            = 100;
    case SWITCHING_PROTOCOLS = 101;
    case PROCESSING          = 102;
    case EARLY_HINTS         = 103;

    /*
    |---------------------------------------------------------------------------
    | Successful responses
    |---------------------------------------------------------------------------
    */
    case OK                            = 200;
    case CREATED                       = 201;
    case ACCEPTED                      = 202;
    case NON_AUTHORITATIVE_INFORMATION = 203;
    case NO_CONTENT                    = 204;
    case RESET_CONTENT                 = 205;
    case PARTIAL_CONTENT               = 206;
    case MULTI_STATUS                  = 207;
    case ALREADY_REPORTED              = 208;
    case IM_USED                       = 226;

    /*
    |---------------------------------------------------------------------------
    | Redirection messages
    |---------------------------------------------------------------------------
    */
    case MULTIPLE_CHOICES   = 300;
    case MOVED_PERMANENTLY  = 301;
    case FOUND              = 302;
    case SEE_OTHER          = 303;
    case NOT_MODIFIED       = 304;
    case USE_PROXY          = 305; // Deprecated
    case SWITCH_PROXY       = 306; // No longer used
    case TEMPORARY_REDIRECT = 307;
    case PERMANENT_REDIRECT = 308;

    /*
    |---------------------------------------------------------------------------
    | Client error responses
    |---------------------------------------------------------------------------
    */
    case BAD_REQUEST                     = 400;
    case UNAUTHORIZED                    = 401;
    case PAYMENT_REQUIRED                = 402;
    case FORBIDDEN                       = 403;
    case NOT_FOUND                       = 404;
    case METHOD_NOT_ALLOWED              = 405;
    case NOT_ACCEPTABLE                  = 406;
    case PROXY_AUTHENTICATION_REQUIRED   = 407;
    case REQUEST_TIMEOUT                 = 408;
    case CONFLICT                        = 409;
    case GONE                            = 410;
    case LENGTH_REQUIRED                 = 411;
    case PRECONDITION_FAILED             = 412;
    case PAYLOAD_TOO_LARGE               = 413;
    case URI_TOO_LONG                    = 414;
    case UNSUPPORTED_MEDIA_TYPE          = 415;
    case RANGE_NOT_SATISFIABLE           = 416;
    case EXPECTATION_FAILED              = 417;
    case IM_A_TEAPOT                     = 418;
    case TOKEN_MISMATCH                  = 419;
    case MISDIRECTED_REQUEST             = 421;
    case UNPROCESSABLE_ENTITY            = 422;
    case LOCKED                          = 423;
    case FAILED_DEPENDENCY               = 424;
    case TOO_EARLY                       = 425;
    case UPGRADE_REQUIRED                = 426;
    case PRECONDITION_REQUIRED           = 428;
    case TOO_MANY_REQUESTS               = 429;
    case REQUEST_HEADER_FIELDS_TOO_LARGE = 431;
    case UNAVAILABLE_FOR_LEGAL_REASONS   = 451;

    /*
    |---------------------------------------------------------------------------
    | Server error responses
    |---------------------------------------------------------------------------
    */
    case INTERNAL_SERVER_ERROR           = 500;
    case NOT_IMPLEMENTED                 = 501;
    case BAD_GATEWAY                     = 502;
    case SERVICE_UNAVAILABLE             = 503;
    case GATEWAY_TIMEOUT                 = 504;
    case HTTP_VERSION_NOT_SUPPORTED      = 505;
    case VARIANT_ALSO_NEGOTIATES         = 506;
    case INSUFFICIENT_STORAGE            = 507;
    case LOOP_DETECTED                   = 508;
    case NOT_EXTENDED                    = 510;
    case NETWORK_AUTHENTICATION_REQUIRED = 511;
}
