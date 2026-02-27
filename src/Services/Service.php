<?php

namespace SineMacula\ApiToolkit\Services;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInterface;
use SineMacula\ApiToolkit\Traits\Lockable;

/**
 * Base API service.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
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

    /**
     * Constructor.
     *
     * @param  array<int|string, mixed>|\Illuminate\Support\Collection<int|string, mixed>|\stdClass  $payload
     */
    public function __construct(

        /** The service input payload */
        protected array|Collection|\stdClass $payload = [],

    ) {
        $this->payload = (!$payload instanceof Collection && !$payload instanceof \stdClass) ? collect($payload) : $payload;

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
     * @return static
     *
     * @throws \RuntimeException
     */
    public function useLock(): static
    {
        if (!$this->getLockId()) {
            throw new \RuntimeException('Lock key is not set');
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
     * Prepare the service for execution.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function prepare(): void {}

    /**
     * Method is triggered if the handle method ran successfully.
     *
     * @return void
     */
    public function success(): void {}

    /**
     * Method is triggered if the handle method failed.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception): void {}

    /**
     * Run the service.
     *
     * @return bool
     *
     * @throws \Throwable
     */
    public function run(): bool
    {
        if ($this->useLock) {
            $this->lock();
        }

        try {

            // Prepare the service for execution
            $this->prepare();

            // Execute the service handler
            $result = $this->useTransaction
                ? DB::transaction(fn () => $this->handle(), 3)
                : $this->handle();

            $this->status = is_bool($result) ? $result : (bool) $result;

        } catch (\Throwable $exception) {
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

        return (bool) $this->status;
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
                // @phpstan-ignore staticMethod.dynamicCall (method name is dynamically resolved from trait names)
                forward_static_call([$class, $method]);
            }
        }
    }

    /**
     * Handles the main execution of the service.
     *
     * @return bool
     */
    abstract protected function handle(): bool;

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
                // @phpstan-ignore method.dynamicName (method name is dynamically resolved from trait names)
                $this->{$method}();
            }
        }
    }
}
