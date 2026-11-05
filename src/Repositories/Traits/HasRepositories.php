<?php

namespace SineMacula\ApiToolkit\Repositories\Traits;

use BadMethodCallException;
use ReflectionMethod;
use SensitiveParameter;
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
     * phpcs:disable Squiz.Commenting.FunctionComment.ScalarTypeHintMissing
     *
     * @param  string  $method
     * @param  array  $arguments
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function __call(#[SensitiveParameter] $method, #[SensitiveParameter] $arguments): mixed
    {
        // phpcs:enable
        if (RepositoryResolver::has($method)) {
            return $this->resolveRepository($method);
        }

        if (is_callable([get_parent_class($this), '__call'])) {
            return parent::__call($method, $arguments);
        }

        if (!$this->handlesCallsHere()) {
            return $this->__call($method, $arguments);
        }

        throw new BadMethodCallException("Method {$method} does not exist.");
    }

    /**
     * Resolve the repository dynamically based on the method name.
     *
     * @param  string  $repository
     * @return \SineMacula\Repositories\Contracts\RepositoryInterface
     *
     * @throws \RuntimeException
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    protected function resolveRepository(string $repository): RepositoryInterface
    {
        if (!isset($this->repositories[$repository])) {
            $this->repositories[$repository] = RepositoryResolver::get($repository);
        }

        return $this->repositories[$repository];
    }

    /**
     * Determine whether the class resolves `__call` to this trait, in which
     * case calling it again could only recurse.
     *
     * @return bool
     */
    private function handlesCallsHere(): bool
    {
        return (new ReflectionMethod($this, '__call'))->getFileName() === __FILE__;
    }
}
