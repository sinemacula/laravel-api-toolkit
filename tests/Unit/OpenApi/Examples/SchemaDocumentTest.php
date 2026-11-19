<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Examples;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Examples\SchemaDocument;

/**
 * Tests for the offline schema document.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SchemaDocument::class)]
final class SchemaDocumentTest extends TestCase
{
    /**
     * Test that a node is read through escaped segments, and a missing one is
     * null.
     *
     * @return void
     */
    public function testNodeIsReadThroughEscapedSegments(): void
    {
        $document = SchemaDocument::of(['paths' => ['/users/{user}' => ['get' => ['a~b' => 1]]]]);

        self::assertSame(1, $document->node('/paths/~1users~1{user}/get/a~0b'));
        self::assertSame(['a~b' => 1], $document->node('/paths/~1users~1{user}/get'));
        self::assertNull($document->node('/paths/~1users/get'));
        self::assertNull($document->node('/paths/~1users~1{user}/get/a~0b/c'));
        self::assertSame(['paths' => ['/users/{user}' => ['get' => ['a~b' => 1]]]], $document->node(''));
    }

    /**
     * Test that an appended segment is escaped.
     *
     * @return void
     */
    public function testAppendEscapesTheSegment(): void
    {
        self::assertSame('/paths/~1users~0all', SchemaDocument::append('/paths', '/users~all'));
        self::assertSame('/items/0', SchemaDocument::append('/items', 0));
    }

    /**
     * Test that a valid value has no mismatch, its references followed across
     * components.
     *
     * @return void
     */
    public function testValidValueHasNoMismatch(): void
    {
        $document = SchemaDocument::of([
            'paths'      => ['/users' => ['get' => ['schema' => ['$ref' => '#/components/schemas/User']]]],
            'components' => ['schemas' => ['User' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]]],
        ]);

        self::assertNull($document->mismatch(['id' => 1], '/paths/~1users/get/schema'));
    }

    /**
     * Test that a mismatch names the deepest failure and where it lies.
     *
     * @return void
     */
    public function testMismatchNamesTheDeepestFailureAndItsLocation(): void
    {
        $document = SchemaDocument::of(['components' => ['schemas' => [
            'Envelope' => ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/Status']], 'required' => ['data']],
            'Status'   => ['type' => 'object', 'properties' => ['status' => ['type' => 'string']]],
        ]]]);

        self::assertSame(
            'at /data/status: The data (integer) must match the type: string',
            $document->mismatch(['data' => ['status' => 1]], '/components/schemas/Envelope'),
        );
        self::assertSame(
            'at the root: The required properties (data) are missing',
            $document->mismatch(new \stdClass, '/components/schemas/Envelope'),
        );
    }

    /**
     * Test that known formats are asserted and unknown ones and extensions are
     * ignored.
     *
     * @return void
     */
    public function testKnownFormatsAreAssertedAndUnknownOnesIgnored(): void
    {
        $document = SchemaDocument::of(['components' => ['schemas' => [
            'Email' => ['type' => 'string', 'format' => 'email'],
            'Ulid'  => ['type' => 'string', 'format' => 'ulid', 'x-query-surface' => ['filter' => true]],
        ]]]);

        self::assertNotNull($document->mismatch('not an email', '/components/schemas/Email'));
        self::assertNull($document->mismatch('not a ulid', '/components/schemas/Ulid'));
    }

    /**
     * Test that a default never fills a missing required property.
     *
     * @return void
     */
    public function testDefaultDoesNotSatisfyRequired(): void
    {
        $document = SchemaDocument::of(['components' => ['schemas' => [
            'Defaulted' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer', 'default' => 1]], 'required' => ['x']],
        ]]]);

        self::assertSame('at the root: The required properties (x) are missing', $document->mismatch(new \stdClass, '/components/schemas/Defaulted'));
    }

    /**
     * Test that a value is validated as it is written: an empty list is not an
     * object, while an empty object and an associative array are.
     *
     * @return void
     */
    public function testValuesAreValidatedAsWritten(): void
    {
        $document = SchemaDocument::of(['components' => ['schemas' => ['Object' => ['type' => 'object']]]]);

        self::assertNull($document->mismatch(new \stdClass, '/components/schemas/Object'));
        self::assertNull($document->mismatch(['a' => 1], '/components/schemas/Object'));
        self::assertSame('at the root: The data (array) must match the type: object', $document->mismatch([], '/components/schemas/Object'));
    }

    /**
     * Test that a schema with an empty enum accepts no value, wherever it is
     * nested, while its node reads as written.
     *
     * @return void
     */
    public function testEmptyEnumAcceptsNoValue(): void
    {
        $document = SchemaDocument::of(['components' => ['schemas' => [
            'Retired' => ['type' => 'string', 'enum' => []],
            'Keyed'   => ['type' => 'object', 'properties' => (object) ['0' => ['type' => 'string', 'enum' => []]]],
        ]]]);

        self::assertSame(['type' => 'string', 'enum' => []], $document->node('/components/schemas/Retired'));
        self::assertNotNull($document->mismatch('active', '/components/schemas/Retired'));
        self::assertNotNull($document->mismatch((object) ['0' => 'active'], '/components/schemas/Keyed'));
        self::assertNull($document->mismatch(new \stdClass, '/components/schemas/Keyed'));
    }
}
