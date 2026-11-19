<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\ForbiddenException;

/**
 * Tests for the ForbiddenException.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ForbiddenException::class)]
final class ForbiddenExceptionTest extends TestCase
{
    /**
     * Test that the meta schema types the optional reason a policy denied the
     * request with.
     *
     * @return void
     */
    public function testMetaSchemaTypesTheOptionalDenialReason(): void
    {
        self::assertSame([
            'type'                 => 'object',
            'properties'           => ['reason' => ['type' => ['string', 'integer']]],
            'additionalProperties' => false,
        ], ForbiddenException::metaSchema());
    }
}
