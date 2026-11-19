<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesFieldExamples;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleDeclarationException;
use SineMacula\ApiToolkit\OpenApi\Schema\FieldExamples;
use Tests\Fixtures\OpenApi\ParityInput;

/**
 * Tests for the writer of a rules source's field examples.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FieldExamples::class)]
final class FieldExamplesTest extends TestCase
{
    /** The schema translated from the source's rules */
    private const array SCHEMA = [
        'type'       => 'object',
        'properties' => [
            'address' => ['type' => ['object', 'null'], 'properties' => ['city' => ['type' => 'string']]],
            'items'   => ['type' => 'array', 'items' => ['allOf' => [['enum' => ['a']], ['type' => 'object', 'properties' => ['sku' => ['type' => 'string']]]]]],
            'slots'   => ['type' => ['object', 'array'], 'properties' => ['0' => ['type' => 'integer']]],
            'retired' => false,
        ],
    ];

    /**
     * Test that a source without the contract leaves the schema as it is.
     *
     * @return void
     */
    public function testSourceWithoutExamplesLeavesTheSchema(): void
    {
        self::assertSame(self::SCHEMA, (new FieldExamples)->apply(self::SCHEMA, ParityInput::class));
    }

    /**
     * Test that each example lands on the node its rules key names, nested,
     * beneath a wildcard, at a list index or at a container.
     *
     * @return void
     */
    public function testExamplesLandOnTheNodesTheirKeysName(): void
    {
        $source = new class implements DefinesFieldExamples {
            /**
             * Return the example values keyed by rules key.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function fieldExamples(): array
            {
                return ['address.city' => 'Paris', 'items.*.sku' => 'A-1', 'slots.0' => 3, 'items' => [['sku' => 'B-2']]];
            }
        };

        $schema     = (new FieldExamples)->apply(self::SCHEMA, $source::class);
        $properties = $schema['properties'];

        self::assertSame(['Paris'], $properties['address']['properties']['city']['examples']);
        self::assertSame(['A-1'], $properties['items']['items']['allOf'][1]['properties']['sku']['examples']);
        self::assertSame([3], $properties['slots']['properties']['0']['examples']);
        self::assertSame([[['sku' => 'B-2']]], $properties['items']['examples']);
        self::assertFalse($properties['retired']);
    }

    /**
     * Provide keys naming no declared field, and the message each fails with.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function undeclaredProvider(): iterable
    {
        yield 'unknown field' => ['nickname', 'The field example [%s] on %s names no field its rules declare.'];
        yield 'unknown nested field' => ['address.zip', 'The field example [%s] on %s names no field its rules declare.'];
        yield 'wildcard on an object' => ['address.*', 'The field example [%s] on %s names no field its rules declare.'];
        yield 'beneath a scalar' => ['slots.0.value', 'The field example [%s] on %s names no field its rules declare.'];
        yield 'beneath a prohibition' => ['retired.reason', 'The field example [%s] on %s names no field its rules declare.'];
        yield 'prohibited field' => ['retired', 'The field example [%s] on %s names a field its rules prohibit.'];
    }

    /**
     * Test that an example naming no field, or a prohibited one, fails.
     *
     * @param  string  $key
     * @param  string  $message
     * @return void
     */
    #[DataProvider('undeclaredProvider')]
    public function testExampleForNoDeclaredFieldFails(string $key, string $message): void
    {
        $source = new class implements DefinesFieldExamples {
            /** @var string The key the example is given at. */
            public static string $key = '';

            /**
             * Return the example values keyed by rules key.
             *
             * @return array<string, mixed>
             */
            #[\Override]
            public static function fieldExamples(): array
            {
                return [self::$key => 'x'];
            }
        };

        $source::$key = $key;

        $this->expectException(ExampleDeclarationException::class);
        $this->expectExceptionMessage(sprintf($message, $key, $source::class));

        (new FieldExamples)->apply(self::SCHEMA, $source::class);
    }
}
