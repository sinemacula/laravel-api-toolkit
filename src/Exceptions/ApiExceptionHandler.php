<?php

namespace SineMacula\ApiToolkit\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Session\TokenMismatchException as LaravelTokenMismatchException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\UnauthorizedException as LaravelUnauthorizedException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * API exception handler.
 *
 * Handles all API exceptions, ensuring they are properly formatted for API
 * responses.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
class ApiExceptionHandler
{
    /** @var array<int, string> */
    private const array DEFAULT_SENSITIVE_CONTEXT_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'api_token',
        'access_token',
        'refresh_token',
        'secret',
        'authorization',
    ];

    /**
     * Convenience method to register the various exception handler controls.
     *
     * @param  \Illuminate\Foundation\Configuration\Exceptions  $exceptions
     * @return void
     */
    public static function handles(Exceptions $exceptions): void
    {
        $exceptions->report(function (ApiException $exception): void {
            self::logApiException($exception);
        })->stop();

        $exceptions->render(fn (\Throwable $exception, Request $request) => self::render($exception, $request));
    }

    /**
     * Renders the given exception as an HTTP response.
     *
     * @param  \Throwable  $exception
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse|null
     */
    private static function render(\Throwable $exception, Request $request): ?JsonResponse
    {
        // We only render exceptions as JSON when specifically required and if
        // the application is in debug mode.
        if (!$request->expectsJson() && (bool) Config::get('app.debug')) {
            return null;
        }

        // If the exception is not already an App Exception, then determine what
        // it is and return the relevant custom exception. Any exceptions that
        // are not matched will just be returned as a 'general error' i.e. an
        // unhandled exception
        if (!$exception instanceof ApiException) {
            $exception = self::mapApiException($exception);
        }

        return self::renderApiExceptionWithJson($request, $exception);
    }

    /**
     * Maps standard exceptions to custom API exceptions.
     *
     * @param  \Throwable  $exception
     * @return \SineMacula\ApiToolkit\Exceptions\ApiException
     */
    private static function mapApiException(\Throwable $exception): ApiException
    {
        $headers = method_exists($exception, 'getHeaders') ? $exception->getHeaders() : [];
        $meta    = method_exists($exception, 'errors') ? $exception->errors() : null;

        $previous = is_a($exception, ValidationException::class) ? null : $exception;

        $mapped = match (true) {
            $exception instanceof NotFoundHttpException           => NotFoundException::class,
            $exception instanceof BackedEnumCaseNotFoundException => NotFoundException::class,
            $exception instanceof ModelNotFoundException          => NotFoundException::class,
            $exception instanceof SuspiciousOperationException    => NotFoundException::class,
            $exception instanceof RecordsNotFoundException        => NotFoundException::class,
            $exception instanceof MethodNotAllowedHttpException   => NotAllowedException::class,
            $exception instanceof RequestExceptionInterface       => BadRequestException::class,
            $exception instanceof LaravelUnauthorizedException    => ForbiddenException::class,
            $exception instanceof AuthorizationException          => ForbiddenException::class,
            $exception instanceof AccessDeniedHttpException       => ForbiddenException::class,
            $exception instanceof AuthenticationException         => UnauthenticatedException::class,
            $exception instanceof LaravelTokenMismatchException   => TokenMismatchException::class,
            $exception instanceof ValidationException             => InvalidInputException::class,
            $exception instanceof TooManyRequestsHttpException    => TooManyRequestsException::class,
            default                                               => UnhandledException::class,
        };

        return new $mapped($meta, $headers, $previous);
    }

    /**
     * Renders an API exception as a JSON response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\ApiToolkit\Exceptions\ApiException  $exception
     * @return \Illuminate\Http\JsonResponse
     */
    private static function renderApiExceptionWithJson(Request $request, ApiException $exception): JsonResponse
    {
        $options = $request->boolean('pretty')
            ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            : JSON_UNESCAPED_SLASHES;

        return Response::json(
            self::convertApiExceptionToArray($exception),
            $exception::getHttpStatusCode(),
            $exception->getHeaders(),
            $options,
        );
    }

    /**
     * Convert the given API exception to an array.
     *
     * @param  \SineMacula\ApiToolkit\Exceptions\ApiException  $exception
     * @return array{error: array<string, mixed>}
     */
    private static function convertApiExceptionToArray(ApiException $exception): array
    {
        return [
            'error' => array_filter([
                'status' => $exception::getHttpStatusCode(),
                'code'   => $exception::getInternalErrorCode(),
                'title'  => $exception->getCustomTitle(),
                'detail' => $exception->getCustomDetail(),
                'meta'   => self::getApiExceptionMeta($exception),
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * Extracts meta information for an API exception.
     *
     * @param  \SineMacula\ApiToolkit\Exceptions\ApiException  $exception
     * @return array<string, mixed>|null
     */
    private static function getApiExceptionMeta(ApiException $exception): ?array
    {
        $previous = $exception->getPrevious();

        return ((bool) Config::get('app.debug')) && $previous instanceof \Throwable ? array_merge($exception->getCustomMeta() ?? [], [
            'message'   => $previous->getMessage(),
            'exception' => $previous::class,
            'file'      => $previous->getFile(),
            'line'      => $previous->getLine(),
            'trace'     => collect($previous->getTrace())->map(fn ($trace) => Arr::except($trace, ['args']))->all(),
        ]) : $exception->getCustomMeta();
    }

    /**
     * Log the given API exception.
     *
     * @param  \SineMacula\ApiToolkit\Exceptions\ApiException  $exception
     * @return void
     */
    private static function logApiException(ApiException $exception): void
    {
        Log::channel('api-exceptions')->error(self::convertExceptionToString($exception), self::getContext());

        if (config('api-toolkit.logging.cloudwatch.enabled', false)) {
            Log::channel('cloudwatch-api-exceptions')->error(self::convertExceptionToString($exception), self::getContext());
        }
    }

    /**
     * Formats an exception into a string representation.
     *
     * @param  \Throwable  $exception
     * @return string
     */
    private static function convertExceptionToString(\Throwable $exception): string
    {
        return sprintf(
            '[%s] "%s" on line %s of file %s',
            $exception->getCode(),
            $exception->getMessage(),
            $exception->getLine(),
            $exception->getFile(),
        );
    }

    /**
     * Retrieves context for logging an exception.
     *
     * @return array<string, mixed>
     */
    private static function getContext(): array
    {
        $context = [
            'method' => RequestFacade::method(),
            'path'   => RequestFacade::path(),
        ];

        if (Config::get('api-toolkit.logging.request_context.include_payload', true)) {
            $context['data'] = self::sanitizeContextData(RequestFacade::all());
        }

        try {
            return array_filter(
                array_merge($context, [
                    'user_id' => Auth::id(),
                ]),
                static fn (mixed $value): bool => $value !== null && $value !== [],
            );
        } catch (\Throwable $exception) {
            return $context;
        }
    }

    /**
     * Sanitize context payload data.
     *
     * @param  mixed  $data
     * @return mixed
     */
    private static function sanitizeContextData(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $keys = Config::get('api-toolkit.logging.request_context.sensitive_keys', self::DEFAULT_SENSITIVE_CONTEXT_KEYS);

        return self::maskSensitiveContextValues($data, array_map('strtolower', (array) $keys));
    }

    /**
     * Mask values with sensitive keys recursively.
     *
     * @param  array<int|string, mixed>  $data
     * @param  array<int, string>  $sensitive_keys
     * @return array<int|string, mixed>
     */
    private static function maskSensitiveContextValues(array $data, array $sensitive_keys): array
    {
        /** @var array<int|string, mixed> $sanitized */
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $sensitive_keys, true)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            $sanitized[$key] = is_array($value)
                ? self::maskSensitiveContextValues($value, $sensitive_keys)
                : $value;
        }

        return $sanitized;
    }
}
