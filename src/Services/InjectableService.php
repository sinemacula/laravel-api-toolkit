<?php

namespace SineMacula\ApiToolkit\Services;

use Closure;
use Illuminate\Container\BoundMethod;
use Illuminate\Container\Container;
use LogicException;
use ReflectionMethod;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInterface;
use SineMacula\ApiToolkit\Services\Traits\HasServiceLifecycle;
use SineMacula\ApiToolkit\Traits\Lockable;

/**
 * Base API service whose hooks declare their dependencies as parameters.
 *
 * Subclasses must define a protected handle() method that returns a boolean,
 * and may define a public prepare() method that runs before it. The parameters
 * of both are resolved from the container as the service runs, the way Laravel
 * resolves controller and job method parameters, so an optional class-typed
 * parameter receives a resolved instance rather than its default. The service
 * itself is not built by the container, so its constructor and initialize() are
 * not resolved from it, and contextual bindings registered for the service
 * class do not apply.
 *
 * @template TPayload of array<array-key, mixed>|\Illuminate\Support\Collection<array-key, mixed>|\stdClass = \Illuminate\Support\Collection<array-key, mixed>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class InjectableService implements ServiceInterface
{
    /** @use \SineMacula\ApiToolkit\Services\Traits\HasServiceLifecycle<TPayload> */
    use HasServiceLifecycle;
    use Lockable;

    /**
     * Run the service.
     *
     * @return bool
     *
     * @throws \Throwable
     */
    public function run(): bool
    {
        $handler  = $this->resolveHook('handle') ?? throw new LogicException(sprintf('Service [%s] must define a protected handle() method', static::class));
        $preparer = $this->resolveHook('prepare');

        return $this->runLifecycle(
            $preparer !== null ? fn () => $this->callHook($preparer) : null,
            fn (): bool => $this->callHook($handler),
        );
    }

    /**
     * Resolve a hook the service defines as a closure, or null if it does not
     * define one.
     *
     * @param  string  $method
     * @return \Closure|null
     *
     * @throws \LogicException
     */
    private function resolveHook(string $method): ?Closure
    {
        if (!method_exists($this, $method)) {
            return null;
        }

        if ((new ReflectionMethod($this, $method))->isPrivate()) {
            throw new LogicException(sprintf('Service [%s] must not define %s() as private', static::class, $method));
        }

        return Closure::fromCallable([$this, $method]);
    }

    /**
     * Call a hook, resolving its parameters from the container.
     *
     * @param  \Closure  $hook
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \ReflectionException
     */
    private function callHook(Closure $hook): mixed
    {
        // Container::call() leaves the service on its build stack if the hook throws
        return BoundMethod::call(Container::getInstance(), $hook);
    }
}
