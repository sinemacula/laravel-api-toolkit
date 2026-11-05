<?php

namespace Tests\Fixtures\Repositories;

use SineMacula\ApiToolkit\Repositories\Traits\HasRepositories;
use SineMacula\Repositories\Contracts\RepositoryInterface;

/**
 * Class fixture that reaches repositories through the accessor trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 *
 * @method \Tests\Fixtures\Repositories\RecordRepository records()
 * @method \Tests\Fixtures\Repositories\NonModelRepository nonModels()
 * @method \SineMacula\Repositories\Contracts\RepositoryInterface unbound()
 */
class RepositoryConsumer
{
    use HasRepositories;

    /**
     * Resolve the named repository directly.
     *
     * @param  string  $name
     * @return \SineMacula\Repositories\Contracts\RepositoryInterface
     *
     * @throws \RuntimeException
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function repository(string $name): RepositoryInterface
    {
        return $this->resolveRepository($name);
    }
}
