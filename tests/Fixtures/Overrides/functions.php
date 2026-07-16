<?php

declare(strict_types = 1);

/**
 * Namespace-scoped function overrides for controller streaming tests.
 *
 * These functions intercept calls made from within the
 * SineMacula\ApiToolkit\Http\Concerns namespace so that tests can control
 * ob_flush(), flush(), and ob_get_level() without affecting global state.
 */

namespace SineMacula\ApiToolkit\Http\Concerns;

use Tests\Fixtures\Support\FunctionOverrides;

/**
 * Override ob_flush() within the Http\Concerns namespace.
 *
 * Prevents ob_flush() from pushing captured output past the test's output
 * buffer during stream response execution.
 *
 * @SuppressWarnings("php:S100")
 *
 * @return void
 */
function ob_flush(): void
{
    $override = FunctionOverrides::get('ob_flush');

    if ($override !== null) {
        $override();
        return;
    }

    \ob_flush();
}

/**
 * Override flush() within the Http\Concerns namespace.
 *
 * @return void
 */
function flush(): void
{
    $override = FunctionOverrides::get('flush');

    if ($override !== null) {
        $override();
        return;
    }

    \flush();
}

/**
 * Override ob_get_level() within the Http\Concerns namespace.
 *
 * @SuppressWarnings("php:S100")
 * @SuppressWarnings("php:S4144")
 *
 * @return int
 */
function ob_get_level(): int
{
    $override = FunctionOverrides::get('ob_get_level');

    if ($override !== null) {
        /** @phpstan-ignore cast.int */
        return (int) $override();
    }

    return \ob_get_level();
}

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use Tests\Fixtures\Support\FunctionOverrides;

/**
 * Override glob() within the OpenApi\Metadata namespace.
 *
 * Lets tests drive the exceptions-directory scan, including the failure path
 * where glob() reports an error by returning false.
 *
 * @param  string  $pattern
 * @param  int  $flags
 * @return array<int, string>|false
 */
function glob(string $pattern, int $flags = 0): array|false
{
    $override = FunctionOverrides::get('glob');

    if ($override !== null) {
        /** @var array<int, string>|false */
        return $override($pattern, $flags);
    }

    return \glob($pattern, $flags);
}

/**
 * Override defined() within the OpenApi\Metadata namespace.
 *
 * Lets tests simulate an exception subclass that declares no CODE constant so
 * the catalogue scan's guard against it is observable.
 *
 * @param  string  $constant
 * @return bool
 *
 * @SuppressWarnings("php:S4144")
 */
function defined(string $constant): bool
{
    $override = FunctionOverrides::get('defined');

    if ($override !== null) {
        return (bool) $override($constant);
    }

    return \defined($constant);
}

namespace SineMacula\ApiToolkit\Providers\Registrars;

use Tests\Fixtures\Support\FunctionOverrides;

/**
 * Override class_exists() within the Providers\Registrars namespace.
 *
 * Lets tests simulate the presence or absence of optional integration
 * packages (Octane, notifications) so the class_exists guards on both branches
 * are observable without changing the installed dependency set.
 *
 * @SuppressWarnings("php:S100")
 * @SuppressWarnings("php:S4144")
 *
 * @param  string  $class
 * @param  bool  $autoload
 * @return bool
 */
function class_exists(string $class, bool $autoload = true): bool
{
    $override = FunctionOverrides::get('class_exists');

    if ($override !== null) {
        return (bool) $override($class, $autoload);
    }

    return \class_exists($class, $autoload);
}
