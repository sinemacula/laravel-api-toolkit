<?php

namespace SineMacula\ApiToolkit\Traits;

use SineMacula\ApiToolkit\Enums\FieldOrderingStrategy;

/**
 * Provides consistent field-ordering utilities for API resources.
 *
 * @author      Michael Stivala <michael.stivala@verifast.com>
 * @copyright   2025 Verifast, Inc.
 */
trait OrdersFields
{
    /**
     * The field-ordering strategy to use.
     *
     * @var FieldOrderingStrategy
     */
    protected FieldOrderingStrategy $fieldOrderingStrategy = FieldOrderingStrategy::DEFAULT;

    /**
     * Resolves and returns the fields based on the API query or defaults.
     *
     * @return array<int, string>
     */
    abstract public static function resolveFields(): array;

    /**
     * Order the resolved fields based on the configured strategy.
     *
     * @param  array  $data
     * @return array
     */
    protected function orderResolvedFields(array $data): array
    {
        return match ($this->fieldOrderingStrategy) {
            FieldOrderingStrategy::DEFAULT             => $this->orderByDefault($data),
            FieldOrderingStrategy::BY_REQUESTED_FIELDS => $this->orderByRequestedFields($data),
        };
    }

    /**
     * Order resolved fields into a predictable output structure.
     *
     * Rules:
     *  - "_type" always first
     *  - "id" always second
     *  - any timestamps (*_at) always last
     *  - everything else alphabetized in between
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function orderByDefault(array $data): array
    {
        $weight = static function (string $key): array {

            if ($key === '_type') {
                return [0, ''];
            }

            if ($key === 'id') {
                return [1, ''];
            }

            $is_timestamp = str_ends_with($key, '_at');

            return [$is_timestamp ? 3 : 2, $key];
        };

        uksort($data, static function (string $a, string $b) use ($weight): int {

            [$wa, $ka] = $weight($a);
            [$wb, $kb] = $weight($b);

            return $wa <=> $wb ?: strcmp($ka, $kb);
        });

        return $data;
    }

    /**
     * Order resolved fields in the order they were requested.
     *
     * @param  array  $data
     * @return array
     */
    protected function orderByRequestedFields(array $data): array
    {
        $requested_fields = static::resolveFields();

        if (empty($requested_fields)) {
            return $data;
        }

        $ordered = [];

        foreach ($requested_fields as $field) {
            if (array_key_exists($field, $data)) {
                $ordered[$field] = $data[$field];
            }
        }

        return $ordered + $data;
    }
}
