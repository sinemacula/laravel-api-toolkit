<?php

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\Traits\HasRepositories;

/**
 * Accessor fixture that defines its own magic call handler and keeps the
 * repository accessor under an alias.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 *
 * @method mixed repositoryCall(string $method, array<int, mixed> $arguments)
 */
class OverridingAccessorConsumer
{
    use HasRepositories {
        HasRepositories::__call as repositoryCall;
    }

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
        return 'class:' . $method . '(' . implode(',', $arguments) . ')';
    }
}
