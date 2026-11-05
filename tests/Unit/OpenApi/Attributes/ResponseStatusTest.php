<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseStatus;
use SineMacula\Http\Enums\HttpStatus;
use Tests\Fixtures\OpenApi\PathResponseStatusController;

/**
 * Tests for the ResponseStatus attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResponseStatus::class)]
final class ResponseStatusTest extends TestCase
{
    /**
     * Provide every success status with whether it answers with no body.
     *
     * @return iterable<string, array{\SineMacula\Http\Enums\HttpStatus, bool}>
     */
    public static function successStatuses(): iterable
    {
        foreach (HttpStatus::cases() as $status) {

            if (!$status->isSuccess()) {
                continue;
            }

            yield $status->name => [$status, in_array($status, [HttpStatus::NO_CONTENT, HttpStatus::RESET_CONTENT], true)];
        }
    }

    /**
     * Test that a success status is carried, answering with no body only when
     * it is 204 or 205.
     *
     * @param  \SineMacula\Http\Enums\HttpStatus  $status
     * @param  bool  $noContent
     * @return void
     */
    #[DataProvider('successStatuses')]
    public function testCarriesASuccessStatus(HttpStatus $status, bool $noContent): void
    {
        $directive = new ResponseStatus($status);

        self::assertSame($status, $directive->status);
        self::assertSame($noContent, $directive->hasNoContent());
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
        yield 'not modified' => [HttpStatus::NOT_MODIFIED];
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
        $this->expectExceptionMessage(sprintf('A response status must be a success status, [%d] given', $status->value));

        new ResponseStatus($status);
    }

    /**
     * Test that the directive is read back off a method declaration.
     *
     * @return void
     */
    public function testIsReadBackFromAMethodDeclaration(): void
    {
        $attributes = (new \ReflectionMethod(PathResponseStatusController::class, 'queue'))->getAttributes(ResponseStatus::class);

        self::assertCount(1, $attributes);
        self::assertSame(HttpStatus::ACCEPTED, $attributes[0]->newInstance()->status);
    }
}
