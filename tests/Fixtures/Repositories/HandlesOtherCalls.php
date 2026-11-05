<?php

namespace Tests\Fixtures\Repositories;

/**
 * Trait fixture with its own magic call handler.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait HandlesOtherCalls
{
    /**
     * Echo the called method and its arguments back.
     *
     * phpcs:disable Squiz.Commenting.FunctionComment.ScalarTypeHintMissing
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     */
    public function __call($method, $arguments): mixed
    {
        // phpcs:enable
        return 'trait:' . $method . '(' . implode(',', $arguments) . ')';
    }
}
