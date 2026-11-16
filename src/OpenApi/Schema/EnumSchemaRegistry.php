<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;

/**
 * Collects the enum classes a document references through a $ref.
 *
 * Reset per document so an enum referenced by one audience does not leak into
 * another.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class EnumSchemaRegistry
{
    /** The path prefix under which component schemas are referenced */
    private const string SCHEMA_REF_PREFIX = '#/components/schemas/';

    /** @var array<class-string, true> */
    private array $enums = [];

    /**
     * Register an enum class as referenced by the document.
     *
     * @param  class-string  $enum
     * @return void
     */
    public function register(string $enum): void
    {
        $this->enums[$enum] = true;
    }

    /**
     * Register an enum class and return the $ref to its component schema.
     *
     * @param  class-string  $enum
     * @return string
     */
    public function reference(string $enum): string
    {
        $this->register($enum);

        return self::SCHEMA_REF_PREFIX . SchemaComponentName::fromClass($enum);
    }

    /**
     * List the distinct enum classes registered so far.
     *
     * @return list<class-string>
     */
    public function classes(): array
    {
        return array_keys($this->enums);
    }

    /**
     * Clear the collected set so the next document starts empty.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->enums = [];
    }
}
