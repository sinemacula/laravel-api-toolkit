<?php

namespace SineMacula\ApiToolkit\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\PostTooLargeException;
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
use SineMacula\Http\Enums\HttpStatus;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface as SymfonyHttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * API exception handler.
 *
 * Handles all API exceptions, ensuring they are properly formatted for API
 * responses.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ApiExceptionHandler
{
    /** @var array<int, string> Lower-case substrings that mark a request key as sensitive in logged context. */
    private const array DEFAULT_SENSITIVE_KEYS = ['password', 'token', 'secret', 'authorization'];

    /** @var string Placeholder substituted for a redacted sensitive value. */
    private const string REDACTION_PLACEHOLDER = '[redacted]';

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
        $strategy = Config::get('api-toolkit.exceptions.render_strategy', 'auto');

        if ($strategy === 'json_when_expected' && !$request->expectsJson()) {
            return null;
        }

        // In auto mode, defer to Laravel's default rendering when the
        // request does not expect JSON and debug mode is enabled
        if ($strategy === 'auto' && !$request->expectsJson() && (bool) Config::get('app.debug')) {
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
            $exception instanceof BadRequestHttpException         => BadRequestException::class,
            $exception instanceof RequestExceptionInterface       => BadRequestException::class,
            $exception instanceof LaravelUnauthorizedException    => ForbiddenException::class,
            $exception instanceof AuthorizationException          => ForbiddenException::class,
            $exception instanceof AccessDeniedHttpException       => ForbiddenException::class,
            $exception instanceof AuthenticationException         => UnauthenticatedException::class,
            $exception instanceof LaravelTokenMismatchException   => TokenMismatchException::class,
            $exception instanceof ValidationException             => InvalidInputException::class,
            $exception instanceof TooManyRequestsHttpException    => TooManyRequestsException::class,
            $exception instanceof ServiceUnavailableHttpException => ServiceUnavailableException::class,
            $exception instanceof PostTooLargeException           => PayloadTooLargeException::class,
            $exception instanceof SymfonyHttpExceptionInterface   => HttpException::class,
            default                                               => UnhandledException::class,
        };

        if ($mapped === HttpException::class && $exception instanceof SymfonyHttpExceptionInterface) {
            return self::mapGenericHttpException($exception, $meta, $headers);
        }

        return new $mapped($meta, $headers, $previous);
    }

    /**
     * Map an unrecognised HTTP-layer exception, preserving its status code
     * and headers. Unknown status codes fall back to an unhandled error.
     *
     * @param  \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface  $exception
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, mixed>  $headers
     * @return \SineMacula\ApiToolkit\Exceptions\ApiException
     */
    private static function mapGenericHttpException(
        SymfonyHttpExceptionInterface $exception,
        ?array $meta,
        array $headers
    ): ApiException {
        // Laravel's handler converts session token mismatches to a generic
        // 419 HttpException before render callbacks run; 419 has no
        // HttpStatus case, so map it back to the dedicated exception
        if ($exception->getStatusCode() === 419) {
            return new TokenMismatchException($meta, $headers, $exception);
        }

        $status = HttpStatus::tryFrom($exception->getStatusCode());

        if ($status === null) {
            return new UnhandledException($meta, $headers, $exception);
        }

        return new HttpException($status, $meta, $headers, $exception);
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
        $options = (bool) $request->input('pretty') ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : JSON_UNESCAPED_SLASHES;

        return Response::json(self::convertApiExceptionToArray($exception), $exception->getStatusCode(), $exception->getHeaders(), $options);
    }

    /**
     * Convert the given API exception to an array.
     *
     * @param  \SineMacula\ApiToolkit\Exceptions\ApiException  $exception
     * @return array<string, array<string, mixed>>
     */
    private static function convertApiExceptionToArray(ApiException $exception): array
    {
        return [
            'error' => array_filter([
                'status' => $exception->getStatusCode(),
                'code'   => $exception::getInternalErrorCode(),
                'title'  => $exception->getCustomTitle(),
                'detail' => $exception->getCustomDetail(),
                'meta'   => self::getApiExceptionMeta($exception),
            ], static fn ($value): bool => (bool) $value),
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
        $previous     = $exception->getPrevious();
        $debugConfig  = Config::get('api-toolkit.exceptions.include_debug_info');
        $includeDebug = (bool) ($debugConfig ?? Config::get('app.debug'));

        return $includeDebug && $previous !== null ? array_merge($exception->getCustomMeta() ?? [], [
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

        if (!config('api-toolkit.logging.cloudwatch.enabled', false)) {
            return;
        }

        Log::channel('cloudwatch-api-exceptions')->error(self::convertExceptionToString($exception), self::getContext());
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
            'data'   => self::redactSensitive(RequestFacade::all()),
        ];

        try {
            return array_filter(
                array_merge($context, [
                    'user_id' => Auth::id(),
                ]),
                static fn ($value): bool => (bool) $value,
            );
        } catch (\Throwable $exception) {
            return $context;
        }
    }

    /**
     * Redact configured sensitive keys from request data before it is logged.
     *
     * Keys are matched case-insensitively against a substring denylist so that
     * variants such as access_token, remember_token, and client_secret are all
     * covered. Nested arrays are redacted recursively.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function redactSensitive(array $data): array
    {
        $configured = Config::get('api-toolkit.exceptions.sensitive_keys', self::DEFAULT_SENSITIVE_KEYS);
        $configured = is_array($configured) ? $configured : self::DEFAULT_SENSITIVE_KEYS;

        $needles = array_values(array_filter(
            array_map(static fn ($needle): string => is_string($needle) ? strtolower($needle) : '', $configured),
            static fn (string $needle): bool => $needle !== '',
        ));

        return self::redactArray($data, $needles);
    }

    /**
     * Recursively replace the value of any sensitive key with the placeholder.
     *
     * @param  array<array-key, mixed>  $data
     * @param  array<int, string>  $needles
     * @return array<array-key, mixed>
     */
    private static function redactArray(array $data, array $needles): array
    {
        foreach ($data as $key => $value) {

            if (is_string($key) && self::isSensitiveKey($key, $needles)) {
                $data[$key] = self::REDACTION_PLACEHOLDER;

                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            $data[$key] = self::redactArray($value, $needles);
        }

        return $data;
    }

    /**
     * Determine whether a request key matches the sensitive-key denylist.
     *
     * @param  string  $key
     * @param  array<int, string>  $needles
     * @return bool
     */
    private static function isSensitiveKey(string $key, array $needles): bool
    {
        $key = strtolower($key);

        foreach ($needles as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
