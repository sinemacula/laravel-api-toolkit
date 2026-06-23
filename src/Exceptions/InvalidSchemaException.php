<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

use SineMacula\ApiToolkit\Services\Validation\SchemaValidationError;

/**
 * Exception thrown when schema validation fails at boot time.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class InvalidSchemaException extends \RuntimeException
{
    /**
     * Create a new invalid schema exception.
     *
     * @param  array<int, \SineMacula\ApiToolkit\Services\Validation\SchemaValidationError>  $errors
     */
    public function __construct(

        /** The validation errors that describe why the schema is invalid. */
        private array $errors

    ) {

        parent::__construct($this->buildMessage());
    }

    /**
     * Return the array of validation errors.
     *
     * @return array<int, \SineMacula\ApiToolkit\Services\Validation\SchemaValidationError>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Build the exception message from the collected errors.
     *
     * @return string
     */
    private function buildMessage(): string
    {
        $lines = array_map(
            static fn (SchemaValidationError $error): string => '  - ' . (string) $error,
            $this->errors,
        );

        return 'Schema validation failed with ' . count($this->errors) . " error(s):\n" . implode("\n", $lines);
    }
}
