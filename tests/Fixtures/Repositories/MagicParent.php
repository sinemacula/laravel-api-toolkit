<?php

namespace Tests\Fixtures\Repositories;

/**
 * Parent fixture that answers any unknown method call.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class MagicParent
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
        return 'parent:' . $method . '(' . implode(',', $arguments) . ')';
    }
}
