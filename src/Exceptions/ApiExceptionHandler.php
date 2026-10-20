<?php

declare(strict_types = 1);

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
use Illuminate\Support\Facades\Config;
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
    /**
     * Register the JSON rendering and the filter that keeps handled exceptions
     * out of every reporter.
     *
     * @param  \Illuminate\Foundation\Configuration\Exceptions  $exceptions
     * @return void
     */
    public static function handles(Exceptions $exceptions): void
    {
        $exceptions->dontReportWhen(fn (\Throwable $exception): bool => self::isHandled($exception));

        $exceptions->render(fn (\Throwable $exception, Request $request) => self::render($exception, $request));
    }

    /**
     * Determine whether the given exception is a handled response that must not
     * be reported.
     *
     * @param  \Throwable  $exception
     * @return bool
     */
    private static function isHandled(\Throwable $exception): bool
    {
        return ($exception instanceof ApiException && !$exception instanceof UnhandledException)
            || $exception instanceof LaravelUnauthorizedException
            || self::isClientHttpException($exception);
    }

    /**
     * Determine whether the given exception is an HTTP-layer exception that is
     * rendered as a client error.
     *
     * @param  \Throwable  $exception
     * @return bool
     */
    private static function isClientHttpException(\Throwable $exception): bool
    {
        if (!$exception instanceof SymfonyHttpExceptionInterface) {
            return false;
        }

        $status = $exception->getStatusCode();

        return $status === 419 || HttpStatus::tryFrom($status)?->isClientError() === true;
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

        // In auto mode, defer to Laravel's default rendering when the request
        // does not expect JSON and debug mode is enabled
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

        $mapped = self::resolveApiExceptionClass($exception);

        if ($mapped === HttpException::class && $exception instanceof SymfonyHttpExceptionInterface) {
            return self::mapGenericHttpException($exception, $meta, $headers);
        }

        return new $mapped($meta, $headers, $previous);
    }

    /**
     * Resolve the API exception class that the given throwable maps to.
     *
     * The first source-type the throwable is an instance of wins, so the order
     * of the map is significant: more specific source types must precede the
     * broader interfaces they extend. Anything unmatched falls back to the
     * unhandled exception.
     *
     * @param  \Throwable  $exception
     * @return class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>
     */
    private static function resolveApiExceptionClass(\Throwable $exception): string
    {
        foreach (self::exceptionMap() as $source => $target) {
            if ($exception instanceof $source) {
                return $target;
            }
        }

        return UnhandledException::class;
    }

    /**
     * The ordered map of source exception types to their API exception class.
     *
     * Ordering is significant: entries are evaluated top-to-bottom and the
     * first match wins, so more specific types precede the broader interfaces
     * they implement.
     *
     * @return array<class-string<\Throwable>, class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>>
     */
    private static function exceptionMap(): array
    {
        // @phpstan-ignore return.type (values are class-strings; PHPStan widens the large map literal to string)
        return [
            NotFoundHttpException::class           => NotFoundException::class,
            BackedEnumCaseNotFoundException::class => NotFoundException::class,
            ModelNotFoundException::class          => NotFoundException::class,
            SuspiciousOperationException::class    => NotFoundException::class,
            RecordsNotFoundException::class        => NotFoundException::class,
            MethodNotAllowedHttpException::class   => NotAllowedException::class,
            BadRequestHttpException::class         => BadRequestException::class,
            RequestExceptionInterface::class       => BadRequestException::class,
            LaravelUnauthorizedException::class    => ForbiddenException::class,
            AuthorizationException::class          => ForbiddenException::class,
            AccessDeniedHttpException::class       => ForbiddenException::class,
            AuthenticationException::class         => UnauthenticatedException::class,
            LaravelTokenMismatchException::class   => TokenMismatchException::class,
            ValidationException::class             => InvalidInputException::class,
            TooManyRequestsHttpException::class    => TooManyRequestsException::class,
            LockUnavailableException::class        => TooManyRequestsException::class,
            ServiceUnavailableHttpException::class => ServiceUnavailableException::class,
            PostTooLargeException::class           => PayloadTooLargeException::class,
            SymfonyHttpExceptionInterface::class   => HttpException::class,
        ];
    }

    /**
     * Map an unrecognised HTTP-layer exception, preserving its status code and
     * headers. Unknown status codes fall back to an unhandled error.
     *
     * @param  \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface  $exception
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, mixed>  $headers
     * @return \SineMacula\ApiToolkit\Exceptions\ApiException
     */
    private static function mapGenericHttpException(SymfonyHttpExceptionInterface $exception, ?array $meta, array $headers): ApiException
    {
        // Laravel's handler converts session token mismatches to a generic 419
        // HttpException before render callbacks run; 419 has no HttpStatus
        // case, so map it back to the dedicated exception
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
}
