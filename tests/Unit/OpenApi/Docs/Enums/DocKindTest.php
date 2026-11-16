<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Docs\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Docs\Enums\DocKind;

/**
 * Tests for the keyed description file kinds.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DocKind::class)]
final class DocKindTest extends TestCase
{
    /**
     * Test that each kind names its directory and what it describes.
     *
     * @return void
     */
    public function testEachKindNamesItsDirectoryAndNoun(): void
    {
        self::assertSame('operations', DocKind::OPERATION->value);
        self::assertSame('operation', DocKind::OPERATION->noun());
        self::assertSame('tags', DocKind::TAG->value);
        self::assertSame('tag', DocKind::TAG->noun());
    }
}
