<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseExample;
use SineMacula\Http\Enums\HttpStatus;
use Tests\Fixtures\OpenApi\PathResponseExampleController;

/**
 * Tests for the ResponseExample attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResponseExample::class)]
final class ResponseExampleTest extends TestCase
{
    /**
     * Test that the status, name and file are carried.
     *
     * @return void
     */
    public function testCarriesItsStatusNameAndFile(): void
    {
        $example = new ResponseExample(HttpStatus::CREATED, 'signed-in', 'auth/sign-in/signed-in.json');

        self::assertSame(HttpStatus::CREATED, $example->status);
        self::assertSame('signed-in', $example->name);
        self::assertSame('auth/sign-in/signed-in.json', $example->file);
    }

    /**
     * Provide statuses outside the success range.
     *
     * @return iterable<string, array{\SineMacula\Http\Enums\HttpStatus}>
     */
    public static function nonSuccessStatuses(): iterable
    {
        yield 'informational' => [HttpStatus::EARLY_HINTS];
        yield 'redirection' => [HttpStatus::MULTIPLE_CHOICES];
        yield 'client error' => [HttpStatus::NOT_FOUND];
        yield 'server error' => [HttpStatus::INTERNAL_SERVER_ERROR];
    }

    /**
     * Test that a status outside the success range is refused.
     *
     * @param  \SineMacula\Http\Enums\HttpStatus  $status
     * @return void
     */
    #[DataProvider('nonSuccessStatuses')]
    public function testRefusesAStatusOutsideTheSuccessRange(HttpStatus $status): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'A response example must name a success status, [%d] given; error examples come from the exceptions an action throws',
            $status->value,
        ));

        new ResponseExample($status, 'failed', 'failed.json');
    }

    /**
     * Test that an empty name is refused.
     *
     * @return void
     */
    public function testRefusesAnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A response example must have a name');

        new ResponseExample(HttpStatus::OK, '', 'signed-in.json');
    }

    /**
     * Provide files that are not a relative path to a .json file.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidFiles(): iterable
    {
        yield 'absolute' => ['/auth/signed-in.json'];
        yield 'backslash' => ['auth\signed-in.json'];
        yield 'current directory segment' => ['./signed-in.json'];
        yield 'parent directory segment' => ['auth/../signed-in.json'];
        yield 'empty segment' => ['auth//signed-in.json'];
        yield 'not json' => ['auth/signed-in.yaml'];
        yield 'empty' => [''];
    }

    /**
     * Test that a file that is not a relative path to a .json file inside the
     * examples directory is refused.
     *
     * @param  string  $file
     * @return void
     */
    #[DataProvider('invalidFiles')]
    public function testRefusesAFileOutsideTheExamplesDirectory(string $file): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('A response example file must be a relative path to a .json file inside the examples directory, [%s] given', $file));

        new ResponseExample(HttpStatus::OK, 'signed-in', $file);
    }

    /**
     * Test that the directive repeats on a method, read back in declaration
     * order.
     *
     * @return void
     */
    public function testRepeatsOnAMethodInDeclarationOrder(): void
    {
        $attributes = (new \ReflectionMethod(PathResponseExampleController::class, 'signIn'))->getAttributes(ResponseExample::class);
        $target     = (new \ReflectionClass(ResponseExample::class))->getAttributes(\Attribute::class)[0]->newInstance();

        self::assertSame(['signed-in', 'remembered'], array_map(static fn (\ReflectionAttribute $attribute): string => $attribute->newInstance()->name, $attributes));
        self::assertSame(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE, $target->flags);
    }
}
