<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Attributes\RequestSchema;

/**
 * Tests for the RequestSchema attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RequestSchema::class)]
final class RequestSchemaTest extends TestCase
{
    /**
     * Test that the attribute carries the schema class it was given.
     *
     * @return void
     */
    public function testCarriesTheSchemaClass(): void
    {
        self::assertSame('App\Http\Requests\StoreUser', (new RequestSchema('App\Http\Requests\StoreUser'))->schema);
    }

    /**
     * Test that the directive is read back off a method declaration, which is
     * the only target it may be written on.
     *
     * @return void
     */
    public function testIsReadBackFromAMethodDeclaration(): void
    {
        $subject = new class {
            /**
             * Carry the directive under test on a method declaration.
             *
             * @return void
             */
            #[RequestSchema('App\Http\Requests\StoreUser')]
            public function store(): void {}
        };

        $attributes = (new \ReflectionMethod($subject, 'store'))->getAttributes(RequestSchema::class);

        self::assertCount(1, $attributes);
        self::assertSame('App\Http\Requests\StoreUser', $attributes[0]->newInstance()->schema);
    }
}
