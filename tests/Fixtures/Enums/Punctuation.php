<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums;

/**
 * Fixture string-backed enum whose values carry commas and quotes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum Punctuation: string
{
    case COMMA = 'a,b';
    case QUOTE = 'say "hi"';
    case PLAIN = 'plain';
}
