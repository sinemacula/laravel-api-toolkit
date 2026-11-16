<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation;

/**
 * Immutable value object naming a declaration the catalogue could not verify.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class UnverifiedDeclaration
{
    /**
     * Create a new unverified declaration.
     *
     * @param  string  $resourceClass
     * @param  string  $fieldKey
     * @param  string  $check
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  string|null  $cause
     */
    public function __construct(

        /** The fully qualified class name of the resource. */
        public string $resourceClass,

        /** The schema field key carrying the declaration. */
        public string $fieldKey,

        /** The claim the catalogue was needed to prove. */
        public string $check,

        /** The model whose catalogue could not be read. */
        public string $modelClass,

        /** Why the read failed, when the rule saw the failure itself. */
        public ?string $cause = null,
    ) {}

    /**
     * Return a formatted string representation of the declaration.
     *
     * @return string
     */
    #[\Override]
    public function __toString(): string
    {
        return sprintf('[%s] Field "%s": could not verify that %s', $this->resourceClass, $this->fieldKey, $this->check);
    }
}
