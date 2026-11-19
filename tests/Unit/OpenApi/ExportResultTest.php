<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\ExportResult;

/**
 * Tests for the OpenAPI export result.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportResult::class)]
final class ExportResultTest extends TestCase
{
    /**
     * Test that the result exposes the document and each count it was given.
     *
     * @return void
     */
    public function testExposesTheDocumentAndEachCount(): void
    {
        $document = ['openapi' => '3.1.0', 'paths' => []];

        $result = new ExportResult($document, 4, 3, 2, 1);

        self::assertSame($document, $result->document);
        self::assertSame(4, $result->operationCount);
        self::assertSame(3, $result->resourceCount);
        self::assertSame(2, $result->parameterCount);
        self::assertSame(1, $result->errorExampleCount);
        self::assertSame([], $result->skippedExamples);
    }

    /**
     * Test that the result exposes the reason each body has no example.
     *
     * @return void
     */
    public function testExposesEachSkippedExample(): void
    {
        $result = new ExportResult([], 0, 0, 0, 0, ['No example for GET /opaque response 200.']);

        self::assertSame(['No example for GET /opaque response 200.'], $result->skippedExamples);
    }
}
