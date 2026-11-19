<?php

declare(strict_types = 1);

namespace Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\Capability;

/**
 * Tests for the Capability enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Capability::class)]
final class CapabilityTest extends TestCase
{
    /** @var array<string, array<int, string>> The default matrix restated by token, in case order, independent of the enum */
    private const array MATRIX = [
        '$eq'       => ['exact', 'enum', 'range', 'opaque'],
        '$neq'      => ['enum'],
        '$in'       => ['exact', 'enum', 'range'],
        '$gt'       => ['range'],
        '$ge'       => ['range'],
        '$lt'       => ['range'],
        '$le'       => ['range'],
        '$between'  => ['range'],
        '$contains' => ['document'],
        '$null'     => ['exact', 'enum', 'range'],
        '$notNull'  => ['exact', 'enum', 'range'],
    ];

    /**
     * Provide every case with its backing value.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\Capability, string}>
     */
    public static function backingValueProvider(): iterable
    {
        yield 'exact' => [Capability::EXACT, 'exact'];
        yield 'enum' => [Capability::ENUM, 'enum'];
        yield 'range' => [Capability::RANGE, 'range'];
        yield 'document' => [Capability::DOCUMENT, 'document'];
        yield 'opaque' => [Capability::OPAQUE, 'opaque'];
    }

    /**
     * Test that each case carries its documented backing value.
     *
     * @param  \SineMacula\ApiToolkit\Enums\Capability  $capability
     * @param  string  $value
     * @return void
     */
    #[DataProvider('backingValueProvider')]
    public function testCaseCarriesItsBackingValue(Capability $capability, string $value): void
    {
        self::assertSame($value, $capability->value);
        self::assertSame($capability, Capability::from($value));
    }

    /**
     * Test that the enum declares exactly the five profiles the matrix decides.
     *
     * @return void
     */
    public function testDeclaresExactlyFiveCapabilities(): void
    {
        self::assertSame(
            [Capability::EXACT, Capability::ENUM, Capability::RANGE, Capability::DOCUMENT, Capability::OPAQUE],
            Capability::cases(),
        );
    }

    /**
     * Provide every case with the exact operator tokens it holds by default.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\Capability, array<int, string>}>
     */
    public static function defaultOperatorProvider(): iterable
    {
        yield 'exact' => [Capability::EXACT, ['$eq', '$in', '$null', '$notNull']];
        yield 'enum' => [Capability::ENUM, ['$eq', '$in', '$neq', '$null', '$notNull']];
        yield 'range' => [Capability::RANGE, ['$eq', '$in', '$gt', '$ge', '$lt', '$le', '$between', '$null', '$notNull']];
        yield 'document' => [Capability::DOCUMENT, ['$contains']];
        yield 'opaque' => [Capability::OPAQUE, ['$eq']];
    }

    /**
     * Test that each case holds exactly the operator tokens its access path
     * serves.
     *
     * @param  \SineMacula\ApiToolkit\Enums\Capability  $capability
     * @param  array<int, string>  $expected
     * @return void
     */
    #[DataProvider('defaultOperatorProvider')]
    public function testDefaultOperatorsAreExactlyTheDeclaredTokens(Capability $capability, array $expected): void
    {
        self::assertSame($expected, $capability->defaultOperators());
    }

    /**
     * Provide every shipped operator token with the cases admitting it.
     *
     * @return iterable<string, array{string, array<int, \SineMacula\ApiToolkit\Enums\Capability>}>
     */
    public static function admittingProvider(): iterable
    {
        foreach (self::MATRIX as $token => $admitting) {
            yield $token => [$token, array_map(Capability::from(...), $admitting)];
        }
    }

    /**
     * Test that each shipped token is admitted by exactly the cases whose
     * defaults hold it, in case order.
     *
     * @param  string  $token
     * @param  array<int, \SineMacula\ApiToolkit\Enums\Capability>  $expected
     * @return void
     */
    #[DataProvider('admittingProvider')]
    public function testAdmittingListsTheCasesWhoseDefaultsHoldTheToken(string $token, array $expected): void
    {
        self::assertSame($expected, Capability::admitting($token));
    }

    /**
     * Provide tokens no case holds by default.
     *
     * @return iterable<string, array{string}>
     */
    public static function unknownTokenProvider(): iterable
    {
        yield 'application registered' => ['$starts'];
        yield 'bare' => ['eq'];
        yield 'empty' => [''];
    }

    /**
     * Test that a token no case holds by default is admitted by none.
     *
     * @param  string  $token
     * @return void
     */
    #[DataProvider('unknownTokenProvider')]
    public function testAdmittingIsEmptyForATokenNoCaseHolds(string $token): void
    {
        self::assertSame([], Capability::admitting($token));
    }

    /**
     * Test that every shipped token is held by at least one capability.
     *
     * @return void
     */
    public function testEveryShippedTokenIsReachableFromSomeCapability(): void
    {
        $reachable = [];

        foreach (Capability::cases() as $capability) {
            $reachable = [...$reachable, ...$capability->defaultOperators()];
        }

        $reachable = array_values(array_unique($reachable));
        $shipped   = array_keys(self::MATRIX);

        sort($reachable);
        sort($shipped);

        self::assertSame($shipped, $reachable);
    }
}
