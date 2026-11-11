<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Validation\Rules;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use SineMacula\ApiToolkit\Support\PureEnumName;

/**
 * Validates a value against the lowercase case names of a pure enum.
 *
 * Laravel's enum rule needs tryFrom(), which only backed enums have.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PureEnum implements ValidationRule
{
    /** @var array<array-key, mixed> The cases that should be considered valid */
    private array $only = [];

    /** @var array<array-key, mixed> The cases that should be considered invalid */
    private array $except = [];

    /**
     * Create a new rule instance.
     *
     * @param  class-string<\UnitEnum>  $enum
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(

        /** The pure enum whose cases the value must name */
        public readonly string $enum,
    ) {
        if (!enum_exists($enum)) {
            throw new \InvalidArgumentException(sprintf('%s is not an enum', $enum));
        }

        if (is_a($enum, \BackedEnum::class, true)) {
            throw new \InvalidArgumentException(sprintf('%s is a backed enum; validate it with Rule::enum() instead', $enum));
        }
    }

    /**
     * Specify the cases that should be considered valid.
     *
     * @param  array<array-key, \UnitEnum>|\Illuminate\Contracts\Support\Arrayable<array-key, \UnitEnum>|\UnitEnum  $values
     * @return self
     */
    public function only(mixed $values): self
    {
        $this->only = $values instanceof Arrayable ? $values->toArray() : Arr::wrap($values);

        return $this;
    }

    /**
     * Specify the cases that should be considered invalid.
     *
     * @param  array<array-key, \UnitEnum>|\Illuminate\Contracts\Support\Arrayable<array-key, \UnitEnum>|\UnitEnum  $values
     * @return self
     */
    public function except(mixed $values): self
    {
        $this->except = $values instanceof Arrayable ? $values->toArray() : Arr::wrap($values);

        return $this;
    }

    /**
     * Return the wire names of the cases the rule accepts.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];

        foreach ($this->enum::cases() as $case) {
            if (!$this->isDesirable($case)) {
                continue;
            }

            $names[] = PureEnumName::of($case);
        }

        return $names;
    }

    /**
     * Run the validation rule.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     * @return void
     */
    #[\Override]
    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        $case = is_string($value) ? PureEnumName::tryFrom($this->enum, $value) : null;

        if ($case !== null && $this->isDesirable($case)) {
            return;
        }

        $fail('validation.enum')->translate();
    }

    /**
     * Determine whether the case is admitted by the only and except lists.
     *
     * @param  \UnitEnum  $case
     * @return bool
     */
    private function isDesirable(\UnitEnum $case): bool
    {
        return match (true) {
            $this->only   !== [] => in_array($case, $this->only, true),
            $this->except !== [] => !in_array($case, $this->except, true),
            default              => true,
        };
    }
}
