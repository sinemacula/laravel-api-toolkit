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
