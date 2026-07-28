<?php

declare(strict_types = 1);

/**
 * Namespaced function handlers for documentable route filter tests.
 *
 * A route may carry a plain function name as its string handler. The filter
 * treats such a handler as a non-closure and never reflects it, so this stub
 * exists purely to prove that the non-closure branch stops before reflection.
 */

namespace Tests\Fixtures\OpenApi\RouteHandlers;

/**
 * No-op function used as a string route handler.
 *
 * @return null
 */
function stub(): null
{
    return null;
}
