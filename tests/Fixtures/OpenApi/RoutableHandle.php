<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture route-bindable value that is not an Eloquent model.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RoutableHandle implements UrlRoutable
{
    /**
     * Get the value of the route key.
     *
     * @return string
     */
    #[\Override]
    public function getRouteKey(): string
    {
        return 'handle';
    }

    /**
     * Get the route key name.
     *
     * @return string
     */
    #[\Override]
    public function getRouteKeyName(): string
    {
        return 'handle';
    }

    /**
     * Retrieve the model for a bound value, of which there is none.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    #[\Override]
    public function resolveRouteBinding(mixed $value, $field = null): ?Model
    {
        return null;
    }

    /**
     * Retrieve the child model for a bound value, of which there is none.
     *
     * @param  mixed  $childType
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    #[\Override]
    public function resolveChildRouteBinding(mixed $childType, mixed $value, $field): ?Model
    {
        return null;
    }
}
