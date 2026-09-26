<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Introspection;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility;
use Tests\TestCase;

/**
 * Index eligibility tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(IndexEligibility::class)]
final class IndexEligibilityTest extends TestCase
{
    /**
     * Test that a report naming nothing leaves every index usable.
     *
     * An engine that cannot answer reports nothing, and that has to read as
     * silence rather than as a refusal of everything it was not asked about.
     *
     * @return void
     */
    public function testAReportNamingNothingLeavesEveryIndexUsable(): void
    {
        $eligibility = new IndexEligibility;

        self::assertFalse($eligibility->disregards('users_name_index'));
        self::assertFalse($eligibility->restricts('users_name_index'));
        self::assertFalse($eligibility->keysAnExpression('users_name_index'));
        self::assertFalse($eligibility->lacksColumnOrder('users_name_index'));
        self::assertTrue($eligibility->describes('users_name_index'));
    }

    /**
     * Test that each fact is reported apart from the others.
     *
     * The facts defeat different readers, so one of them holding must not make
     * the others answer for an index they were never told about.
     *
     * @return void
     */
    public function testEachFactIsReportedApartFromTheOthers(): void
    {
        $eligibility = new IndexEligibility(['hidden'], ['partial'], ['expression'], ['prefix']);

        self::assertTrue($eligibility->disregards('hidden'));
        self::assertFalse($eligibility->restricts('hidden'));
        self::assertFalse($eligibility->keysAnExpression('hidden'));

        self::assertTrue($eligibility->restricts('partial'));
        self::assertFalse($eligibility->disregards('partial'));
        self::assertFalse($eligibility->keysAnExpression('partial'));

        self::assertTrue($eligibility->keysAnExpression('expression'));
        self::assertFalse($eligibility->disregards('expression'));
        self::assertFalse($eligibility->restricts('expression'));
        self::assertFalse($eligibility->lacksColumnOrder('expression'));

        self::assertTrue($eligibility->lacksColumnOrder('prefix'));
        self::assertFalse($eligibility->disregards('prefix'));
        self::assertFalse($eligibility->restricts('prefix'));
        self::assertFalse($eligibility->keysAnExpression('prefix'));
    }

    /**
     * Test that a leading key lacking its column's order is still described.
     *
     * A truncated or pattern-class key still finds rows by its column, so a
     * proof reading the reported columns for anything other than order must
     * keep resting on it.
     *
     * @return void
     */
    public function testALeadingKeyLackingItsColumnsOrderIsStillDescribed(): void
    {
        $eligibility = new IndexEligibility([], [], [], ['users_name_prefix_index']);

        self::assertTrue($eligibility->lacksColumnOrder('users_name_prefix_index'));
        self::assertTrue($eligibility->describes('users_name_prefix_index'));
    }

    /**
     * Test that any one of the three facts defeats a proof reading the reported
     * columns.
     *
     * @return void
     */
    public function testAnyOneFactDefeatsAProofReadingTheReportedColumns(): void
    {
        self::assertFalse((new IndexEligibility(['one']))->describes('one'));
        self::assertFalse((new IndexEligibility([], ['two']))->describes('two'));
        self::assertFalse((new IndexEligibility([], [], ['three']))->describes('three'));
        self::assertTrue((new IndexEligibility(['one'], ['two'], ['three']))->describes('four'));
    }

    /**
     * Test that a name is matched whatever case either side names it in.
     *
     * The catalogue reports a name folded while a declaration may name one as
     * it was created, so comparing the two literally would let a name carrying
     * a capital slip past every fact reported about it.
     *
     * @return void
     */
    public function testMatchesANameWhateverCaseEitherSideNamesItIn(): void
    {
        $eligibility = new IndexEligibility(['USERS_Name_Index'], ['Users_Live_Index'], ['USERS_LOWER_INDEX'], ['Users_Prefix_INDEX']);

        self::assertTrue($eligibility->disregards('users_name_index'));
        self::assertTrue($eligibility->restricts('users_live_index'));
        self::assertTrue($eligibility->keysAnExpression('users_lower_index'));
        self::assertFalse($eligibility->describes('Users_Name_INDEX'));
        self::assertTrue($eligibility->lacksColumnOrder('USERS_prefix_index'));
    }
}
