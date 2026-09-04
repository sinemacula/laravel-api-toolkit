<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;

/**
 * Tests for the ResponseSchema attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResponseSchema::class)]
final class ResponseSchemaTest extends TestCase
{
    /**
     * Test that a directive naming only its schema describes a single item,
     * so a collection is never documented by omission.
     *
     * @return void
     */
    public function testDescribesASingleItemUnlessToldOtherwise(): void
    {
        $directive = new ResponseSchema('App\Http\Resources\UserResource');

        self::assertSame('App\Http\Resources\UserResource', $directive->schema);
        self::assertFalse($directive->collection);
    }

    /**
     * Test that the collection flag is carried when it is given.
     *
     * @return void
     */
    public function testCarriesTheCollectionFlag(): void
    {
        self::assertTrue((new ResponseSchema('App\Http\Resources\UserResource', true))->collection);
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
            #[ResponseSchema('App\Http\Resources\UserResource', true)]
            public function index(): void {}
        };

        $directive = (new \ReflectionMethod($subject, 'index'))->getAttributes(ResponseSchema::class)[0]->newInstance();

        self::assertSame('App\Http\Resources\UserResource', $directive->schema);
        self::assertTrue($directive->collection);
    }
}
