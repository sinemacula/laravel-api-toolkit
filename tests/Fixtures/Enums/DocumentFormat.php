<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums;

/**
 * Fixture non-backed enum for document format.
 *
 * Proves a non-backed enum falls back to a string-typed component.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum DocumentFormat
{
    case PDF;
    case HTML;
}
