<?php

declare(strict_types = 1);

namespace Tests\Unit\Search;

use Illuminate\Database\Grammar;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Search\ColumnCondition;
use Tests\TestCase;

/**
 * Tests for the ColumnCondition search condition.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ColumnCondition::class)]
final class ColumnConditionTest extends TestCase
{
    /**
     * Test that a pattern match is a like comparison with an escape clause.
     *
     * @return void
     */
    public function testEscapedPatternIsALikeComparisonNamingTheEscapeCharacter(): void
    {
        $condition = ColumnCondition::escapedPattern('users.name');

        self::assertSame('"users"."name" like ? escape \'!\'', $condition->getValue($this->grammar()));
    }

    /**
     * Test that a pattern match takes the comparison operator it is given.
     *
     * @return void
     */
    public function testEscapedPatternTakesTheGivenOperator(): void
    {
        $condition = ColumnCondition::escapedPattern('users.name', 'ilike');

        self::assertSame('"users"."name" ilike ? escape \'!\'', $condition->getValue($this->grammar()));
    }

    /**
     * Test that a full-text match reads every column in boolean mode.
     *
     * @return void
     */
    public function testBooleanFullTextMatchesEveryColumnAgainstTheBoundPhrase(): void
    {
        $condition = ColumnCondition::booleanFullText(['users.name', 'users.email']);

        self::assertSame('match ("users"."name", "users"."email") against (? in boolean mode)', $condition->getValue($this->grammar()));
    }

    /**
     * Return the grammar of the test connection.
     *
     * @return \Illuminate\Database\Grammar
     */
    private function grammar(): Grammar
    {
        return DB::connection()->getQueryGrammar();
    }
}
