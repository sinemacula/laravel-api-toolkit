<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Attributes;

/**
 * Declare the operationId and summary of an action.
 *
 * The summary is always exported; the id only while operation ids are on.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Operation
{
    /**
     * Create a new operation directive.
     *
     * @param  string|null  $id
     * @param  string|null  $summary
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(

        /** The operationId replacing the derived one. */
        public ?string $id = null,

        /** The one-line title of the operation. */
        public ?string $summary = null,
    ) {
        if ($id === null && $summary === null) {
            throw new \InvalidArgumentException('An operation directive must declare an id or a summary');
        }

        if ($id === '') {
            throw new \InvalidArgumentException('An operation id must not be empty');
        }

        if ($summary === '') {
            throw new \InvalidArgumentException('An operation summary must not be empty');
        }
    }
}
