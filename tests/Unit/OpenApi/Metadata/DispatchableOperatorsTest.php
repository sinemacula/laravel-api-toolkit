<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\OpenApi\Metadata\DispatchableOperators;

/**
 * Tests for the dispatchable operator narrowing.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DispatchableOperators::class)]
final class DispatchableOperatorsTest extends TestCase
{
    /** @var array<int, string> The tokens the package ships */
    private const array FULL_VOCABULARY = [
        '$eq', '$neq', '$in', '$gt', '$ge', '$lt', '$le', '$between', '$contains', '$null', '$notNull',
    ];

    /**
     * Provide every case with the operators it documents.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\Capability, array<int, string>}>
     */
    public static function fullVocabularyProvider(): iterable
    {
        yield 'exact' => [Capability::EXACT, ['$eq', '$in', '$null', '$notNull']];
        yield 'enum' => [Capability::ENUM, ['$eq', '$in', '$neq', '$null', '$notNull']];
        yield 'range' => [Capability::RANGE, ['$eq', '$in', '$gt', '$ge', '$lt', '$le', '$between', '$null', '$notNull']];
        yield 'document' => [Capability::DOCUMENT, ['$contains']];
        yield 'opaque' => [Capability::OPAQUE, ['$eq']];
    }

    /**
     * Test that a full registry documents the whole capability declaration.
     *
     * @param  \SineMacula\ApiToolkit\Enums\Capability  $capability
     * @param  array<int, string>  $expected
     * @return void
     */
    #[DataProvider('fullVocabularyProvider')]
    public function testAnUnnarrowedVocabularyDocumentsTheWholeDeclaration(Capability $capability, array $expected): void
    {
        self::assertSame($expected, DispatchableOperators::forCapability($capability, self::FULL_VOCABULARY));
    }

    /**
     * Test that an unregistered token is dropped and the order kept.
     *
     * @return void
     */
    public function testATokenTheRegistryNoLongerHoldsIsDropped(): void
    {
        $vocabulary = ['$eq', '$null', '$notNull'];

        self::assertSame(
            ['$eq', '$null', '$notNull'],
            DispatchableOperators::forCapability(Capability::RANGE, $vocabulary),
        );
    }

    /**
     * Test that the surviving tokens are returned as a list.
     *
     * A gapped array would encode as an object keyed by position, not an array.
     *
     * @return void
     */
    public function testTheSurvivingTokensAreReturnedAsAList(): void
    {
        // The dropped token sits between two survivors, leaving a mid-list gap
        $operators = DispatchableOperators::forCapability(Capability::EXACT, ['$eq', '$null', '$notNull']);

        self::assertSame([0, 1, 2], array_keys($operators));
        self::assertTrue(array_is_list($operators));
    }

    /**
     * Provide every case, so each is checked against an emptied registry.
     *
     * @return iterable<string, array{\SineMacula\ApiToolkit\Enums\Capability}>
     */
    public static function everyCaseProvider(): iterable
    {
        foreach (Capability::cases() as $capability) {
            yield $capability->value => [$capability];
        }
    }

    /**
     * Test that a capability with every token unbound documents no filter.
     *
     * @param  \SineMacula\ApiToolkit\Enums\Capability  $capability
     * @return void
     */
    #[DataProvider('everyCaseProvider')]
    public function testACapabilityWithNoDispatchableTokenDocumentsNothing(Capability $capability): void
    {
        self::assertSame([], DispatchableOperators::forCapability($capability, []));
    }

    /**
     * Test that a token the capability does not permit is not documented.
     *
     * @return void
     */
    public function testAVocabularyWiderThanTheCapabilityDoesNotWidenIt(): void
    {
        self::assertSame(['$contains'], DispatchableOperators::forCapability(Capability::DOCUMENT, self::FULL_VOCABULARY));
    }
}
