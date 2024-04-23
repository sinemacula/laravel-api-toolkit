<?php

namespace SineMacula\ApiToolkit\Exceptions;

use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Arr;
use Illuminate\Validation\UnauthorizedException;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Auth\Facades\Auth;
use SineMacula\ApiToolkit\Log\Facades\Journal;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Exception handler.
 *
 * Handle all exceptions thrown within the app.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
class Handler extends ExceptionHandler
{
    /** @var string|null The traceable id of the exception */
    protected ?string $traceId = null;

    /** @var array<int, class-string|string> A list of the exception types that are not reported */
    protected $dontReport = [
        BackedEnumCaseNotFoundException::class,
        HttpException::class,
        HttpResponseException::class,
        MethodNotAllowedHttpException::class,
        ModelNotFoundException::class,
        MultipleRecordsFoundException::class,
        NotFoundHttpException::class,
        RecordsNotFoundException::class,
        SuspiciousOperationException::class,
        TokenMismatchException::class,
        ValidationException::class
    ];

    /**
     * Report or log an exception.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        if ($this->shouldntReport($exception)) {
            return;
        }

        try {

            // Handle unexpected exceptions
            if (!$exception instanceof ApiException) {

                Journal::channel('exceptions')->error($exception, $this->getContext());

                if (app()->bound('sentry')) {
                    $this->traceId = (string) app('sentry')->captureException($exception);
                }

                return;
            }

            // If the exception is an app exception then there is no need to
            // report it, but we still want to log it locally
            Journal::channel('app')->error(Journal::formatException($exception), $this->getContext());

        } catch (Exception $e) {
            parent::report($exception);
        }
    }

    /**
     * Get the exception context.
     *
     * @return array
     */
    protected function getContext(): array
    {
        $request = app('request');

        $context = [
            'method' => $request->method(),
            'path'   => $request->path(),
            'data'   => $request->all()
        ];

        try {
            return array_filter(
                array_merge($context, [
                    'user_id' => Auth::id()
                ])
            );
        } catch (Throwable $exception) {
            return $context;
        }
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable                $exception
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function render($request, Throwable $exception): SymfonyResponse
    {
        // Render the exception with ignition when in debug mode
        if (!$request->expectsJson() && config('app.debug')) {
            return $this->convertExceptionToResponse($exception);
        }

        // If the exception is not already an App Exception, then determine what
        // it is and return the relevant custom exception. Any exceptions that
        // are not matched will just be returned as a 'general error' i.e. an
        // unhandled exception
        if (!$exception instanceof ApiException) {
            $exception = $this->prepareAppException($exception);
        }

        return $this->renderAppExceptionWithJson($request, $exception);
    }

    /**
     * Determine if the exception handler response should be JSON.
     *
     * @param  \Throwable  $exception
     * @return \Core\Exceptions\ApiException
     */
    protected function prepareAppException(Throwable $exception): ApiException
    {
        $headers = method_exists($exception, 'getHeaders') ? $exception->getHeaders() : [];

        $type = match (true) {
            $exception instanceof NotFoundHttpException           => AppExceptionType::NOT_FOUND,
            $exception instanceof BackedEnumCaseNotFoundException => AppExceptionType::NOT_FOUND,
            $exception instanceof ModelNotFoundException          => AppExceptionType::NOT_FOUND,
            $exception instanceof SuspiciousOperationException    => AppExceptionType::NOT_FOUND,
            $exception instanceof RecordsNotFoundException        => AppExceptionType::NOT_FOUND,
            $exception instanceof MethodNotAllowedHttpException   => AppExceptionType::NOT_ALLOWED,
            $exception instanceof UnauthorizedException           => AppExceptionType::UNAUTHORIZED,
            $exception instanceof AuthorizationException          => AppExceptionType::UNAUTHORIZED,
            default                                               => AppExceptionType::GENERAL_ERROR
        };

        return new ApiException($type, null, $headers, $exception);
    }

    /**
     * Render an exception to a JSON object.
     *
     * @param  \Illuminate\Http\Request       $request
     * @param  \Core\Exceptions\ApiException  $exception
     * @return \Illuminate\Http\JsonResponse
     */
    private function renderAppExceptionWithJson(Request $request, ApiException $exception): JsonResponse
    {
        $options = $request->get('pretty') ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : JSON_UNESCAPED_SLASHES;

        return response()->json($this->convertAppExceptionToArray($exception), $exception->getStatusCode(), $exception->getHeaders(), $options);
    }

    /**
     * Convert the given app exception to an array.
     *
     * @param  \Core\Exceptions\ApiException  $exception
     * @return array
     */
    protected function convertAppExceptionToArray(ApiException $exception): array
    {
        return [
            'error' => array_filter([
                'status' => $exception->getStatusCode(),
                'code'   => $exception->getCustomCode(),
                'title'  => $exception->getCustomTitle(),
                'detail' => $exception->getCustomDetail(),
                'meta'   => $this->getAppExceptionMeta($exception)
            ])
        ];
    }

    /**
     * Return the meta for the given app exception.
     *
     * @param  \Core\Exceptions\ApiException  $exception
     * @return array|null
     */
    private function getAppExceptionMeta(ApiException $exception): ?array
    {
        $previous = $exception->getPrevious();

        return config('app.debug') && $previous ? array_merge($exception->getCustomMeta() ?? [], [
            'trace_id'  => $this->traceId ?? null,
            'message'   => $previous->getMessage(),
            'exception' => get_class($previous),
            'file'      => $previous->getFile(),
            'line'      => $previous->getLine(),
            'trace'     => collect($previous->getTrace())->map(fn ($trace) => Arr::except($trace, ['args']))->all()
        ]) : $exception->getCustomMeta();
    }

    /**
     * Determine if the exception handler response should be JSON.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable                $exception
     * @return bool
     */
    protected function shouldReturnJson($request, Throwable $exception): bool
    {
        return !config('app.debug') || (config('app.debug') && $request->expectsJson());
    }
}
