<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;

/**
 * Fixture rate-limited exception redeclaring its parent's header, under a
 * different case, beside an undescribed header of its own.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[ResponseHeader('retry-after', 'The number of seconds until the quota resets.', ['type' => 'integer', 'minimum' => 1])]
#[ResponseHeader('X-Quota-Reset', schema: ['type' => 'integer'])]
final class QuotaExceededException extends RateLimitedException {}
