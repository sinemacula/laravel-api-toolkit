<?php

/**
 * Namespace-scoped function overrides for controller streaming tests.
 *
 * These functions intercept calls made from within the
 * SineMacula\ApiToolkit\Http\Routing namespace so that tests can control
 * connection_aborted(), sleep(), and flush() without affecting global state.
 */

namespace SineMacula\ApiToolkit\Http\Concerns;

use Tests\Fixtures\Support\FunctionOverrides;

/**
 * Override ob_flush() within the Http\Controllers namespace.
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
 * Override flush() within the Http\Controllers namespace.
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

namespace SineMacula\ApiToolkit\Http\Routing;

use Tests\Fixtures\Support\FunctionOverrides;

/**
 * Override connection_aborted() within the controller namespace.
 *
 * @SuppressWarnings("php:S100")
 *
 * @return int
 */
function connection_aborted(): int
{
    $override = FunctionOverrides::get('connection_aborted');

    if ($override !== null) {
        /** @phpstan-ignore cast.int */
        return (int) $override();
    }

    return \connection_aborted();
}

/**
 * Override sleep() within the controller namespace.
 *
 * @param  int  $seconds
 * @return int
 */
function sleep(int $seconds): int
{
    $override = FunctionOverrides::get('sleep');

    if ($override !== null) {
        /** @phpstan-ignore cast.int */
        return (int) $override($seconds);
    }

    return \sleep($seconds);
}

/**
 * Override ob_flush() within the Http\Routing namespace.
 *
 * Prevents ob_flush() from pushing captured output past the test's output
 * buffer during stream response execution.
 *
 * @SuppressWarnings("php:S100")
 * @SuppressWarnings("php:S4144")
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
 * Override flush() within the Http\Routing namespace.
 *
 * @SuppressWarnings("php:S4144")
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
