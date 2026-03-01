<?php

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Http\Request;

/**
 * Stateless evaluator for guard callables on schema definitions.
 *
 * Each guard receives the resource instance and the current request. If any
 * guard returns exactly false, the definition is suppressed.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class GuardEvaluator
{
    /**
     * Evaluate whether all guards pass.
     *
     * Each guard is a callable that receives the resource instance and the
     * current request. If any guard returns exactly `false`, the definition
     * is suppressed.
     *
     * @param  array<int, mixed>  $guards
     * @param  mixed  $resource
     * @param  \Illuminate\Http\Request|null  $request
     * @return bool
     */
    public function passesGuards(array $guards, mixed $resource, ?Request $request): bool
    {
        foreach ($guards as $guard) {
            if (is_callable($guard) && $guard($resource, $request) === false) {
                return false;
            }
        }

        return true;
    }
}
