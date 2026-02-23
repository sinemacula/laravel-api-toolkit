<?php

namespace Tests\Fixtures\Support;

/**
 * Parent class providing a fallback __call that throws BadMethodCallException.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class HasRepositoriesTestParent
{
    /**
     * Handle dynamic method calls.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     */
    public function __call(string $method, array $arguments): mixed
    {
        throw new \BadMethodCallException("Method {$method} does not exist.");
    }
}
