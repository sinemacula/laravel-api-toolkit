<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;

/**
 * Registry for operator token-to-handler mappings.
 *
 * Stores and resolves filter operator handlers keyed by their token string.
 * Supports registration, override, removal, and transparent closure wrapping.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class OperatorRegistry
{
    /** @var array<string, \SineMacula\ApiToolkit\Contracts\FilterOperator> */
    private array $operators = [];

    /**
     * Register a handler for the given operator token.
     *
     * Throws if the token is already registered. Use override() to replace
     * an existing handler.
     *
     * @param  string  $token
     * @param  \Closure|\SineMacula\ApiToolkit\Contracts\FilterOperator  $operator
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    public function register(string $token, \Closure|FilterOperator $operator): void // @phpstan-ignore missingType.callable
    {
        if (array_key_exists($token, $this->operators)) {
            throw new \InvalidArgumentException("Operator \"{$token}\" is already registered. Use override() to replace it.");
        }

        $this->operators[$token] = $operator instanceof \Closure
            ? $this->wrapClosure($operator)
            : $operator;
    }

    /**
     * Replace the handler for the given operator token unconditionally.
     *
     * If the token is not currently registered, this behaves identically
     * to register().
     *
     * @param  string  $token
     * @param  \Closure|\SineMacula\ApiToolkit\Contracts\FilterOperator  $operator
     * @return void
     */
    public function override(string $token, \Closure|FilterOperator $operator): void // @phpstan-ignore missingType.callable
    {
        $this->operators[$token] = $operator instanceof \Closure
            ? $this->wrapClosure($operator)
            : $operator;
    }

    /**
     * Remove the handler for the given operator token.
     *
     * Removing a token that does not exist is a no-op.
     *
     * @param  string  $token
     * @return void
     */
    public function remove(string $token): void
    {
        unset($this->operators[$token]);
    }

    /**
     * Resolve the handler for the given operator token.
     *
     * @param  string  $token
     * @return \SineMacula\ApiToolkit\Contracts\FilterOperator|null
     */
    public function resolve(string $token): ?FilterOperator
    {
        return $this->operators[$token] ?? null;
    }

    /**
     * Determine whether a handler is registered for the given token.
     *
     * @param  string  $token
     * @return bool
     */
    public function has(string $token): bool
    {
        return array_key_exists($token, $this->operators);
    }

    /**
     * Wrap a closure in an anonymous FilterOperator implementation.
     *
     * @param  \Closure  $closure
     * @return \SineMacula\ApiToolkit\Contracts\FilterOperator
     */
    private function wrapClosure(\Closure $closure): FilterOperator // @phpstan-ignore missingType.callable
    {
        /**
         * Closure-backed FilterOperator adapter.
         *
         * @author      Ben Carey <bdmc@sinemacula.co.uk>
         * @copyright   2026 Sine Macula Limited.
         */
        return new class ($closure) implements FilterOperator {
            /**
             * Constructor.
             *
             * @param  \Closure  $closure
             * @return void
             */
            public function __construct(// @phpstan-ignore missingType.callable

                /** @var \Closure */
                private readonly \Closure $closure,

            ) {}

            /**
             * Apply the operator constraint to the query builder.
             *
             * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
             * @param  string  $column
             * @param  mixed  $value
             * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
             * @return void
             */
            #[\Override]
            public function apply(Builder $query, string $column, mixed $value, FilterContext $context): void
            {
                ($this->closure)($query, $column, $value, $context);
            }
        };
    }
}
