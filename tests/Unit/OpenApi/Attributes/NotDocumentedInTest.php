<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Attributes\NotDocumentedIn;

/**
 * Tests for the NotDocumentedIn attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(NotDocumentedIn::class)]
final class NotDocumentedInTest extends TestCase
{
    /**
     * Test that a single audience is stored as a list.
     *
     * @return void
     */
    public function testStoresSingleAudience(): void
    {
        self::assertSame(['public'], (new NotDocumentedIn('public'))->audiences);
    }

    /**
     * Test that multiple audiences are stored in order.
     *
     * @return void
     */
    public function testStoresMultipleAudiences(): void
    {
        self::assertSame(['public', 'partner'], (new NotDocumentedIn('public', 'partner'))->audiences);
    }

    /**
     * Test that no audiences yields an empty list.
     *
     * @return void
     */
    public function testStoresEmptyAudiences(): void
    {
        self::assertSame([], (new NotDocumentedIn)->audiences);
    }
}
