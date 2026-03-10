<?php

namespace SineMacula\ApiToolkit\Services\Validation;

/**
 * Immutable value object representing a single schema validation defect.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SchemaValidationError
{
    /**
     * Create a new schema validation error.
     *
     * @param  string  $resourceClass
     * @param  string  $fieldKey
     * @param  string  $defect
     */
    public function __construct(

        /** The fully qualified class name of the resource. */
        public string $resourceClass,

        /** The schema field key where the defect was found. */
        public string $fieldKey,

        /** A human-readable description of the defect. */
        public string $defect,

    ) {}

    /**
     * Return a formatted string representation of the error.
     *
     * @return string
     */
    public function __toString(): string
    {
        return sprintf('[%s] Field "%s": %s', $this->resourceClass, $this->fieldKey, $this->defect);
    }
}
