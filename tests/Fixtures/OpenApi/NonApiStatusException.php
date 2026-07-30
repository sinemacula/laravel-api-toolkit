<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

/**
 * Fixture exception declaring an HTTP status yet sitting outside the API base.
 *
 * Documents that a throws tag resolving to a class carrying an HTTP status
 * constant but not descending from the API exception base is ignored by the
 * error-response reflection rather than surfaced as a documented response.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class NonApiStatusException extends \RuntimeException
{
    /** An HTTP status constant the reflection must never trust here */
    public const int HTTP_STATUS = 418;
}
