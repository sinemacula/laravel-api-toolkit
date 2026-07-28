<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Schema\FieldSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser;
use SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator;
use Tests\Fixtures\OpenApi\ArticleRequestInput;
use Tests\TestCase;

/**
 * Tests for the rules-to-schema translator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RulesToSchemaTranslator::class)]
final class RulesToSchemaTranslatorTest extends TestCase
{
    /**
     * Test that the representative rules source translates to the full object
     * schema spanning presence, size, array items, enum, format, and the
     * confirmation sibling.
     *
     * @return void
     */
    public function testRepresentativeRulesTranslateToFullObjectSchema(): void
    {
        $schema = $this->translator()->translate(ArticleRequestInput::rules());

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'title'                 => ['type' => 'string', 'maxLength' => 255],
                'summary'               => ['type' => ['string', 'null']],
                'tags'                  => ['type' => 'array', 'items' => ['type' => 'string']],
                'status'                => ['enum' => ['active', 'inactive', 'banned']],
                'email'                 => ['type' => 'string', 'format' => 'email'],
                'password'              => ['type' => 'string', 'minLength' => 8],
                'password_confirmation' => ['type' => 'string'],
            ],
            'required' => ['title', 'email', 'password'],
        ], $schema);
    }

    /**
     * Test that the pipe-string and array rule forms translate identically.
     *
     * @return void
     */
    public function testPipeAndArrayFormsAgree(): void
    {
        $translator = $this->translator();

        self::assertSame(
            $translator->translate(['f' => 'required|string']),
            $translator->translate(['f' => ['required', 'string']]),
        );
    }

    /**
     * Test that dotted keys weave into a nested object property carrying its
     * own required list.
     *
     * @return void
     */
    public function testDottedKeysBuildNestedObject(): void
    {
        $schema = $this->translator()->translate(['address.city' => 'required|string']);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'address' => [
                    'type'       => 'object',
                    'properties' => ['city' => ['type' => 'string']],
                    'required'   => ['city'],
                ],
            ],
        ], $schema);
    }

    /**
     * Test that a field.* sibling supplies the items schema of an array field.
     *
     * @return void
     */
    public function testStarKeyBuildsArrayItems(): void
    {
        $schema = $this->translator()->translate(['tags' => ['array'], 'tags.*' => 'integer']);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'tags' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
        ], $schema);
    }

    /**
     * Test that a field.*.child grid weaves an array of objects, nesting each
     * child property and its required list under the items node.
     *
     * @return void
     */
    public function testStarChildGridBuildsArrayOfObjects(): void
    {
        $schema = $this->translator()->translate([
            'items'       => ['array'],
            'items.*.sku' => 'required|string',
            'items.*.qty' => 'integer',
        ]);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'items' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'sku' => ['type' => 'string'],
                            'qty' => ['type' => 'integer'],
                        ],
                        'required' => ['sku'],
                    ],
                ],
            ],
        ], $schema);
    }

    /**
     * Test that a confirmed field gains a confirmation sibling of the same base
     * type.
     *
     * @return void
     */
    public function testConfirmedFieldGainsSibling(): void
    {
        $schema = $this->translator()->translate(['password' => ['required', 'string', 'confirmed']]);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'password'              => ['type' => 'string'],
                'password_confirmation' => ['type' => 'string'],
            ],
            'required' => ['password'],
        ], $schema);
    }

    /**
     * Test that a confirmed field carrying no scalar type gains a string
     * confirmation sibling.
     *
     * @return void
     */
    public function testConfirmedTypelessFieldGainsStringSibling(): void
    {
        $schema = $this->translator()->translate(['status' => ['in:active,inactive', 'confirmed']]);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'status'              => ['enum' => ['active', 'inactive']],
                'status_confirmation' => ['type' => 'string'],
            ],
        ], $schema);
    }

    /**
     * Test that a confirmed field's confirmation sibling mirrors a non-string
     * base type verbatim.
     *
     * @return void
     */
    public function testConfirmedTypedFieldMirrorsBaseType(): void
    {
        $schema = $this->translator()->translate(['count' => ['integer', 'confirmed']]);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'count'              => ['type' => 'integer'],
                'count_confirmation' => ['type' => 'integer'],
            ],
        ], $schema);
    }

    /**
     * Test that an unrecognised rule is skipped while its field is kept.
     *
     * @return void
     */
    public function testUnknownRuleIsSkippedKeepingField(): void
    {
        $schema = $this->translator()->translate(['f' => 'string|foobar']);

        self::assertSame([
            'type'       => 'object',
            'properties' => ['f' => ['type' => 'string']],
        ], $schema);
    }

    /**
     * Test that keys nested more than one level deep are woven best-effort.
     *
     * @return void
     */
    public function testDeeperNestingIsWovenBestEffort(): void
    {
        $schema = $this->translator()->translate(['a.b.c' => 'string']);

        self::assertSame([
            'type'       => 'object',
            'properties' => [
                'a' => [
                    'type'       => 'object',
                    'properties' => [
                        'b' => [
                            'type'       => 'object',
                            'properties' => ['c' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
        ], $schema);
    }

    /**
     * Build a translator over the real normaliser and field builder.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator
     */
    private function translator(): RulesToSchemaTranslator
    {
        return new RulesToSchemaTranslator(new RuleNormaliser, new FieldSchemaBuilder);
    }
}
