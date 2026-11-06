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
     * Test that a required confirmed field adds its confirmation sibling, of
     * the field's base type, to the required list.
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
     * Test that an optional confirmed field requires its confirmation only when
     * the field itself is sent.
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
     * Test that a confirmation sibling is a string when the confirmed field
     * carries no single scalar type.
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
     * Test that a confirmed rule naming its confirmation field adds and
     * requires that sibling instead of the default name.
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
     * Test that a declared confirmation field keeps its own schema and place
     * while still being required alongside the confirmed field, once.
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
     * Test that a confirmation field named outside the object is skipped, while
     * another confirmed rule on the same field is still applied.
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
     * Test that a field that may never be sent gains no confirmation sibling
     * and expresses none of its conditional presence rules.
     *
     * @return void
     */
    public function testFieldThatMayNeverBeSentAddsNoConstraint(): void
    {
        $object = ['type' => 'object', 'properties' => ['a' => [], 'f' => false]];

        self::assertSame($object, $this->builder()->build($object, '', ['a' => [], 'f' => ['confirmed', 'required_with:a', 'required_without:a']]));
    }

    /**
     * Provide each conditional presence rule over one and over several fields
     * with the constraint it adds to the object.
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
     * Test that each conditional presence rule, naming one or several sibling
     * fields, adds the constraint the validator applies.
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
     * Test that a required field or one the validator skips when absent adds no
     * conditional constraint, since the rule never changes its presence.
     *
     * @return void
     */
    public function testRequiredOrSometimesFieldAddsNoConditionalConstraint(): void
    {
        $schema = $this->build(['a' => [], 'f' => ['required', 'required_without:a'], 'g' => ['sometimes', 'required_with:a']], ['f']);

        self::assertSame(['type' => 'object', 'properties' => ['a' => [], 'f' => [], 'g' => []], 'required' => ['f']], $schema);
    }

    /**
     * Test that several conditions on one object combine under allOf in the
     * order the fields declare them, rather than overwriting one another.
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
     * Test that a condition stated more than once, from either side or in
     * another order, is emitted once as first stated.
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
            'f'     => ['required_without:a,b', 'required_without:b,a', 'required_with_all:a,b', 'required_with_all:b,a'],
        ]);

        self::assertSame([
            ['anyOf' => [['required' => ['email']], ['required' => ['phone']]]],
            ['anyOf' => [['required' => ['f']], ['required' => ['a', 'b']]]],
            ['if' => ['required' => ['a', 'b']], 'then' => ['required' => ['f']]],
        ], $schema['allOf']);
    }

    /**
     * Test that a lone condition whose keyword the object schema already
     * carries is placed under allOf instead of replacing it.
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
     * Test that the requirements of confirmed and required_with rules sharing a
     * trigger field merge into one entry without repeats.
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
     * Test that a rule needing any one of its fields keeps the references it
     * can place, while a rule needing all of them is skipped when one is
     * unplaceable, and a repeated reference still counts as placed.
     *
     * @return void
     */
    public function testUnplaceableReferencesAreSkipped(): void
    {
        $schema = $this->build([
            'a' => [],
            'f' => ['required_with:a,missing', 'required_with:other.a,'],
            'g' => ['required_without:a,missing', 'required_without:missing'],
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
     * Test that the fields of an array item object reference one another by
     * their wildcard paths, while a field outside the item is unplaceable.
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
     * Test that numeric property names, held as integer keys, are constrained
     * like any other.
     *
     * @return void
     */
    public function testNumericPropertyNamesAreConstrained(): void
    {
        $schema = $this->build(['1' => ['confirmed'], '2' => ['required_with:1']]);

        self::assertSame(['1' => ['1_confirmation', '2']], $schema['dependentRequired']);
    }

    /**
     * Test that an object without properties is returned with an empty
     * properties map and nothing else added.
     *
     * @return void
     */
    public function testObjectWithoutPropertiesGainsNoConstraint(): void
    {
        self::assertSame(['type' => 'object', 'properties' => []], $this->builder()->build(['type' => 'object'], '', []));
    }

    /**
     * Build an object whose every field has an empty schema from the given
     * tokens, then add its presence constraints.
     *
     * @param  array<string, list<string>>  $tokens
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
