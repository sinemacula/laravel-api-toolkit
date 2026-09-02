<?php

declare(strict_types = 1);

namespace Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\SearchStrategy;

/**
 * Tests for the SearchStrategy enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SearchStrategy::class)]
final class SearchStrategyTest extends TestCase
{
    /**
     * Provide every case with its backing value.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\SearchStrategy, string}>
     */
    public static function backingValueProvider(): iterable
    {
        yield 'exact'     => [SearchStrategy::EXACT, 'exact'];
        yield 'prefix'    => [SearchStrategy::PREFIX, 'prefix'];
        yield 'substring' => [SearchStrategy::SUBSTRING, 'substring'];
    }

    /**
     * Provide every case with whether it needs a specialised index.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\SearchStrategy, bool}>
     */
    public static function specialisedIndexProvider(): iterable
    {
        yield 'exact'     => [SearchStrategy::EXACT, false];
        yield 'prefix'    => [SearchStrategy::PREFIX, false];
        yield 'substring' => [SearchStrategy::SUBSTRING, true];
    }

    /**
     * Provide every case with whether it matches by pattern.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\SearchStrategy, bool}>
     */
    public static function patternMatchProvider(): iterable
    {
        yield 'exact'     => [SearchStrategy::EXACT, false];
        yield 'prefix'    => [SearchStrategy::PREFIX, true];
        yield 'substring' => [SearchStrategy::SUBSTRING, true];
    }

    /**
     * Provide every case with the wildcards it wraps a term in.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\SearchStrategy, string}>
     */
    public static function wildcardProvider(): iterable
    {
        yield 'exact'     => [SearchStrategy::EXACT, 'smith'];
        yield 'prefix'    => [SearchStrategy::PREFIX, 'smith%'];
        yield 'substring' => [SearchStrategy::SUBSTRING, '%smith%'];
    }

    /**
     * Test that each case carries its documented backing value.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  string  $value
     * @return void
     */
    #[DataProvider('backingValueProvider')]
    public function testCaseCarriesItsBackingValue(SearchStrategy $strategy, string $value): void
    {
        self::assertSame($value, $strategy->value);
        self::assertSame($strategy, SearchStrategy::from($value));
    }

    /**
     * Test that the enum declares exactly the three match shapes the package
     * serves, so a fourth cannot arrive without a driver being taught it.
     *
     * @return void
     */
    public function testDeclaresExactlyThreeStrategies(): void
    {
        self::assertSame(
            [SearchStrategy::EXACT, SearchStrategy::PREFIX, SearchStrategy::SUBSTRING],
            SearchStrategy::cases(),
        );
    }

    /**
     * Test that only the anywhere-match needs an index beyond a plain B-tree.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  bool  $expected
     * @return void
     */
    #[DataProvider('specialisedIndexProvider')]
    public function testReportsWhetherASpecialisedIndexIsNeeded(SearchStrategy $strategy, bool $expected): void
    {
        self::assertSame($expected, $strategy->requiresSpecialisedIndex());
    }

    /**
     * Test that every strategy but the equality one matches by pattern, so the
     * term it is given has to be escaped.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  bool  $expected
     * @return void
     */
    #[DataProvider('patternMatchProvider')]
    public function testReportsWhetherTheStrategyMatchesByPattern(SearchStrategy $strategy, bool $expected): void
    {
        self::assertSame($expected, $strategy->matchesByPattern());
    }

    /**
     * Test that each strategy wraps a term in its own wildcards.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  string  $expected
     * @return void
     */
    #[DataProvider('wildcardProvider')]
    public function testWrapsATermInItsOwnWildcards(SearchStrategy $strategy, string $expected): void
    {
        self::assertSame($expected, $strategy->wrapWildcards('smith'));
    }
}
