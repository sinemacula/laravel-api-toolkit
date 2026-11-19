<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;

/**
 * Registry of filter operator handlers and the capabilities admitting each.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class OperatorRegistry
{
    /** @var string A dollar sign, a letter, then letters, digits or underscores */
    private const string TOKEN_PATTERN = '/\A\$[A-Za-z][A-Za-z0-9_]*\z/';

    /** @var array<string, array{handler: \SineMacula\ApiToolkit\Contracts\FilterOperator, capabilities: array<string, true>}> */
    private array $operators = [];

    /**
     * Register a handler for the given token, admitted on columns declaring any
     * of the given capabilities.
     *
     * @param  string  $token
     * @param  \Closure|\SineMacula\ApiToolkit\Contracts\FilterOperator  $operator
     * @param  \SineMacula\ApiToolkit\Enums\Capability  $capability
     * @param  \SineMacula\ApiToolkit\Enums\Capability  ...$capabilities
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    public function register(string $token, \Closure|FilterOperator $operator, Capability $capability, Capability ...$capabilities): void // @phpstan-ignore missingType.callable
    {
        if (in_array($token, FilterApplier::STRUCTURAL_OPERATORS, true)) {
            throw new \InvalidArgumentException("Operator \"{$token}\" is a structural filter token and cannot be registered.");
        }

        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new \InvalidArgumentException("Operator \"{$token}\" must be a dollar sign followed by a letter, then letters, digits or underscores.");
        }

        if (array_key_exists($token, $this->operators)) {
            throw new \InvalidArgumentException("Operator \"{$token}\" is already registered. Use override() to replace it.");
        }

        $admitted = array_map(static fn (Capability $case): string => $case->value, [$capability, ...$capabilities]);

        $this->operators[$token] = [
            'handler'      => $this->toHandler($operator),
            'capabilities' => array_fill_keys($admitted, true),
        ];
    }

    /**
     * Replace the handler of a registered token, keeping its capabilities.
     *
     * @param  string  $token
     * @param  \Closure|\SineMacula\ApiToolkit\Contracts\FilterOperator  $operator
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    public function override(string $token, \Closure|FilterOperator $operator): void // @phpstan-ignore missingType.callable
    {
        if (!array_key_exists($token, $this->operators)) {
            throw new \InvalidArgumentException("Operator \"{$token}\" is not registered. Use register() to add it.");
        }

        $this->operators[$token]['handler'] = $this->toHandler($operator);
    }

    /**
     * Remove the handler and capabilities of the given token.
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
        return $this->operators[$token]['handler'] ?? null;
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
     * Determine whether a column declaring the capability answers the token.
     *
     * @param  string  $token
     * @param  \SineMacula\ApiToolkit\Enums\Capability  $capability
     * @return bool
     */
    public function admits(string $token, Capability $capability): bool
    {
        return isset($this->operators[$token]['capabilities'][$capability->value]);
    }

    /**
     * Return all registered operator tokens.
     *
     * @return array<int, string>
     */
    public function tokens(): array
    {
        return array_keys($this->operators);
    }

    /**
     * Return the tokens a column declaring the capability answers, in
     * registration order.
     *
     * @param  \SineMacula\ApiToolkit\Enums\Capability  $capability
     * @return array<int, string>
     */
    public function tokensFor(Capability $capability): array
    {
        return array_values(array_filter(
            $this->tokens(),
            fn (string $token): bool => $this->admits($token, $capability),
        ));
    }

    /**
     * Return the given handler, wrapping a closure in a FilterOperator.
     *
     * @param  \Closure|\SineMacula\ApiToolkit\Contracts\FilterOperator  $operator
     * @return \SineMacula\ApiToolkit\Contracts\FilterOperator
     */
    private function toHandler(\Closure|FilterOperator $operator): FilterOperator // @phpstan-ignore missingType.callable
    {
        if ($operator instanceof FilterOperator) {
            return $operator;
        }

        /**
         * Closure-backed FilterOperator adapter.
         *
         * @author      Ben Carey <bdmc@sinemacula.co.uk>
         * @copyright   2026 Sine Macula Limited.
         */
        return new class ($operator) implements FilterOperator {
            /**
             * Constructor.
             *
             * @param  \Closure  $closure
             * @return void
             *
             * @phpstan-ignore missingType.callable
             */
            public function __construct(

                /** @var \Closure */
                private readonly \Closure $closure,
            ) {}

            /**
             * Apply the operator constraint to the query builder.
             *
             * @template TModel of \Illuminate\Database\Eloquent\Model
             *
             * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
             * @param  string  $column
             * @param  mixed  $value
             * @return void
             */
            #[\Override]
            public function apply(Builder $query, string $column, mixed $value): void
            {
                ($this->closure)($query, $column, $value);
            }
        };
    }
}
