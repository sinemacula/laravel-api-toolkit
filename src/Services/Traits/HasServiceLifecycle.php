<?php

namespace SineMacula\ApiToolkit\Services\Traits;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * The payload, status, transaction, lock and hook lifecycle shared by the base
 * services.
 *
 * @template TPayload of array<array-key, mixed>|\Illuminate\Support\Collection<array-key, mixed>|\stdClass
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait HasServiceLifecycle
{
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
     * Transactions are only supported on MySQL databases running the InnoDB
     * engine.
     *
     * @return static
     */
    public function useTransaction(): static
    {
        $this->useTransaction = true;

        return $this;
    }

    /**
     * Instruct the service not to use database transactions.
     *
     * @return static
     */
    public function dontUseTransaction(): static
    {
        $this->useTransaction = false;

        return $this;
    }

    /**
     * Instruct the service to use a cache lock.
     *
     * @SuppressWarnings("php:S112")
     *
     * @return static
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
     * @return static
     */
    public function dontUseLock(): static
    {
        $this->useLock = false;

        return $this;
    }

    /**
     * Method is triggered if the handle method ran successfully.
     *
     * @return void
     */
    public function success(): void
    {
    }

    /**
     * Method is triggered if the handle method failed.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
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

        foreach (array_diff(class_uses_recursive($class), [__TRAIT__]) as $trait) {

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
     * Run the service lifecycle around the given prepare and handle steps.
     *
     * @param  \Closure|null  $prepare
     * @param  \Closure  $handle
     * @return bool
     *
     * @throws Throwable
     */
    private function runLifecycle(?Closure $prepare, Closure $handle): bool
    {
        if ($this->useLock) {
            $this->lock();
        }

        try {

            // Prepare the service for execution
            if ($prepare !== null) {
                $prepare();
            }

            // Execute the service handler
            $this->status = $this->useTransaction
                ? DB::transaction(fn () => $handle(), 3)
                : $handle();

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
     * Call any success callbacks that are defined on any traits used by the
     * service.
     *
     * @return void
     */
    private function callTraitsSuccessCallbacks(): void
    {
        $traits = array_diff(class_uses_recursive(static::class), [__TRAIT__]);

        foreach ($traits as $trait) {

            $method = Str::camel(class_basename($trait) . 'Success');

            if (method_exists($this, $method)) {
                $this->{$method}();
            }
        }
    }
}
