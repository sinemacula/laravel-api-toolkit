<?php

namespace SineMacula\ApiToolkit\Repositories\Traits;

use SineMacula\ApiToolkit\Repositories\RepositoryResolver;
use SineMacula\Repositories\Contracts\RepositoryInterface;

/**
 * Provides access to all registered repositories.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
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
     * @throws \Exception
     */
    public function __call(#[\SensitiveParameter] $method, #[\SensitiveParameter] $arguments): mixed
    {
        // phpcs:enable
        if (RepositoryResolver::has($method)) {
            return $this->resolveRepository($method);
        }

        if (is_callable([get_parent_class($this), '__call'])) {
            return parent::__call($method, $arguments);
        }

        foreach (class_uses($this) as $trait) {
            if ($trait !== __TRAIT__ && method_exists($trait, '__call')) {
                return call_user_func_array([$this, '__call'], [$method, $arguments]); // @codeCoverageIgnore
            }
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
        if (!isset($this->repositories[$repository])) {
            $this->repositories[$repository] = RepositoryResolver::get($repository);
        }

        return $this->repositories[$repository];
    }
}
