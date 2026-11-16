<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Collects the DefinesSchema classes a document references through a $ref.
 *
 * Reset per document so a shape referenced by one audience does not leak into
 * another.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DefinedSchemaRegistry
{
    /** @var array<class-string<\SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema>, class-string<\SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema>> */
    private array $classes = [];

    /**
     * Register a DefinesSchema class as referenced by the document.
     *
     * @param  class-string<\SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema>  $class
     * @return void
     */
    public function register(string $class): void
    {
        $this->classes[$class] = $class;
    }

    /**
     * List the distinct classes registered so far, in first-registration order.
     *
     * @return list<class-string<\SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema>>
     */
    public function classes(): array
    {
        return array_values($this->classes);
    }

    /**
     * Clear the collected set so the next document starts empty.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->classes = [];
    }
}
