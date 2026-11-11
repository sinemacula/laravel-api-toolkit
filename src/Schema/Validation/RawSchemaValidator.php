<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation;

use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;

/**
 * Refuses raw schema definitions the compiled definitions cannot carry.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class RawSchemaValidator
{
    /** @var array<string, string> Callable list keys mapped to the label reported for a defect */
    private const array CALLABLE_LISTS = ['guards' => 'Guard', 'transformers' => 'Transformer'];

    /**
     * Assert that every definition in the raw schema can be compiled.
     *
     * @param  array<string, array<string, mixed>>  $rawSchema
     * @param  string  $resourceClass
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public static function assertValid(array $rawSchema, string $resourceClass): void
    {
        $errors = [];

        foreach ($rawSchema as $key => $definition) {
            foreach (self::collectDefects($definition) as $defect) {
                $errors[] = new SchemaValidationError(
                    resourceClass: $resourceClass,
                    fieldKey: $key,
                    defect: $defect,
                );
            }
        }

        if ($errors !== []) {
            throw new InvalidSchemaException($errors);
        }
    }

    /**
     * Collect the defects in a raw definition's constraint and callable lists.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private static function collectDefects(array $definition): array
    {
        $defects    = [];
        $constraint = $definition['constraint'] ?? null;

        if ($constraint !== null && !$constraint instanceof \Closure) {
            $defects[] = 'Constraint must be a Closure';
        }

        foreach (self::CALLABLE_LISTS as $list => $label) {
            array_push($defects, ...self::collectCallableDefects($definition[$list] ?? [], $list, $label));
        }

        return $defects;
    }

    /**
     * Collect the defects in a single raw guard or transformer list.
     *
     * @param  mixed  $callables
     * @param  string  $list
     * @param  string  $label
     * @return list<string>
     */
    private static function collectCallableDefects(mixed $callables, string $list, string $label): array
    {
        if (!is_array($callables)) {
            return [sprintf('%s must be a list of callables', ucfirst($list))];
        }

        $defects = [];

        foreach (array_values($callables) as $index => $callable) {

            if (is_callable($callable)) {
                continue;
            }

            $defects[] = sprintf('%s at index %d is not callable', $label, $index);
        }

        return $defects;
    }
}
