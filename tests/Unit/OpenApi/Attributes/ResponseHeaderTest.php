<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;
use Tests\Fixtures\OpenApi\PathHeaderBaseController;

/**
 * Tests for the ResponseHeader attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResponseHeader::class)]
final class ResponseHeaderTest extends TestCase
{
    /**
     * Test that a directive naming only its header documents a plain string.
     *
     * @return void
     */
    public function testDocumentsAnUndescribedStringByDefault(): void
    {
        $directive = new ResponseHeader('X-Session-Id');

        self::assertSame('X-Session-Id', $directive->name);
        self::assertSame('', $directive->description);
        self::assertSame(['type' => 'string'], $directive->schema);
    }

    /**
     * Test that the description and schema are carried when they are given.
     *
     * @return void
     */
    public function testCarriesTheDescriptionAndSchema(): void
    {
        $directive = new ResponseHeader('Retry-After', 'Seconds to wait.', ['type' => 'integer', 'minimum' => 0]);

        self::assertSame('Seconds to wait.', $directive->description);
        self::assertSame(['type' => 'integer', 'minimum' => 0], $directive->schema);
    }

    /**
     * Test that a name using every HTTP field-name character is accepted.
     *
     * @return void
     */
    public function testAcceptsEveryFieldNameCharacter(): void
    {
        $name = 'A!#$%&\'*+-.^_`|~09az';

        self::assertSame($name, (new ResponseHeader($name))->name);
    }

    /**
     * Test that a name opening with a digit or a symbol is accepted.
     *
     * @return void
     */
    public function testAcceptsANameOpeningWithAnyTokenCharacter(): void
    {
        self::assertSame('1-Header', (new ResponseHeader('1-Header'))->name);
        self::assertSame('-Header', (new ResponseHeader('-Header'))->name);
        self::assertSame('12a', (new ResponseHeader('12a'))->name);
    }

    /**
     * Provide names that are not valid HTTP field names.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'integer' => ['123'];
        yield 'negative integer' => ['-5'];
        yield 'space' => ['Retry After'];
        yield 'colon' => ['Retry-After:'];
        yield 'trailing newline' => ["Retry-After\n"];
        yield 'leading newline' => ["\nRetry-After"];
        yield 'non-ascii letter' => ['Ré-Try'];
    }

    /**
     * Test that a name that is not a valid HTTP field name is rejected.
     *
     * @param  string  $name
     * @return void
     */
    #[DataProvider('invalidNames')]
    public function testRejectsAnInvalidName(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('A response header name must be a valid HTTP field name, [%s] given', $name));

        new ResponseHeader($name);
    }

    /**
     * Test that the content type is rejected in any case.
     *
     * @return void
     */
    public function testRejectsTheContentTypeInAnyCase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A response header cannot document Content-Type, which the response media type describes');

        new ResponseHeader('content-TYPE');
    }

    /**
     * Provide schemas that are not JSON-Schema objects.
     *
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidSchemas(): iterable
    {
        yield 'empty' => [[]];
        yield 'list' => [['integer']];
    }

    /**
     * Test that a schema that is not a JSON-Schema object is rejected.
     *
     * @param  array<array-key, mixed>  $schema
     * @return void
     */
    #[DataProvider('invalidSchemas')]
    public function testRejectsASchemaThatIsNotAnObject(array $schema): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The schema of response header [Retry-After] must be a JSON-Schema object');

        new ResponseHeader('Retry-After', schema: $schema);
    }

    /**
     * Test that repeated directives are read back in declared order.
     *
     * @return void
     */
    public function testIsReadBackRepeatedFromAMethodDeclaration(): void
    {
        $subject = new class {
            /**
             * Carry the directives under test on a method declaration.
             *
             * @return void
             */
            #[ResponseHeader('Set-Cookie', 'The refresh token.')]
            #[ResponseHeader('X-Session-Id')]
            public function signIn(): void {}
        };

        $names = array_map(
            static fn (\ReflectionAttribute $attribute): string => $attribute->newInstance()->name,
            (new \ReflectionMethod($subject, 'signIn'))->getAttributes(ResponseHeader::class),
        );

        self::assertSame(['Set-Cookie', 'X-Session-Id'], $names);
    }

    /**
     * Test that repeated directives are read back off a class declaration.
     *
     * @return void
     */
    public function testIsReadBackRepeatedFromAClassDeclaration(): void
    {
        $names = array_map(
            static fn (\ReflectionAttribute $attribute): string => $attribute->newInstance()->name,
            (new \ReflectionClass(PathHeaderBaseController::class))->getAttributes(ResponseHeader::class),
        );

        self::assertSame(['X-Api-Version', 'X-Request-Id'], $names);
    }
}
