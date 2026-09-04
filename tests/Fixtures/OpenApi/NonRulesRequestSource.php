<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

/**
 * Fixture class that is not a rules source.
 *
 * Implements neither the self-describing input contract nor the FormRequest
 * base, so a directive naming it reaches no rules at all.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class NonRulesRequestSource {}
