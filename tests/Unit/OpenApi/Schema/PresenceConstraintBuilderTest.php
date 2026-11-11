<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\OpenApi\Schema\PresenceConstraintBuilder;
use Tests\TestCase;

/**
 * Tests for the presence constraint builder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PresenceConstraintBuilder::class)]
final class PresenceConstraintBuilderTest extends TestCase
{
    /**
     * Test that a required confirmed field requires its typed confirmation.
     *
     * @return void
     */
    public function testRequiredConfirmedFieldRequiresItsConfirmation(): void
    {
        $schema = $this->builder()->build(
            ['type' => 'object', 'properties' => ['password' => ['type' => 'string', 'minLength' => 8], 'name' => []], 'required' => ['password']],
            '',
            ['password' => ['required', 'confirmed'], 'name' => []],
        );

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'password'              => ['type' => 'string', 'minLength' => 8],
                'password_confirmation' => ['type' => 'string'],
                'name'                  => [],
            ],
            'required' => ['password', 'password_confirmation'],
        ], $schema);
    }

    /**
     * Test that an optional confirmed field requires confirmation when sent.
     *
     * @return void
     */
    public function testOptionalConfirmedFieldRequiresItsConfirmationWhenSent(): void
    {
        $schema = $this->builder()->build(
            ['type' => 'object', 'properties' => ['count' => ['type' => 'integer']]],
            '',
            ['count' => ['sometimes', 'integer', 'confirmed:']],
        );

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'count'              => ['type' => 'integer'],
                'count_confirmation' => ['type' => 'integer'],
            ],
            'dependentRequired' => ['count' => ['count_confirmation']],
        ], $schema);
    }

    /**
     * Test that a confirmation is a string when the field type is ambiguous.
     *
     * @return void
     */
    public function testConfirmationOfAnUntypedFieldIsAString(): void
    {
        $schema = $this->builder()->build(
            ['type' => 'object', 'properties' => ['a' => ['enum' => ['x']], 'b' => ['type' => ['string', 'null']]]],
            '',
            ['a' => ['confirmed'], 'b' => ['confirmed']],
        );

        self::assertSame(['type' => 'string'], $schema['properties']['a_confirmation']);
        self::assertSame(['type' => 'string'], $schema['properties']['b_confirmation']);
    }

    /**
     * Test that a confirmation mirrors each non-null type of its field.
     *
     * @return void
     */
    public function testConfirmationOfAMultiTypedFieldMirrorsItsTypes(): void
    {
        $schema = $this->builder()->build(
            ['type' => 'object', 'properties' => ['tags' => ['type' => ['object', 'array', 'null']], 'meta' => ['type' => ['null', 'object', 'array']]]],
            '',
            ['tags' => ['confirmed'], 'meta' => ['confirmed']],
        );

        self::assertSame(['type' => ['object', 'array']], $schema['properties']['tags_confirmation']);
        self::assertSame(['type' => ['object', 'array']], $schema['properties']['meta_confirmation']);
    }

    /**
     * Test that a named confirmation field replaces the default name.
     *
     * @return void
     */
    public function testConfirmedRuleNamingItsFieldRequiresThatSibling(): void
    {
        $schema = $this->builder()->build(
            ['type' => 'object', 'properties' => ['password' => ['type' => 'string'], 'name' => []], 'required' => ['password']],
            'user',
            ['password' => ['required', 'confirmed:user.repeat_password'], 'name' => []],
        );

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'password'        => ['type' => 'string'],
                'repeat_password' => ['type' => 'string'],
                'name'            => [],
            ],
            'required' => ['password', 'repeat_password'],
        ], $schema);
    }

    /**
     * Test that a confirmation shared across types is left unconstrained.
     *
     * @return void
     */
    public function testSharedConfirmationFieldAcceptsEachSourceType(): void
    {
        $schema = $this->builder()->build(
            ['type' => 'object', 'properties' => ['a' => ['type' => 'integer'], 'b' => ['type' => 'string'], 'c' => ['type' => 'string']]],
            '',
            ['a' => ['confirmed:repeat'], 'b' => ['confirmed:repeat', 'confirmed:again'], 'c' => ['confirmed:again']],
        );

        self::assertSame([], $schema['properties']['repeat']);
        self::assertSame(['type' => 'string'], $schema['properties']['again']);
        self::assertSame(['a' => ['repeat'], 'b' => ['repeat', 'again'], 'c' => ['again']], $schema['dependentRequired']);
    }

    /**
     * Test that a declared confirmation field keeps its own schema and place.
     *
     * @return void
     */
    public function testDeclaredConfirmationKeepsItsSchemaAndIsRequiredOnce(): void
    {
        $schema = $this->builder()->build(
            [
                'type'       => 'object',
                'properties' => ['password_confirmation' => ['type' => 'string', 'minLength' => 8], 'password' => ['type' => 'string']],
                'required'   => ['password_confirmation', 'password'],
            ],
            '',
            ['password_confirmation' => ['required'], 'password' => ['required', 'confirmed']],
        );

        self::assertSame([
            'type'       => 'object',
            'properties' => ['password_confirmation' => ['type' => 'string', 'minLength' => 8], 'password' => ['type' => 'string']],
            'required'   => ['password_confirmation', 'password'],
        ], $schema);
    }

    /**
     * Test that an out-of-object confirmation field is skipped.
     *
     * @return void
     */
    public function testConfirmationNamedOutsideTheObjectIsSkipped(): void
    {
        $schema = $this->builder()->build(
            ['type' => 'object', 'properties' => ['password' => ['type' => 'string']]],
            'user',
            ['password' => ['confirmed:repeat', 'confirmed:user.', 'confirmed:user.extra.repeat', 'confirmed:username', 'confirmed']],
        );

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'password'              => ['type' => 'string'],
                'password_confirmation' => ['type' => 'string'],
            ],
            'dependentRequired' => ['password' => ['password_confirmation']],
        ], $schema);
    }

    /**
     * Test that a never-sendable field gains no confirmation sibling.
     *
     * @return void
     */
    public function testFieldThatMayNeverBeSentStillConstrainsTheFieldsItNames(): void
    {
        $schema = $this->builder()->build(
            ['type' => 'object', 'properties' => ['f' => false, 'a' => [], 'p' => []]],
            '',
            ['f' => ['confirmed', 'required_with:a', 'required_without:a'], 'a' => [], 'p' => ['confirmed', 'required_with:a']],
        );

        self::assertSame([
            'type'              => 'object',
            'properties'        => ['f' => false, 'a' => [], 'p' => [], 'p_confirmation' => ['type' => 'string']],
            'dependentRequired' => ['p' => ['p_confirmation'], 'a' => ['f', 'p']],
            'anyOf'             => [['required' => ['f']], ['required' => ['a']]],
        ], $schema);
    }

    /**
     * Test that an excludable field requires neither itself nor confirmation.
     *
     * @return void
     */
    public function testExcludableFieldRequiresNothing(): void
    {
        $schema = $this->builder()->build(
            ['type' => 'object', 'properties' => ['a' => [], 'f' => [], 'p' => ['type' => 'string']], 'required' => ['p']],
            '',
            ['a' => [], 'f' => ['exclude_if:a,1', 'required_with:a'], 'p' => ['required', 'exclude', 'confirmed']],
        );

        self::assertSame([
            'type'       => 'object',
            'properties' => ['a' => [], 'f' => [], 'p' => ['type' => 'string'], 'p_confirmation' => ['type' => 'string']],
            'required'   => ['p'],
        ], $schema);
    }

    /**
     * Provide each conditional presence rule with the constraint it adds.
     *
     * @return iterable<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function conditionalRules(): iterable
    {
        yield 'required_with one field' => ['required_with:a', ['dependentRequired' => ['a' => ['f']]]];
        yield 'required_with several fields' => ['required_with:a,b', ['dependentRequired' => ['a' => ['f'], 'b' => ['f']]]];
        yield 'required_with_all one field' => ['required_with_all:a', ['dependentRequired' => ['a' => ['f']]]];
        yield 'required_with_all several fields' => ['required_with_all:a,b', ['if' => ['required' => ['a', 'b']], 'then' => ['required' => ['f']]]];
        yield 'required_without one field' => ['required_without:a', ['anyOf' => [['required' => ['f']], ['required' => ['a']]]]];
        yield 'required_without several fields' => ['required_without:a,b', ['anyOf' => [['required' => ['f']], ['required' => ['a', 'b']]]]];
        yield 'required_without_all one field' => ['required_without_all:a', ['anyOf' => [['required' => ['f']], ['required' => ['a']]]]];
        yield 'required_without_all several fields' => ['required_without_all:a,b', ['anyOf' => [['required' => ['f']], ['required' => ['a']], ['required' => ['b']]]]];
    }

    /**
     * Test that each conditional presence rule adds its constraint.
     *
     * @param  string  $rule
     * @param  array<string, mixed>  $expected
     * @return void
     */
    #[DataProvider('conditionalRules')]
    public function testConditionalRuleAddsItsConstraint(string $rule, array $expected): void
    {
        $schema = $this->build(['a' => [], 'b' => [], 'f' => [$rule]]);

        self::assertSame(['type' => 'object', 'properties' => ['a' => [], 'b' => [], 'f' => []], ...$expected], $schema);
    }

    /**
     * Test that a required or skippable field adds no conditional constraint.
     *
     * @return void
     */
    public function testRequiredOrSometimesFieldAddsNoConditionalConstraint(): void
    {
        $schema = $this->build(
            ['a' => [], 'f' => ['required', 'required_without:a'], 'g' => ['sometimes', 'required_with:a'], 'h' => ['string', 'required_with:a']],
            ['f'],
        );

        self::assertSame([
            'type'              => 'object',
            'properties'        => ['a' => [], 'f' => [], 'g' => [], 'h' => []],
            'required'          => ['f'],
            'dependentRequired' => ['a' => ['h']],
        ], $schema);
    }

    /**
     * Test that several conditions combine under allOf in declared order.
     *
     * @return void
     */
    public function testSeveralConditionsCombineUnderAllOf(): void
    {
        $schema = $this->build([
            'a' => [],
            'b' => [],
            'f' => ['required_without:a,b', 'required_with_all:a,b'],
            'g' => ['required_without_all:a,b'],
        ]);

        self::assertSame([
            ['anyOf' => [['required' => ['f']], ['required' => ['a', 'b']]]],
            ['if' => ['required' => ['a', 'b']], 'then' => ['required' => ['f']]],
            ['anyOf' => [['required' => ['g']], ['required' => ['a']], ['required' => ['b']]]],
        ], $schema['allOf']);
        self::assertArrayNotHasKey('anyOf', $schema);
        self::assertArrayNotHasKey('if', $schema);
    }

    /**
     * Test that a repeated condition is emitted once as first stated.
     *
     * @return void
     */
    public function testRepeatedConditionIsEmittedOnce(): void
    {
        $schema = $this->build([
            'a'     => [],
            'b'     => [],
            'email' => ['required_without:phone'],
            'phone' => ['required_without:email'],
            'f'     => ['required_without:a,b', 'required_without:b,a', 'required_with_all:a,b', 'required_with_all:b,a', 'required_without:a'],
        ]);

        self::assertSame([
            ['anyOf' => [['required' => ['email']], ['required' => ['phone']]]],
            ['anyOf' => [['required' => ['f']], ['required' => ['a', 'b']]]],
            ['if' => ['required' => ['a', 'b']], 'then' => ['required' => ['f']]],
            ['anyOf' => [['required' => ['f']], ['required' => ['a']]]],
        ], $schema['allOf']);
    }

    /**
     * Test that a lone condition on an existing keyword goes under allOf.
     *
     * @return void
     */
    public function testLoneConditionClashingWithTheSchemaIsPlacedUnderAllOf(): void
    {
        $schema = $this->builder()->build(
            ['anyOf' => [['type' => 'object'], ['type' => 'null']], 'type' => 'object', 'properties' => ['a' => [], 'f' => []]],
            '',
            ['a' => [], 'f' => ['required_without:a']],
        );

        self::assertSame([['type' => 'object'], ['type' => 'null']], $schema['anyOf']);
        self::assertSame([['anyOf' => [['required' => ['f']], ['required' => ['a']]]]], $schema['allOf']);
    }

    /**
     * Test that confirmed and required_with on one trigger merge.
     *
     * @return void
     */
    public function testDependentRequirementsMergePerTriggerWithoutRepeats(): void
    {
        $schema = $this->build([
            'password'              => ['confirmed'],
            'password_confirmation' => ['required_with:password'],
            'token'                 => ['required_with:password,password', 'required_with:password'],
            'email'                 => ['required_with:password'],
        ]);

        self::assertSame(
            ['password' => ['password_confirmation', 'token', 'email']],
            $schema['dependentRequired'],
        );
    }

    /**
     * Test that any-field rules keep placeable references, all-field ones skip.
     *
     * @return void
     */
    public function testUnplaceableReferencesAreSkipped(): void
    {
        $schema = $this->build([
            'a' => [],
            'f' => ['required_with:other.a,', 'required_with:missing,a'],
            'g' => ['required_without:missing', 'required_without:a,missing'],
            'h' => ['required_with_all:a,missing', 'required_without_all:a,missing', 'required_with_all:a,'],
            'i' => ['required_with_all:a,a'],
        ]);

        self::assertSame([
            'type'              => 'object',
            'properties'        => ['a' => [], 'f' => [], 'g' => [], 'h' => [], 'i' => []],
            'dependentRequired' => ['a' => ['f', 'i']],
            'anyOf'             => [['required' => ['g']], ['required' => ['a']]],
        ], $schema);
    }

    /**
     * Test that array item fields reference one another by wildcard path.
     *
     * @return void
     */
    public function testArrayItemSiblingsResolveByTheirWildcardPaths(): void
    {
        $schema = $this->build(['a' => ['required_with:items.*.b,b'], 'b' => [], 'c' => ['required_without:b']], [], 'items.*');

        self::assertSame(['b' => ['a']], $schema['dependentRequired']);
        self::assertArrayNotHasKey('anyOf', $schema);
    }

    /**
     * Test that a referenced field name carrying a colon is read whole.
     *
     * @return void
     */
    public function testReferenceCarryingAColonIsReadWhole(): void
    {
        $schema = $this->build(['a' => [], 'a:b' => [], 'f' => ['required_with:a:b']]);

        self::assertSame(['a:b' => ['f']], $schema['dependentRequired']);
    }

    /**
     * Test that a quoted reference is read as one field name.
     *
     * @return void
     */
    public function testQuotedReferenceIsReadAsOneFieldName(): void
    {
        $schema = $this->build(['a' => [], 'a,b' => [], 'f' => ['required_with:"a,b"']]);

        self::assertSame(['a,b' => ['f']], $schema['dependentRequired']);
    }

    /**
     * Test that integer-keyed property names are constrained too.
     *
     * @return void
     */
    public function testNumericPropertyNamesAreConstrained(): void
    {
        $schema = $this->build(['1' => ['confirmed'], '2' => ['required_with:1']]);

        self::assertSame(['1' => ['1_confirmation', '2']], $schema['dependentRequired']);
    }

    /**
     * Test that an object without properties gains only an empty map.
     *
     * @return void
     */
    public function testObjectWithoutPropertiesGainsNoConstraint(): void
    {
        self::assertSame(['type' => 'object', 'properties' => []], $this->builder()->build(['type' => 'object'], '', []));
    }

    /**
     * Build an object of empty-schema fields and add its constraints.
     *
     * @param  array<array-key, list<string>>  $tokens
     * @param  list<string>  $required
     * @param  string  $path
     * @return array<string, mixed>
     */
    private function build(array $tokens, array $required = [], string $path = ''): array
    {
        $object = ['type' => 'object', 'properties' => array_map(static fn (): array => [], $tokens)];

        if ($required !== []) {
            $object['required'] = $required;
        }

        return $this->builder()->build($object, $path, $tokens);
    }

    /**
     * Build the presence constraint builder under test.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Schema\PresenceConstraintBuilder
     */
    private function builder(): PresenceConstraintBuilder
    {
        return new PresenceConstraintBuilder;
    }
}
