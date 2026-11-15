<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Derives a `oneOf` discriminator from the properties its members pin.
 *
 * A member pins a property it requires to a string `const`. The discriminator
 * uses the first property the first member pins that every member pins to a
 * value no other member shares.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class DiscriminatorBuilder
{
    /**
     * List the required top-level properties pinned to a string `const`, in
     * property order.
     *
     * @param  array<array-key, mixed>  $schema
     * @return array<string, string>
     */
    public function pinnedValues(array $schema): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required   = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $pins       = [];

        foreach ($properties as $name => $property) {

            $name = (string) $name;

            if (!in_array($name, $required, true) || !is_array($property) || !is_string($property['const'] ?? null)) {
                continue;
            }

            $pins[$name] = $property['const'];
        }

        return $pins;
    }

    /**
     * Build the discriminator for the alternatives, or null when no property
     * tells every member apart.
     *
     * @param  array<string, array<string, string>>  $alternatives
     * @return array{propertyName: string, mapping: array<string, string>}|null
     */
    public function build(array $alternatives): ?array
    {
        foreach (array_map(strval(...), array_keys(reset($alternatives) ?: [])) as $candidate) {

            $mapping = $this->mapping($alternatives, $candidate);

            if ($mapping !== null) {
                return ['propertyName' => $candidate, 'mapping' => $mapping];
            }
        }

        return null;
    }

    /**
     * Map each member's value of the property to its reference, or null when a
     * member does not pin it or two members share a value.
     *
     * @param  array<string, array<string, string>>  $alternatives
     * @param  string  $property
     * @return array<string, string>|null
     */
    private function mapping(array $alternatives, string $property): ?array
    {
        $mapping = [];

        foreach ($alternatives as $reference => $pins) {

            $value = $pins[$property] ?? null;

            if ($value === null || isset($mapping[$value])) {
                return null;
            }

            $mapping[$value] = $reference;
        }

        return $mapping;
    }
}
