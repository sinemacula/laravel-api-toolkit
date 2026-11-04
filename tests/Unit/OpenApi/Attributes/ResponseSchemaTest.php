<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseSchema;
use Tests\Fixtures\OpenApi\PendingSignInShape;
use Tests\Fixtures\OpenApi\SignedInShape;
use Tests\Fixtures\Resources\UserResource;

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
     * Test that a directive naming only its schema describes a single item, so
     * a collection is never documented by omission.
     *
     * @return void
     */
    public function testDescribesASingleItemUnlessToldOtherwise(): void
    {
        $directive = new ResponseSchema(UserResource::class);

        self::assertSame([UserResource::class], $directive->schemas);
        self::assertFalse($directive->collection);
    }

    /**
     * Test that the collection flag is carried when it is given.
     *
     * @return void
     */
    public function testCarriesTheCollectionFlag(): void
    {
        self::assertTrue((new ResponseSchema(UserResource::class, true))->collection);
    }

    /**
     * Test that a list of classes is kept in the order it was declared, since
     * that order is the order the union documents its shapes in.
     *
     * @return void
     */
    public function testKeepsAListOfClassesInDeclaredOrder(): void
    {
        $directive = new ResponseSchema([SignedInShape::class, PendingSignInShape::class]);

        self::assertSame([SignedInShape::class, PendingSignInShape::class], $directive->schemas);
    }

    /**
     * Test that a keyed array of classes is re-indexed into a list, so the keys
     * a caller happened to use never leak into the documented union.
     *
     * @return void
     */
    public function testReindexesAKeyedArrayOfClasses(): void
    {
        $directive = new ResponseSchema(['signed' => SignedInShape::class, 'pending' => PendingSignInShape::class]);

        self::assertSame([SignedInShape::class, PendingSignInShape::class], $directive->schemas);
    }

    /**
     * Test that an empty list of classes is rejected, since it describes no
     * response body at all.
     *
     * @return void
     */
    public function testRejectsAnEmptyListOfClasses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A response schema must name at least one class');

        new ResponseSchema([]);
    }

    /**
     * Test that a list holding anything other than a class name is rejected.
     *
     * @return void
     */
    public function testRejectsAListHoldingANonStringElement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A response schema must name each class as a string');

        new ResponseSchema([SignedInShape::class, 42]);
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
            #[ResponseSchema([UserResource::class, PendingSignInShape::class], true)]
            public function index(): void {}
        };

        $directive = (new \ReflectionMethod($subject, 'index'))->getAttributes(ResponseSchema::class)[0]->newInstance();

        self::assertSame([UserResource::class, PendingSignInShape::class], $directive->schemas);
        self::assertTrue($directive->collection);
    }
}
