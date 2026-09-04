<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Attributes\SchemaName;

/**
 * Tests for the SchemaName attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SchemaName::class)]
final class SchemaNameTest extends TestCase
{
    /**
     * Test that the attribute carries the component name it was given.
     *
     * @return void
     */
    public function testCarriesTheComponentName(): void
    {
        self::assertSame('AccountSummary', (new SchemaName('AccountSummary'))->name);
    }

    /**
     * Test that the override is read back off a declaration, which is the only
     * way the naming layer ever obtains one.
     *
     * @return void
     */
    public function testIsReadBackFromADeclaration(): void
    {
        $subject = new #[SchemaName('AccountSummary')] class {};

        $attributes = (new \ReflectionClass($subject))->getAttributes(SchemaName::class);

        self::assertCount(1, $attributes);
        self::assertSame('AccountSummary', $attributes[0]->newInstance()->name);
    }
}
