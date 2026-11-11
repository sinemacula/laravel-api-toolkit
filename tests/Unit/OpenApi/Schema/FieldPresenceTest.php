<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\OpenApi\Schema\FieldPresence;
use Tests\TestCase;

/**
 * Tests for the field presence reader.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FieldPresence::class)]
final class FieldPresenceTest extends TestCase
{
    /**
     * Provide token lists with the presence each one implies.
     *
     * @return iterable<string, array{0: list<string>, 1: bool, 2: bool, 3: bool}>
     */
    public static function presenceProvider(): iterable
    {
        yield from [
            'required'             => [['required', 'string'], true, true, false],
            'present'              => [['present', 'array'], true, false, false],
            'filled'               => [['filled'], false, true, false],
            'nullable'             => [['nullable', 'string'], false, false, true],
            'nullable required'    => [['nullable', 'required'], true, true, false],
            'nullable filled'      => [['filled', 'nullable'], false, true, false],
            'nullable present'     => [['present', 'nullable'], true, false, true],
            'sometimes required'   => [['sometimes', 'required', 'nullable'], false, true, false],
            'sometimes present'    => [['sometimes', 'present'], false, false, false],
            'excludable required'  => [['exclude_if:kind,a', 'required', 'nullable'], false, false, true],
            'excludable present'   => [['present', 'exclude_unless:kind,a'], false, false, false],
            'excludable filled'    => [['filled', 'exclude_with:kind'], false, false, false],
            'required with'        => [['required_with:a', 'nullable'], false, false, true],
            'untranslatable rules' => [['accepted', 'starts_with:a'], false, false, false],
        ];
    }

    /**
     * Test that a field's rules decide its presence, emptiness and nullability.
     *
     * @param  list<string>  $tokens
     * @param  bool  $required
     * @param  bool  $rejectsEmpty
     * @param  bool  $acceptsNull
     * @return void
     */
    #[DataProvider('presenceProvider')]
    public function testRulesGovernTheFieldsPresence(array $tokens, bool $required, bool $rejectsEmpty, bool $acceptsNull): void
    {
        $presence = new FieldPresence;

        self::assertSame($required, $presence->isRequired($tokens));
        self::assertSame($rejectsEmpty, $presence->rejectsEmpty($tokens));
        self::assertSame($acceptsNull, $presence->acceptsNull($tokens));
    }

    /**
     * Provide fields beneath `address` with whether each refuses its absence.
     *
     * @return iterable<string, array{0: list<string>, 1: bool, 2: bool}>
     */
    public static function ancestorDemandProvider(): iterable
    {
        yield from [
            'required'                      => [['required', 'string'], true, true],
            'nullable required'             => [['nullable', 'required'], true, true],
            'present'                       => [['present'], true, true],
            'sometimes required'            => [['sometimes', 'required'], false, false],
            'excludable required'           => [['required', 'exclude_if:a,x'], false, false],
            'optional'                      => [['nullable', 'string'], false, false],
            'filled'                        => [['filled'], false, false],
            'required with within'          => [['required_with:address.zip'], false, false],
            'required with all within'      => [['required_with_all:address.zip'], false, false],
            'required without within'       => [['required_without:address.zip'], true, true],
            'required without deeper'       => [['required_without:address.zip.code'], true, true],
            'required without the ancestor' => [['required_without:address'], true, false],
            'required without an index'     => [['required_without:address.0'], true, false],
            'required without a big index'  => [['required_without:address.12'], true, false],
            'required without beneath one'  => [['required_without:address.0.zip'], true, false],
            'required without a padded key' => [['required_without:address.01'], true, true],
            'required without outside'      => [['required_without:other'], false, false],
            'required without a lookalike'  => [['required_without:addressee,addresses.zip'], false, false],
            'required without any within'   => [['required_without:other,address.zip'], true, true],
            'required without quoted'       => [['required_without:"address.zip"'], true, true],
            'required without a colon'      => [['required_without:other:x,address.zip'], true, true],
            'required without all within'   => [['required_without_all:address.zip,address.street'], true, true],
            'required without all indexed'  => [['required_without_all:address.zip,address.0'], true, false],
            'required without all mixed'    => [['required_without_all:address.zip,other'], false, false],
            'sometimes required without'    => [['required_without:address.zip', 'sometimes'], false, false],
            'required if'                   => [['required_if:address.type,x'], false, false],
        ];
    }

    /**
     * Test that a field refuses bodies missing or emptying its ancestor.
     *
     * @param  list<string>  $tokens
     * @param  bool  $ancestor
     * @param  bool  $namedKey
     * @return void
     */
    #[DataProvider('ancestorDemandProvider')]
    public function testAFieldDemandsAnAncestorItsRulesRequireWhenMissing(array $tokens, bool $ancestor, bool $namedKey): void
    {
        $presence = new FieldPresence;

        self::assertSame($ancestor, $presence->demandsAncestor($tokens, 'address'));
        self::assertSame($namedKey, $presence->demandsNamedKey($tokens, 'address'));
    }

    /**
     * Test that any exclude rule, whatever its condition, may drop a field.
     *
     * @return void
     */
    public function testAnyExcludeRuleMayDropAField(): void
    {
        $presence = new FieldPresence;

        self::assertTrue($presence->isExcludable(['string', 'exclude_without:a']));
        self::assertTrue($presence->isExcludable(['exclude']));
        self::assertFalse($presence->isExcludable(['required', 'string']));
    }
}
