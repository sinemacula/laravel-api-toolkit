<?php

namespace SineMacula\ApiToolkit\Repositories\Traits;

use SineMacula\ApiToolkit\Repositories\RepositoryResolver;
use SineMacula\Repositories\Contracts\RepositoryInterface;

/**
 * Provides access to all registered repositories.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
trait HasRepositories
{
    /** @var array<string, \SineMacula\Repositories\Contracts\RepositoryInterface> */
    private array $repositories = [];

    /**
     * Magic method to dynamically call repository accessors while allowing
     * other `__call` methods to work.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     *
     * @throws \BadMethodCallException
     */
    public function __call(#[\SensitiveParameter] string $method, #[\SensitiveParameter] array $arguments): mixed
    {
        if (RepositoryResolver::has($method)) {
            return $this->resolveRepository($method);
        }

        $parent = get_parent_class($this);

        if (is_string($parent) && is_callable([$parent, '__call'])) {
            return parent::__call($method, $arguments);
        }

        throw new \BadMethodCallException("Method {$method} does not exist.");
    }

    /**
     * Resolve the repository dynamically based on the method name.
     *
     * @param  string  $repository
     * @return mixed
     *
     * @throws \Exception
     */
    protected function resolveRepository(string $repository): RepositoryInterface
    {
        if (!RepositoryResolver::shouldCacheResolvedInstances()) {
            return RepositoryResolver::get($repository);
        }

        if (!isset($this->repositories[$repository])) {
            $this->repositories[$repository] = RepositoryResolver::get($repository);
        }

        return $this->repositories[$repository];
    }
}
