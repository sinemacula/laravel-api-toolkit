<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Attributes;

use SineMacula\Http\Enums\HttpStatus;

/**
 * Declare the success status of an action, overriding the derived one.
 *
 * A no-content status (204 or 205) has no body, so it cannot sit beside a
 * #[ResponseSchema].
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class ResponseStatus
{
    /** The success statuses whose response carries no body */
    private const array NO_CONTENT_STATUSES = [HttpStatus::NO_CONTENT, HttpStatus::RESET_CONTENT];

    /**
     * Create a new response-status directive.
     *
     * @param  \SineMacula\Http\Enums\HttpStatus  $status
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(

        /** The success status the action answers with. */
        public HttpStatus $status,
    ) {
        if (!$status->isSuccess()) {
            throw new \InvalidArgumentException(sprintf('A response status must be a success status, [%d] given', $status->value));
        }
    }

    /**
     * Determine whether the declared status answers with no body.
     *
     * @return bool
     */
    public function hasNoContent(): bool
    {
        return in_array($this->status, self::NO_CONTENT_STATUSES, true);
    }
}
