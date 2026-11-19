<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\InvalidInputException;

/**
 * Tests for the InvalidInputException.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(InvalidInputException::class)]
final class InvalidInputExceptionTest extends TestCase
{
    /**
     * Test that the meta schema types the meta as the messages of each failed
     * field.
     *
     * @return void
     */
    public function testMetaSchemaTypesTheMessagesOfEachFailedField(): void
    {
        self::assertSame([
            'type'                 => 'object',
            'minProperties'        => 1,
            'additionalProperties' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
        ], InvalidInputException::metaSchema());
    }
}
