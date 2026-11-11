<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Http\Request;

/**
 * Stateless evaluator for guard callables on schema definitions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class GuardEvaluator
{
    /**
     * Determine whether all guards pass, i.e. none returns exactly false.
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
