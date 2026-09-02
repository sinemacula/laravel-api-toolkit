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
    /** @var array<string, array<int, string>> The permitted matrix restated by token, so the enum is checked against a table rather than against itself */
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
     * Test that the enum declares exactly the five profiles the matrix decides,
     * so a sixth cannot arrive without its permitted operators being chosen.
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
     * Provide every case with the exact operator tokens it permits.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\Capability, array<int, string>}>
     */
    public static function permittedOperatorProvider(): iterable
    {
        yield 'exact' => [Capability::EXACT, ['$eq', '$in', '$null', '$notNull']];
        yield 'enum' => [Capability::ENUM, ['$eq', '$in', '$neq', '$null', '$notNull']];
        yield 'range' => [Capability::RANGE, ['$eq', '$in', '$gt', '$ge', '$lt', '$le', '$between', '$null', '$notNull']];
        yield 'document' => [Capability::DOCUMENT, ['$contains']];
        yield 'opaque' => [Capability::OPAQUE, ['$eq']];
    }

    /**
     * Test that each case permits exactly the operator tokens its access path
     * serves, and nothing beside them.
     *
     * @param  \SineMacula\ApiToolkit\Enums\Capability  $capability
     * @param  array<int, string>  $expected
     * @return void
     */
    #[DataProvider('permittedOperatorProvider')]
    public function testPermittedOperatorsAreExactlyTheDeclaredTokens(Capability $capability, array $expected): void
    {
        self::assertSame($expected, $capability->permittedOperators());
    }

    /**
     * Provide every capability paired with every shipped operator token, and
     * whether the pair is permitted.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\Capability, string, bool}>
     */
    public static function matrixProvider(): iterable
    {
        foreach (self::MATRIX as $token => $permitted) {
            foreach (Capability::cases() as $capability) {
                yield $capability->value . ' ' . $token => [$capability, $token, in_array($capability->value, $permitted, true)];
            }
        }
    }

    /**
     * Test that every capability answers the whole shipped operator vocabulary
     * exactly as the matrix says, permitting and refusing each token.
     *
     * @param  \SineMacula\ApiToolkit\Enums\Capability  $capability
     * @param  string  $token
     * @param  bool  $expected
     * @return void
     */
    #[DataProvider('matrixProvider')]
    public function testPermitsAnswersEveryTokenAsTheMatrixSays(Capability $capability, string $token, bool $expected): void
    {
        self::assertSame($expected, $capability->permits($token));
    }

    /**
     * Provide every case with a token no operator ships under.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\Capability, string}>
     */
    public static function unknownTokenProvider(): iterable
    {
        foreach (Capability::cases() as $capability) {
            yield $capability->value . ' like' => [$capability, '$like'];
            yield $capability->value . ' bare' => [$capability, 'eq'];
            yield $capability->value . ' empty' => [$capability, ''];
        }
    }

    /**
     * Test that a token outside the shipped vocabulary is refused by every
     * capability, so an unregistered or misspelled operator can never inherit a
     * permission from a profile.
     *
     * @param  \SineMacula\ApiToolkit\Enums\Capability  $capability
     * @param  string  $token
     * @return void
     */
    #[DataProvider('unknownTokenProvider')]
    public function testUnknownTokenIsRefusedByEveryCapability(Capability $capability, string $token): void
    {
        self::assertFalse($capability->permits($token));
    }

    /**
     * Provide every shipped operator token.
     *
     * @return iterable<string, array{string}>
     */
    public static function shippedTokenProvider(): iterable
    {
        foreach (array_keys(self::MATRIX) as $token) {
            yield $token => [$token];
        }
    }

    /**
     * Test that the matrix reports itself as governing every token it ships, so
     * the gate holds each of them to a declaring column's capability.
     *
     * @param  string  $token
     * @return void
     */
    #[DataProvider('shippedTokenProvider')]
    public function testGovernsEveryShippedToken(string $token): void
    {
        self::assertTrue(Capability::governs($token));
    }

    /**
     * Provide tokens no capability mentions.
     *
     * @return iterable<string, array{string}>
     */
    public static function ungovernedTokenProvider(): iterable
    {
        yield 'application registered' => ['$starts'];
        yield 'deleted from the package' => ['$like'];
        yield 'bare' => ['eq'];
        yield 'empty' => [''];
    }

    /**
     * Test that a token no capability mentions is reported as ungoverned, so an
     * operator the application bound to a handler of its own is left to the
     * application rather than held to a matrix that never described it.
     *
     * @param  string  $token
     * @return void
     */
    #[DataProvider('ungovernedTokenProvider')]
    public function testDoesNotGovernATokenNoCapabilityMentions(string $token): void
    {
        self::assertFalse(Capability::governs($token));
    }

    /**
     * Test that every shipped token is permitted by at least one capability, so
     * an operator cannot ship as one no declaration can ever reach.
     *
     * @return void
     */
    public function testEveryShippedTokenIsReachableFromSomeCapability(): void
    {
        $reachable = [];

        foreach (Capability::cases() as $capability) {
            $reachable = [...$reachable, ...$capability->permittedOperators()];
        }

        $reachable = array_values(array_unique($reachable));
        $shipped   = array_keys(self::MATRIX);

        sort($reachable);
        sort($shipped);

        self::assertSame($shipped, $reachable);
    }
}
