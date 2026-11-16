<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation;

/**
 * Immutable outcome of validating every registered resource schema.
 *
 * Whether an unverified declaration fails the run is the caller's decision.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SchemaValidationReport
{
    /**
     * Create a new schema validation report.
     *
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>  $errors
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration>  $unverified
     * @param  int  $resourceCount
     */
    public function __construct(

        /** The defects found, in rule and resource order. */
        public array $errors,

        /** The declarations the catalogue could not verify. */
        public array $unverified,

        /** The number of resource schemas validated. */
        public int $resourceCount,
    ) {}
}
