<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Concerns;

use SineMacula\ApiToolkit\Enums\FieldOrderingStrategy;

/**
 * Provides consistent field-ordering utilities for API resources.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait OrdersFields
{
    /**
     * The field-ordering strategy to use.
     *
     * @var \SineMacula\ApiToolkit\Enums\FieldOrderingStrategy
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
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

            $isTimestamp = str_ends_with($key, '_at');

            return [$isTimestamp ? 3 : 2, $key];
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function orderByRequestedFields(array $data): array
    {
        $requestedFields = static::resolveFields();

        if (empty($requestedFields)) {
            return $data;
        }

        $ordered = [];

        foreach ($requestedFields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $ordered[$field] = $data[$field];
        }

        return $ordered + $data;
    }
}
