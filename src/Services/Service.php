<?php

namespace SineMacula\ApiToolkit\Services;

use Closure;
use Error;
use Illuminate\Container\BoundMethod;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInterface;
use SineMacula\ApiToolkit\Traits\Lockable;
use stdClass;
use Throwable;

/**
 * Base API service.
 *
 * Subclasses must define a protected handle() method that returns a boolean,
 * and may define a public prepare() method that runs before it. When either
 * declares required parameters, the container resolves its parameters as the
 * service runs.
 *
 * @method void prepare()
 *
 * @template TPayload of array<array-key, mixed>|\Illuminate\Support\Collection<array-key, mixed>|\stdClass = \Illuminate\Support\Collection<array-key, mixed>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
abstract class Service implements ServiceInterface
{
    use Lockable;

    /** @var bool|null Service outcome status */
    protected ?bool $status = null;

    /** @var bool Indicate whether to use database transactions for the service */
    protected bool $useTransaction = true;

    /** @var bool Indicate whether to lock the task execution */
    protected bool $useLock = false;

    /** @var TPayload The service input payload */
    protected array|Collection|stdClass $payload;

    /**
     * Constructor.
     *
     * @param  array<array-key, mixed>|\Illuminate\Support\Collection<array-key, mixed>|\stdClass  $payload
     */
    public function __construct(array|Collection|stdClass $payload = [])
    {
        if (!$payload instanceof Collection && !$payload instanceof stdClass) {
            $payload = collect($payload);
        }

        /** @var TPayload $payload */
        $this->payload = $payload;

        $this->initialize();
    }

    /**
     * Get the service status.
     *
     * @return bool|null
     */
    public function getStatus(): ?bool
    {
        return $this->status;
    }

    /**
     * Instruct the service to use database transactions.
     *
     * NOTE: Transactions are only supported on MySQL databases running the
     * InnoDB engine
     *
     * @return \SineMacula\ApiToolkit\Services\Service
     */
    public function useTransaction(): static
    {
        $this->useTransaction = true;

        return $this;
    }

    /**
     * Instruct the service not to use database transactions.
     *
     * @return \SineMacula\ApiToolkit\Services\Service
     */
    public function dontUseTransaction(): static
    {
        $this->useTransaction = false;

        return $this;
    }

    /**
     * Instruct the service to use a cache lock.
     *
     * @return \SineMacula\ApiToolkit\Services\Service
     */
    public function useLock(): static
    {
        if (!$this->getLockId()) {
            throw new RuntimeException('Lock key is not set');
        }

        $this->useLock = true;

        return $this;
    }

    /**
     * Instruct the service not to use a cache lock.
     *
     * @return \SineMacula\ApiToolkit\Services\Service
     */
    public function dontUseLock(): static
    {
        $this->useLock = false;

        return $this;
    }

    /**
     * Treat prepare() as a no-op on a service that does not define it, so it
     * can still be called directly.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     *
     * @throws \Error
     */
    public function __call(string $method, array $arguments)
    {
        if (strtolower($method) === 'prepare') {
            return null;
        }

        throw new Error(sprintf('Call to undefined method %s::%s()', static::class, $method));
    }

    /**
     * Method is triggered if the handle method ran successfully.
     *
     * @return void
     */
    public function success(): void {}

    /**
     * Method is triggered if the handle method failed.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void {}

    /**
     * Run the service.
     *
     * @return bool
     *
     * @throws Throwable
     */
    public function run(): bool
    {
        $handler  = $this->resolveHandler();
        $preparer = $this->resolveHook('prepare');

        if ($this->useLock) {
            $this->lock();
        }

        try {

            // Prepare the service for execution
            if ($preparer !== null) {
                $this->callHook($preparer);
            }

            // Execute the service handler
            $this->status = $this->useTransaction
                ? DB::transaction(fn () => $this->callHandler($handler), 3)
                : $this->callHandler($handler);

        } catch (Throwable $exception) {
            $this->failed($exception);
            throw $exception;
        } finally {
            $this->unlock();
        }

        // The success callback is run outside the try/catch block in order to
        // ensure it does not trigger the failed callback if exceptions are
        // thrown. Typically, if an exception is thrown in the success callback,
        // then this would be a bug considering all changes are committed at
        // this point
        $this->success();

        // Call any success callbacks defined on traits used by the service
        $this->callTraitsSuccessCallbacks();

        return $this->status;
    }

    /**
     * Initialize the service.
     *
     * @return void
     */
    protected function initialize(): void
    {
        $this->initializeTraits();
    }

    /**
     * Initialize each of the initializable traits on the service.
     *
     * @return void
     */
    protected static function initializeTraits(): void
    {
        $class = static::class;

        foreach (class_uses_recursive($class) as $trait) {

            $method = 'initialize' . class_basename($trait);

            if (method_exists($class, $method)) {
                forward_static_call([$class, $method]);
            }
        }
    }

    /**
     * Generate the key used for locking the task execution.
     *
     * @return string
     */
    protected function generateLockKey(): string
    {
        return sha1(static::class . '|' . $this->getLockId());
    }

    /**
     * Return the unique id to be used in the generation of the lock key.
     *
     * @return string
     */
    protected function getLockId(): string
    {
        return '';
    }

    /**
     * Resolve the service handler as a closure the container can call.
     *
     * @return \Closure
     *
     * @throws \LogicException
     */
    private function resolveHandler(): Closure
    {
        return $this->resolveHook('handle')
            ?? throw new LogicException(sprintf('Service [%s] must define a protected handle() method', static::class));
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
     * Call the service handler.
     *
     * @param  \Closure  $handler
     * @return bool
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \ReflectionException
     */
    private function callHandler(Closure $handler): bool
    {
        return $this->callHook($handler);
    }

    /**
     * Call a hook, resolving its parameters from the container when it
     * declares required parameters.
     *
     * @param  \Closure  $hook
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \ReflectionException
     */
    private function callHook(Closure $hook): mixed
    {
        if ((new ReflectionFunction($hook))->getNumberOfRequiredParameters() === 0) {
            return $hook();
        }

        // Container::call() leaves the service on its build stack if the hook throws
        return BoundMethod::call(Container::getInstance(), $hook);
    }

    /**
     * Call any success callbacks that are defined on any traits used by the
     * service.
     *
     * @return void
     */
    private function callTraitsSuccessCallbacks(): void
    {
        $traits = class_uses_recursive(static::class);

        foreach ($traits as $trait) {

            $method = Str::camel(class_basename($trait) . 'Success');

            if (method_exists($this, $method)) {
                $this->{$method}();
            }
        }
    }
}
