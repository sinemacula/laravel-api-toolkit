<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\UnauthorizedException as LaravelUnauthorizedException;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Config\HttpSettings;
use SineMacula\ApiToolkit\Enums\RenderStrategy;
use SineMacula\Http\Enums\HttpStatus;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface as SymfonyHttpExceptionInterface;

/**
 * Renders exceptions as API error responses.
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
     * An invalid configuration is left to Laravel's renderer, so rendering it
     * cannot fail again.
     *
     * @param  \Throwable  $exception
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse|null
     */
    private static function render(\Throwable $exception, Request $request): ?JsonResponse
    {
        $prepared = $exception instanceof InvalidConfigurationException ? null : self::prepare($exception);

        if ($prepared === null || self::isLeftToLaravel($request, $prepared['strategy'])) {
            return null;
        }

        // Precognition clients read Laravel's validation body
        if ($exception instanceof ValidationException && $request->isPrecognitive()) {
            return Response::json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], $exception->status);
        }

        return self::renderApiExceptionWithJson($request, $prepared['exception'], $prepared['debug']);
    }

    /**
     * Read the rendering settings and map the exception, or report an invalid
     * setting and return null.
     *
     * @param  \Throwable  $exception
     * @return array{strategy: \SineMacula\ApiToolkit\Enums\RenderStrategy, debug: bool, exception: \SineMacula\ApiToolkit\Exceptions\ApiException}|null
     */
    private static function prepare(\Throwable $exception): ?array
    {
        try {
            return [
                'strategy'  => HttpSettings::renderStrategy(),
                'debug'     => HttpSettings::shouldIncludeDebugInfo() ?? (bool) Config::get('app.debug'),
                'exception' => $exception instanceof ApiException ? $exception : self::mapApiException(ExceptionMap::fromConfig(), $exception),
            ];
        } catch (InvalidConfigurationException $invalid) {
            report($invalid);

            return null;
        }
    }

    /**
     * Determine whether the render strategy leaves the request to Laravel's
     * renderer.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\ApiToolkit\Enums\RenderStrategy  $strategy
     * @return bool
     */
    private static function isLeftToLaravel(Request $request, RenderStrategy $strategy): bool
    {
        // Auto mode leaves non-JSON requests in debug to Laravel's renderer
        return !$request->expectsJson()
            && ($strategy === RenderStrategy::JSON_WHEN_EXPECTED || ($strategy === RenderStrategy::AUTO && (bool) Config::get('app.debug')));
    }

    /**
     * Maps standard exceptions to custom API exceptions.
     *
     * @param  \SineMacula\ApiToolkit\Exceptions\ExceptionMap  $map
     * @param  \Throwable  $exception
     * @return \SineMacula\ApiToolkit\Exceptions\ApiException
     */
    private static function mapApiException(ExceptionMap $map, \Throwable $exception): ApiException
    {
        $headers = method_exists($exception, 'getHeaders') ? $exception->getHeaders() : [];
        $meta    = method_exists($exception, 'errors') ? $exception->errors() : null;

        $previous = is_a($exception, ValidationException::class) ? null : $exception;
        $mapped   = $map->targetFor($exception::class);

        if ($mapped === HttpException::class && $exception instanceof SymfonyHttpExceptionInterface) {
            return self::mapGenericHttpException($map, $exception, $meta, $headers);
        }

        return new $mapped($meta, $headers, $previous);
    }

    /**
     * Map an HTTP-layer exception no specific entry names as the exception its
     * status stands for, else as a generic one keeping its status. Unknown
     * status codes fall back to an unhandled error.
     *
     * @param  \SineMacula\ApiToolkit\Exceptions\ExceptionMap  $map
     * @param  \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface  $exception
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, mixed>  $headers
     * @return \SineMacula\ApiToolkit\Exceptions\ApiException
     */
    private static function mapGenericHttpException(ExceptionMap $map, SymfonyHttpExceptionInterface $exception, ?array $meta, array $headers): ApiException
    {
        $status = $exception->getStatusCode();

        // Laravel converts token mismatches to a generic 419 before rendering
        $target = $status === 419 ? TokenMismatchException::class : $map->targetForStatus($status);

        if ($target !== null) {
            return new $target($meta, $headers, $exception);
        }

        $known = HttpStatus::tryFrom($status);

        return $known === null ? new UnhandledException($meta, $headers, $exception) : new HttpException($known, $meta, $headers, $exception);
    }

    /**
     * Renders an API exception as a JSON response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\ApiToolkit\Exceptions\ApiException  $exception
     * @param  bool  $includeDebug
     * @return \Illuminate\Http\JsonResponse
     */
    private static function renderApiExceptionWithJson(Request $request, ApiException $exception, bool $includeDebug): JsonResponse
    {
        $options = (bool) $request->input('pretty') ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : JSON_UNESCAPED_SLASHES;

        return Response::json(self::convertApiExceptionToArray($exception, $includeDebug), $exception->getStatusCode(), $exception->getHeaders(), $options);
    }

    /**
     * Convert the given API exception to an array.
     *
     * @param  \SineMacula\ApiToolkit\Exceptions\ApiException  $exception
     * @param  bool  $includeDebug
     * @return array<string, array<string, mixed>>
     */
    private static function convertApiExceptionToArray(ApiException $exception, bool $includeDebug): array
    {
        return [
            'error' => array_filter([
                'status' => $exception->getStatusCode(),
                'code'   => $exception::getInternalErrorCode(),
                'title'  => $exception->getCustomTitle(),
                'detail' => $exception->getCustomDetail(),
                'meta'   => self::getApiExceptionMeta($exception, $includeDebug),
            ], static fn ($value): bool => (bool) $value),
        ];
    }

    /**
     * Extracts meta information for an API exception.
     *
     * @param  \SineMacula\ApiToolkit\Exceptions\ApiException  $exception
     * @param  bool  $includeDebug
     * @return array<string, mixed>|null
     */
    private static function getApiExceptionMeta(ApiException $exception, bool $includeDebug): ?array
    {
        $previous = $exception->getPrevious();

        return $includeDebug && $previous !== null ? array_merge($exception->getCustomMeta() ?? [], [
            'message'   => $previous->getMessage(),
            'exception' => $previous::class,
            'file'      => $previous->getFile(),
            'line'      => $previous->getLine(),
            'trace'     => collect($previous->getTrace())->map(fn ($trace) => Arr::except($trace, ['args']))->all(),
        ]) : $exception->getCustomMeta();
    }
}
