<?php

namespace SineMacula\ApiToolkit\Validation\Rules;

use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Enum;

/**
 * Pure enum validation rule.
 *
 * This rule will validate a value against a non-backed enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
class PureEnum extends Enum
{
    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value): bool
    {
        if (is_null($value) || !enum_exists($this->type)) {
            return false;
        }

        $value = strtolower($value);

        foreach ($this->type::cases() as $case) {
            if (strtolower($case->name) === $value && $this->isDesirable($case)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Specify the cases that should be considered valid.
     *
     * @param  \UnitEnum[]|\UnitEnum  $values
     * @return static
     */
    public function only($values): static
    {
        $this->only = array_map(function ($value) {
            return strtolower($value->name);
        }, Arr::wrap($values));

        return $this;
    }

    /**
     * Specify the cases that should be considered invalid.
     *
     * @param  \UnitEnum[]|\UnitEnum  $values
     * @return static
     */
    public function except($values): static
    {
        $this->except = array_map(function ($value) {
            return strtolower($value->name);
        }, Arr::wrap($values));

        return $this;
    }

    /**
     * Determine if the given case is a valid case based on the only / except values.
     *
     * @param  mixed  $value
     * @return bool
     */
    protected function isDesirable($value): bool
    {
        $value = strtolower($value->name);

        return match (true) {
            !empty($this->only)   => in_array($value, $this->only, true),
            !empty($this->except) => !in_array($value, $this->except, true),
            default               => true,
        };
    }
}
