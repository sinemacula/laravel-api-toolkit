<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Attributes\Tag;

/**
 * Tests for the Tag attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Tag::class)]
final class TagTest extends TestCase
{
    /**
     * Test that the attribute carries the tag it was given.
     *
     * @return void
     */
    public function testCarriesTheTagName(): void
    {
        self::assertSame('Users', (new Tag('Users'))->name);
    }

    /**
     * Test that the tag is read back off a declaration, which is the only way
     * the exporter ever obtains one.
     *
     * @return void
     */
    public function testIsReadBackFromADeclaration(): void
    {
        $subject = new #[Tag('Accounts')] class {};

        $attributes = (new \ReflectionClass($subject))->getAttributes(Tag::class);

        self::assertCount(1, $attributes);
        self::assertSame('Accounts', $attributes[0]->newInstance()->name);
    }
}
