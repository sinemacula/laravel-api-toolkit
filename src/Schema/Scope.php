<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Declares a model's local scope as a predicate clients may invoke by name.
 *
 * The scope's method signature is the argument contract: each declared argument
 * reaches the parameter of the same name.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @implements \Illuminate\Contracts\Support\Arrayable<string, array<string, mixed>>
 */
final class Scope implements Arrayable
{
    /** @var string The prefix every scope's raw schema key carries */
    public const string KEY_PREFIX = '__scope__:';

    /** @var string The grammar of the name a client invokes a scope by */
    public const string NAME_PATTERN = '/\A[A-Za-z][A-Za-z0-9_]*\z/';

    /** @var array<string, mixed> The validation rules for the scope's arguments */
    private array $parameters = [];

    /** @var string|null What the scope matches, for the documentation */
    private ?string $description = null;

    /** @var array<int, callable(\Illuminate\Http\Request|null): bool> Caller guards for conditional availability */
    private array $callerGuards = [];

    /**
     * Prevent direct instantiation.
     *
     * @param  string  $scope
     * @param  string  $name
     */
    private function __construct(

        /** The model's local scope */
        private readonly string $scope,

        /** The name a client invokes the scope by */
        private readonly string $name,
    ) {}

    /**
     * Declare the given local scope, invoked by its own name or the alias.
     *
     * @param  string  $scope
     * @param  string|null  $alias
     * @return self
     *
     * @throws \InvalidArgumentException
     */
    public static function of(string $scope, ?string $alias = null): self
    {
        $name = $alias ?? $scope;

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Scope name "%s" must be a letter followed by letters, digits or underscores', $name));
        }

        return new self($scope, $name);
    }

    /**
     * Return the arguments the given rules declare: the distinct first segment
     * of each rule key.
     *
     * @param  array<array-key, mixed>  $rules
     * @return list<string>
     */
    public static function argumentsOf(array $rules): array
    {
        $arguments = [];

        foreach (array_keys($rules) as $key) {
            $arguments[explode('.', (string) $key, 2)[0]] = true;
        }

        return array_map(strval(...), array_keys($arguments));
    }

    /**
     * Declare the scope's arguments as validation rules keyed as in a form
     * request.
     *
     * @param  array<string, mixed>  $rules
     * @return self
     */
    public function parameters(array $rules): self
    {
        $this->parameters = $rules;

        return $this;
    }

    /**
     * Describe what the scope matches.
     *
     * @param  string  $description
     * @return self
     */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Add a caller guard; returning false hides the scope from that request.
     *
     * @param  callable(\Illuminate\Http\Request|null): bool  $guard
     * @return self
     */
    public function visibleTo(callable $guard): self
    {
        $this->callerGuards[] = $guard;

        return $this;
    }

    /**
     * Convert this definition to a normalized array.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            self::KEY_PREFIX . $this->name => array_filter([
                'scope'         => $this->scope,
                'key'           => $this->name,
                'parameters'    => $this->parameters,
                'description'   => $this->description,
                'caller_guards' => $this->callerGuards ?: null,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }
}
