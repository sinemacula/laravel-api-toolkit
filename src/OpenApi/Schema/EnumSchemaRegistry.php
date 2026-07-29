<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Collects the enum classes a document references through a $ref.
 *
 * An enum-typed field documents as a $ref to a named component rather than an
 * inline enum, on both the request and response sides. The resolvers register
 * each enum as they emit its reference, and the assembler reads the collected
 * set back to build one component per enum. Registration is idempotent and
 * keyed by class, so an enum referenced from many fields is collected once. The
 * set is reset at the start of each document so an enum referenced by one
 * audience does not leak into another.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class EnumSchemaRegistry
{
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
