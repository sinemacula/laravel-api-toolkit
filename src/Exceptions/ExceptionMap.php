<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Session\TokenMismatchException as LaravelTokenMismatchException;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\UnauthorizedException as LaravelUnauthorizedException;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Contracts\ErrorCodeInterface;
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
 * The ordered map of exceptions to the ApiException each renders as.
 *
 * A configured entry is matched just before the first built-in whose key it is
 * or extends, else after every built-in, so it replaces a built-in of the same
 * key and never shadows a more specific one. The first matching entry wins.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExceptionMap
{
    /** The built-in entries, specific types before the interfaces they implement */
    private const array DEFAULTS = [
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

    /** The message for a setting that is not an array */
    private const string SETTING_MESSAGE = 'The api-toolkit.exceptions.map setting must be an array of ApiException classes keyed by the exception class they replace.';

    /** The message for a key that cannot be mapped */
    private const string KEY_MESSAGE = 'The api-toolkit.exceptions.map key [%s] must be a Throwable class or interface that is not an ApiException.';

    /** The message for a target the handler cannot render */
    private const string TARGET_MESSAGE = 'The api-toolkit.exceptions.map target [%s] for [%s] must be an instantiable ApiException with a CODE, a 4xx or 5xx status, '
        . 'and ApiException\'s own constructor and getStatusCode().';

    /**
     * Constructor.
     *
     * @param  list<array{0: string, 1: class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>}>  $entries
     */
    private function __construct(

        /** The source and target pairs, in match order. */
        private array $entries,
    ) {}

    /**
     * Build the map from the built-ins and the configured entries.
     *
     * @return self
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidExceptionMapException
     */
    public static function fromConfig(): self
    {
        return new self(self::place(self::configuredEntries()));
    }

    /**
     * Build the map from the built-ins alone.
     *
     * @return self
     */
    public static function defaults(): self
    {
        return new self(self::place([]));
    }

    /**
     * Resolve the API exception class the given exception class renders as.
     *
     * @param  string  $exceptionClass
     * @return class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>
     */
    public function targetFor(string $exceptionClass): string
    {
        foreach ($this->entries as [$source, $target]) {
            if (is_a($exceptionClass, $source, true)) {
                return $target;
            }
        }

        return UnhandledException::class;
    }

    /**
     * Order the configured entries among the built-ins.
     *
     * @param  array<string, class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>>  $configured
     * @return list<array{0: string, 1: class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>}>
     */
    private static function place(array $configured): array
    {
        $preceding = [];
        $trailing  = [];

        foreach ($configured as $source => $target) {

            $slot = self::slotFor($source);

            if ($slot === null) {
                $trailing[] = [$source, $target];
                continue;
            }

            $preceding[$slot][] = [$source, $target];
        }

        $entries = [];

        foreach (self::DEFAULTS as $key => $target) {
            $entries = [...$entries, ...$preceding[$key] ?? [], [$key, $target]];
        }

        return [...$entries, ...$trailing];
    }

    /**
     * Find the first built-in key the given class is or extends.
     *
     * @param  string  $source
     * @return string|null
     */
    private static function slotFor(string $source): ?string
    {
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (is_a($source, $key, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Read and validate the configured entries.
     *
     * @return array<string, class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidExceptionMapException
     */
    private static function configuredEntries(): array
    {
        $map = Config::get('api-toolkit.exceptions.map');

        if ($map === null) {
            return [];
        }

        if (!is_array($map)) {
            throw new InvalidExceptionMapException(self::SETTING_MESSAGE);
        }

        $entries = [];

        foreach ($map as $source => $target) {
            $entries[self::validSource($source)] = self::validTarget($source, $target);
        }

        return $entries;
    }

    /**
     * Validate a configured key.
     *
     * @param  int|string  $source
     * @return string
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidExceptionMapException
     */
    private static function validSource(int|string $source): string
    {
        if (!is_string($source) || !is_a($source, \Throwable::class, true) || is_a($source, ApiException::class, true)) {
            throw new InvalidExceptionMapException(sprintf(self::KEY_MESSAGE, $source));
        }

        return $source;
    }

    /**
     * Validate a configured target.
     *
     * @param  int|string  $source
     * @param  mixed  $target
     * @return class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidExceptionMapException
     */
    private static function validTarget(int|string $source, mixed $target): string
    {
        if (!is_string($target) || !is_subclass_of($target, ApiException::class) || !self::isRenderable($target)) {
            throw new InvalidExceptionMapException(sprintf(self::TARGET_MESSAGE, is_string($target) ? $target : get_debug_type($target), $source));
        }

        return $target;
    }

    /**
     * Determine whether the handler can construct and render the target.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>  $target
     * @return bool
     */
    private static function isRenderable(string $target): bool
    {
        return self::keepsTheBaseContract($target) && self::hasErrorCode($target) && self::answersWithError($target);
    }

    /**
     * Determine whether the target is instantiable and keeps ApiException's own
     * constructor and instance status.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>  $target
     * @return bool
     */
    private static function keepsTheBaseContract(string $target): bool
    {
        $class = new \ReflectionClass($target);

        return $class->isInstantiable()
            && $class->getMethod('__construct')->getDeclaringClass()->getName()   === ApiException::class
            && $class->getMethod('getStatusCode')->getDeclaringClass()->getName() === ApiException::class;
    }

    /**
     * Determine whether the target declares an error code.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>  $target
     * @return bool
     */
    private static function hasErrorCode(string $target): bool
    {
        return defined($target . '::CODE') && constant($target . '::CODE') instanceof ErrorCodeInterface;
    }

    /**
     * Determine whether the target's static status is a 4xx or 5xx.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>  $target
     * @return bool
     */
    private static function answersWithError(string $target): bool
    {
        try {
            $status = $target::getHttpStatusCode();
        } catch (\LogicException) {
            return false;
        }

        return in_array(intdiv($status, 100), [4, 5], true);
    }
}
