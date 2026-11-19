<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
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
    /** The message Laravel gives a denial raised without one */
    private const string DEFAULT_DENIAL = 'This action is unauthorized.';

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
        return $exception instanceof SymfonyHttpExceptionInterface && self::isClientStatus($exception->getStatusCode());
    }

    /**
     * Determine whether the given status is rendered as a client error.
     *
     * @param  int  $status
     * @return bool
     */
    private static function isClientStatus(int $status): bool
    {
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
            return Response::json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], $exception->status, [], JSON_INVALID_UTF8_SUBSTITUTE);
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
        $mapped  = $map->targetFor($exception::class);

        if ($exception instanceof ValidationException) {
            return self::mapValidationException($exception, $mapped, $meta, $headers);
        }

        if ($mapped === HttpException::class && $exception instanceof SymfonyHttpExceptionInterface) {
            return self::mapGenericHttpException($map, $exception, $meta, $headers);
        }

        return self::instantiate($mapped, $exception, $meta, $headers);
    }

    /**
     * Map a validation failure as the configured exception, or as a generic
     * HTTP exception keeping a client-error status other than 422.
     *
     * @param  \Illuminate\Validation\ValidationException  $exception
     * @param  class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>  $mapped
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, mixed>  $headers
     * @return \SineMacula\ApiToolkit\Exceptions\ApiException
     */
    private static function mapValidationException(ValidationException $exception, string $mapped, ?array $meta, array $headers): ApiException
    {
        $status = HttpStatus::tryFrom($exception->status);

        if ($mapped === InvalidInputException::class && $exception->status !== 422 && $status?->isClientError() === true) {
            return new HttpException($status, $meta, $headers);
        }

        return new $mapped($meta, $headers);
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
            return self::instantiate($target, $exception, $meta, $headers);
        }

        $known = HttpStatus::tryFrom($status);

        if ($known === null) {
            return new UnhandledException($meta, $headers, $exception);
        }

        $denial = self::denialOf($exception, $status);

        return new HttpException($known, self::withReason($meta, $denial), $headers, $exception, self::detailOf($denial));
    }

    /**
     * Instantiate the target for the exception, carrying the denial behind it
     * when the target renders as a client error.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>  $target
     * @param  \Throwable  $exception
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, mixed>  $headers
     * @return \SineMacula\ApiToolkit\Exceptions\ApiException
     */
    private static function instantiate(string $target, \Throwable $exception, ?array $meta, array $headers): ApiException
    {
        $denial = self::denialOf($exception, $target::getHttpStatusCode());

        return new $target(self::withReason($meta, $denial), $headers, $exception, self::detailOf($denial));
    }

    /**
     * Resolve the policy denial Laravel prepared the exception from, when the
     * exception renders as a client error.
     *
     * @param  \Throwable  $exception
     * @param  int  $status
     * @return \Illuminate\Auth\Access\AuthorizationException|null
     */
    private static function denialOf(\Throwable $exception, int $status): ?AuthorizationException
    {
        $previous = $exception->getPrevious();

        return $exception instanceof SymfonyHttpExceptionInterface && $previous instanceof AuthorizationException && self::isClientStatus($status) ? $previous : null;
    }

    /**
     * Resolve the detail a denial carries, unless it is Laravel's default.
     *
     * @param  \Illuminate\Auth\Access\AuthorizationException|null  $denial
     * @return string|null
     */
    private static function detailOf(?AuthorizationException $denial): ?string
    {
        $message = $denial?->getMessage();

        return $message === self::DEFAULT_DENIAL ? null : $message;
    }

    /**
     * Add the code a denial carries to the meta as its reason, unless it is
     * missing or neither an int, a string nor a backed enum.
     *
     * @param  array<string, mixed>|null  $meta
     * @param  \Illuminate\Auth\Access\AuthorizationException|null  $denial
     * @return array<string, mixed>|null
     */
    private static function withReason(?array $meta, ?AuthorizationException $denial): ?array
    {
        // getCode() is typed int, though the denial holds the code as raised
        $code   = $denial?->toResponse()->code();
        $reason = $code instanceof \BackedEnum ? $code->value : $code;

        if ((!is_int($reason) && !is_string($reason)) || $code === 0) {
            return $meta;
        }

        return [...$meta ?? [], 'reason' => $reason];
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
        $options = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

        if ($request->boolean('pretty')) {
            $options |= JSON_PRETTY_PRINT;
        }

        return Response::json(self::convertApiExceptionToArray($exception, $includeDebug), $exception->getStatusCode(), $exception->getHeaders(), $options);
    }

    /**
     * Convert the given API exception to an array.
     *
     * The detail is always present; the title, the meta, rendered as an object,
     * and the debug details are left out when empty.
     *
     * @param  \SineMacula\ApiToolkit\Exceptions\ApiException  $exception
     * @param  bool  $includeDebug
     * @return array<string, array<string, mixed>>
     */
    private static function convertApiExceptionToArray(ApiException $exception, bool $includeDebug): array
    {
        $title = $exception->getCustomTitle();
        $meta  = $exception->getCustomMeta() ?? [];

        return [
            'error' => array_filter([
                'status' => $exception->getStatusCode(),
                'code'   => $exception::getInternalErrorCode(),
                'title'  => $title === '' ? null : $title,
                'detail' => $exception->getCustomDetail(),
                'meta'   => $meta === [] ? null : (object) $meta,
                'debug'  => $includeDebug ? self::getDebugInfo($exception) : null,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * Describe the throwable the API exception was mapped from, if any.
     *
     * @param  \SineMacula\ApiToolkit\Exceptions\ApiException  $exception
     * @return array<string, mixed>|null
     */
    private static function getDebugInfo(ApiException $exception): ?array
    {
        $previous = $exception->getPrevious();

        return $previous === null ? null : [
            'message'   => $previous->getMessage(),
            'exception' => $previous::class,
            'file'      => $previous->getFile(),
            'line'      => $previous->getLine(),
            'trace'     => collect($previous->getTrace())->map(fn ($trace) => Arr::except($trace, ['args']))->all(),
        ];
    }
}
