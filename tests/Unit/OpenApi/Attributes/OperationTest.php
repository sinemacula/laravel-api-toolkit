<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Attributes\Operation;
use Tests\Fixtures\OpenApi\PathOperationController;

/**
 * Tests for the Operation attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Operation::class)]
final class OperationTest extends TestCase
{
    /**
     * Test that a declared id and summary are carried.
     *
     * @return void
     */
    public function testCarriesTheIdAndSummary(): void
    {
        $both = new Operation('widgets.showcase', 'Show a widget');

        self::assertSame('widgets.showcase', $both->id);
        self::assertSame('Show a widget', $both->summary);

        self::assertNull((new Operation(id: 'widgets.listing'))->summary);
        self::assertNull((new Operation(summary: 'List the widgets'))->id);
    }

    /**
     * Provide the invalid arguments with the message each is refused with.
     *
     * @return iterable<string, array{string|null, string|null, string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'neither' => [null, null, 'An operation directive must declare an id or a summary'];
        yield 'empty id' => ['', 'Show a widget', 'An operation id must not be empty'];
        yield 'empty summary' => ['widgets.show', '', 'An operation summary must not be empty'];
        yield 'empty id alone' => ['', null, 'An operation id must not be empty'];
        yield 'empty summary alone' => [null, '', 'An operation summary must not be empty'];
    }

    /**
     * Test that a directive declaring nothing, or an empty value, is refused.
     *
     * @param  string|null  $id
     * @param  string|null  $summary
     * @param  string  $message
     * @return void
     */
    #[DataProvider('invalidArguments')]
    public function testRefusesAnEmptyDirective(?string $id, ?string $summary, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($message, '/') . '$/');

        new Operation($id, $summary);
    }

    /**
     * Test that the directive is read back off a method declaration.
     *
     * @return void
     */
    public function testIsReadBackFromAMethodDeclaration(): void
    {
        $attributes = (new \ReflectionMethod(PathOperationController::class, 'show'))->getAttributes(Operation::class);

        self::assertCount(1, $attributes);
        self::assertSame('widgets.showcase', $attributes[0]->newInstance()->id);
    }
}
