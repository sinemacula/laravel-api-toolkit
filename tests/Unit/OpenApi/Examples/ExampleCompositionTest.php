<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Examples;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Examples\ExampleComposition;

/**
 * Tests for the result of composing a document's examples.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExampleComposition::class)]
final class ExampleCompositionTest extends TestCase
{
    /**
     * Test that the composition exposes its document and skipped bodies.
     *
     * @return void
     */
    public function testExposesTheDocumentAndSkippedBodies(): void
    {
        $composition = new ExampleComposition(['openapi' => '3.1.0'], ['No example for GET /opaque response 200.']);

        self::assertSame(['openapi' => '3.1.0'], $composition->document);
        self::assertSame(['No example for GET /opaque response 200.'], $composition->skipped);
    }
}
