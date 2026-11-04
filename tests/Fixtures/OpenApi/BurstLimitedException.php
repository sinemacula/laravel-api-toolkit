<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

/**
 * Fixture rate-limited exception declaring no header of its own.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class BurstLimitedException extends RateLimitedException {}
