<?php

/**
 * Namespace-scoped function overrides for controller streaming tests.
 *
 * These functions intercept calls made from within the
 * SineMacula\ApiToolkit\Http\Routing namespace so that tests can control
 * connection_aborted(), sleep(), and flush() without affecting global state.
 */

namespace SineMacula\ApiToolkit\Http\Routing;

use Tests\Fixtures\Support\FunctionOverrides;

/**
 * Override connection_aborted() within the controller namespace.
 *
 * @return int
 */
function connection_aborted(): int
{
    $override = FunctionOverrides::get('connection_aborted');

    if ($override !== null) {
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
        return (int) $override($seconds);
    }

    return \sleep($seconds);
}

/**
 * Override flush() within the controller namespace.
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
