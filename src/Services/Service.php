<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services;

use Illuminate\Support\Facades\App;
use SineMacula\ApiToolkit\Concerns\Lockable;
use SineMacula\ApiToolkit\Contracts\LockKeyProvider;
use SineMacula\ApiToolkit\Services\Actors\AnonymousActor;
use SineMacula\ApiToolkit\Services\Contracts\Actor;
use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\Jobs\ServiceJob;

/**
 * Base class for a service action with a typed input and an explicit actor.
 *
 * One instance must not run concurrently.
 *
 * @template TInput of \SineMacula\ApiToolkit\Services\Contracts\ServiceInput
 * @template TOutput
 *
 * @phpstan-import-type ServiceHooks from \SineMacula\ApiToolkit\Services\ServiceRunner
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class Service implements LockKeyProvider
{
    use Lockable;

    /** @var bool Wrap prepare()+handle() in a database transaction */
    protected bool $transactional = true;

    /** @var int Transaction retry attempts */
    protected int $transactionAttempts = 3;

    /** @var bool Whether this service acquires a cache lock */
    protected bool $lockable = false;

    /** @var \SineMacula\ApiToolkit\Services\Contracts\Actor|null Explicit causer; null until set by by() or withContext() */
    private ?Actor $actor = null;

    /** @var \SineMacula\ApiToolkit\Services\ServiceContext|null Fully-built execution context; null until set by withContext() */
    private ?ServiceContext $attachedContext = null;

    /** @var \SineMacula\ApiToolkit\Services\ServiceContext|null The context of the run in progress; null outside a run */
    private ?ServiceContext $runContext = null;

    /**
     * Constructor.
     *
     * @param  TInput  $input
     */
    public function __construct(

        /** @var TInput The typed input for this service action */
        protected readonly ServiceInput $input,
    ) {}

    /**
     * Resolve a new service instance from the container with the given input.
     *
     * @param  \SineMacula\ApiToolkit\Services\Contracts\ServiceInput  $input
     * @return static
     */
    public static function make(ServiceInput $input): static
    {
        $class    = static::class;
        $instance = App::make($class, ['input' => $input]);
        assert($instance instanceof static);

        return $instance;
    }

    /**
     * Record the explicit causer for this invocation.
     *
     * @param  \SineMacula\ApiToolkit\Services\Contracts\Actor  $actor
     * @return static
     */
    public function by(Actor $actor): static
    {
        $this->actor = $actor;

        return $this;
    }

    /**
     * Attach a prebuilt execution context, taking the actor from it.
     *
     * A later by() does not change the attached context's actor.
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @return static
     */
    public function withContext(ServiceContext $context): static
    {
        $this->attachedContext = $context;
        $this->actor           = $context->actor;

        return $this;
    }

    /**
     * Return the actor for this invocation.
     *
     * During a run this is the run context's actor; otherwise the explicit one,
     * or an AnonymousActor. Never reads ambient auth state.
     *
     * @return \SineMacula\ApiToolkit\Services\Contracts\Actor
     */
    public function actor(): Actor
    {
        return $this->runContext->actor ?? $this->actor ?? new AnonymousActor;
    }

    /**
     * Execute the service synchronously and return a total result.
     *
     * Never throws for business failures; the failure is on the result.
     *
     * @return \SineMacula\ApiToolkit\Services\ServiceResult<TOutput>
     */
    final public function run(): ServiceResult
    {
        $context = $this->attachedContext ?? ServiceContext::for($this->actor(), ServiceSource::INTERNAL);
        $class   = ServiceRunner::class;
        $runner  = App::make($class);
        assert($runner instanceof ServiceRunner);

        return $runner->run($this, $context);
    }

    /**
     * Execute the service synchronously and return its output, or throw.
     *
     * Throws the captured exception, or a ServiceFailedException when there is
     * none.
     *
     * @return TOutput
     *
     * @throws \Throwable
     */
    final public function runOrFail(): mixed
    {
        return $this->run()->throw()->output();
    }

    /**
     * Push the service onto the queue via ServiceJob.
     *
     * @return mixed
     */
    final public function dispatch(): mixed
    {
        $context = $this->attachedContext ?? ServiceContext::for($this->actor());

        return ServiceJob::dispatch(static::class, $this->input, $context);
    }

    /**
     * Generate the cache-lock key for this service.
     *
     * @return string
     */
    #[\Override]
    public function getLockKey(): string
    {
        return sha1(static::class . '|' . $this->lockId());
    }

    /**
     * Bind the context of the run in progress, returning the one it replaces.
     *
     * @internal
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext|null  $context
     * @return \SineMacula\ApiToolkit\Services\ServiceContext|null
     */
    final public function bindRunContext(?ServiceContext $context): ?ServiceContext
    {
        $previous         = $this->runContext;
        $this->runContext = $context;

        return $previous;
    }

    /**
     * Return the lifecycle hooks and configuration for this service.
     *
     * The closures are created here so they can call the protected hooks.
     *
     * @internal
     *
     * @return ServiceHooks
     */
    final public function serviceHooks(): array
    {
        return [
            'authorize' => function (): void {
                $this->authorize();
            },
            'validate' => function (): void {
                $this->validate();
            },
            'prepare' => function (): void {
                $this->prepare();
            },
            'handle'      => fn (): mixed => $this->handle(),
            'afterCommit' => function (mixed $output): void {
                $this->afterCommit($output);
            },
            'onFailure' => function (\Throwable $exception): void {
                $this->onFailure($exception);
            },
            'concerns'            => $this->concerns(),
            'lockId'              => $this->lockId(),
            'transactional'       => $this->transactional,
            'transactionAttempts' => $this->transactionAttempts,
            'lockable'            => $this->lockable,
            // Reaches event listeners verbatim; see ServiceInput::toArray()
            'inputSummary' => $this->input->toArray(),
        ];
    }

    /**
     * Return the execution context of the run in progress.
     *
     * Available in every lifecycle hook, concerns() and lockId(); work deferred
     * beyond the run must capture it first.
     *
     * @return \SineMacula\ApiToolkit\Services\ServiceContext
     *
     * @throws \LogicException
     */
    final protected function context(): ServiceContext
    {
        return $this->runContext ?? throw new \LogicException('The service context is only available while the service is running.');
    }

    /**
     * Authorize the current actor to perform this action.
     *
     * Runs before the lock and transaction. Throw AuthorizationException to
     * deny access. Default: allow.
     *
     * @return void
     */
    protected function authorize(): void {}

    /**
     * Validate the input data for this action.
     *
     * Runs before the lock and transaction. Throw ValidationException to signal
     * invalid input. Default: pass.
     *
     * @return void
     */
    protected function validate(): void {}

    /**
     * Perform pre-handle setup inside the transaction.
     *
     * Default: no-op.
     *
     * @return void
     */
    protected function prepare(): void {}

    /**
     * Execute the core domain action.
     *
     * Runs inside the transaction. Signal failure only by throwing; the return
     * value is the typed output threaded through ServiceResult.
     *
     * @return TOutput
     */
    abstract protected function handle(): mixed;

    /**
     * React to a successful outcome after the transaction has committed.
     *
     * Exceptions are reported and recorded as side-effect errors without
     * failing the committed outcome. Default: no-op.
     *
     * @param  TOutput  $output
     * @return void
     */
    protected function afterCommit(mixed $output): void {}

    /**
     * React to a failed outcome after the transaction has rolled back.
     *
     * Runs after lock release; exceptions are caught and reported. Default:
     * no-op.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    protected function onFailure(\Throwable $exception): void {}

    /**
     * Return the ordered list of custom concern classes.
     *
     * Concerns wrap prepare() and handle() inside the transaction, in order.
     *
     * @return array<int, class-string<\SineMacula\ApiToolkit\Services\Contracts\ServiceConcern>>
     */
    protected function concerns(): array
    {
        return [];
    }

    /**
     * Return the unique lock identity for this invocation.
     *
     * Must be non-empty when $lockable is true.
     *
     * @return string
     */
    protected function lockId(): string
    {
        return '';
    }
}
