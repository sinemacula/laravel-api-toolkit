<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\PerItemGuardedFieldException;

/**
 * Tests for the PerItemGuardedFieldException.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PerItemGuardedFieldException::class)]
final class PerItemGuardedFieldExceptionTest extends TestCase
{
    /**
     * Test that forField builds the full guidance message verbatim.
     *
     * @return void
     */
    public function testForFieldBuildsTheFullGuidanceMessage(): void
    {
        $exception = PerItemGuardedFieldException::forField('email', 'posts');

        $expected = 'The "email" field on the "posts" resource carries a row guard, '
            . 'so it cannot be exported to a tabular format. A tabular export includes or '
            . 'omits whole columns rather than individual cells, so a row guard cannot '
            . 'be honoured without leaking the values it is meant to hide. Guard the field '
            . 'with visibleTo() where visibility depends on the caller alone, exclude it '
            . 'from the export, or override tabular() and gate the column with the exporter\'s '
            . '->visible().';

        self::assertSame($expected, $exception->getMessage());
    }
}
