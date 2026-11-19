<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Concurrency;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Concurrency\EntityTag;
use SineMacula\ApiToolkit\Http\Concurrency\IfMatch;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\ServiceContext;
use Tests\Fixtures\Models\Document;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Services\StubActor;
use Tests\TestCase;

/**
 * Tests for the IfMatch class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(IfMatch::class)]
final class IfMatchTest extends TestCase
{
    /**
     * Test that a request without the field sets no precondition.
     *
     * @return void
     */
    public function testAbsentFieldSetsNoPrecondition(): void
    {
        self::assertNull(IfMatch::fromRequest(Request::create('/')));
    }

    /**
     * Provide field lines that accept the document's current tag.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function matchingProvider(): iterable
    {
        yield 'wildcard' => [['*']];
        yield 'padded wildcard' => [[' * ']];
        yield 'weak tag' => [['W/"%s"']];
        yield 'strong form of the tag' => [['"%s"']];
        yield 'list with spaces' => [['"other" ,  W/"%s" , "another"']];
        yield 'empty list members' => [[', "other",, "%s",']];
        yield 'tag on a later line' => [['"other"', '"%s"']];
        yield 'quoted commas and asterisks in one tag' => [['"foo,*,bar", "%s"']];
    }

    /**
     * Test that a well-formed field naming the current tag, or the wildcard,
     * matches.
     *
     * @param  list<string>  $lines
     * @return void
     */
    #[DataProvider('matchingProvider')]
    public function testFieldNamingTheCurrentTagMatches(array $lines): void
    {
        self::assertTrue($this->ifMatch($lines)->matches($this->document()));
    }

    /**
     * Provide field lines that accept nothing the document can be.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function failingProvider(): iterable
    {
        yield 'blank field' => [['']];
        yield 'whitespace field' => [['  ']];
        yield 'only separators' => [[' , ,']];
        yield 'other tag' => [['"other"']];
        yield 'quoted wildcard' => [['"foo,*,bar"']];
        yield 'wildcard beside a tag' => [['*, "%s"']];
        yield 'wildcard on its own line beside a tag' => [['*', '"%s"']];
        yield 'unquoted member' => [['%s']];
        yield 'unquoted member beside the tag' => [['"%s", other']];
        yield 'unterminated quote' => [['"%s']];
        yield 'tags without a separator' => [['"other""%s"']];
        yield 'lowercase weak prefix' => [['w/"%s"']];
        yield 'quote inside a member' => [['"a"b", "%s"']];
        yield 'trailing text after the tag' => [['"%s" x']];
        yield 'trailing newline' => [["\"%s\"\n"]];
        yield 'wildcard with a trailing newline' => [["*\n"]];
        yield 'wildcard after a vertical tab' => [["\v*"]];
    }

    /**
     * Test that a malformed field, or one naming another tag, matches nothing.
     *
     * @param  list<string>  $lines
     * @return void
     */
    #[DataProvider('failingProvider')]
    public function testMalformedOrStaleFieldMatchesNothing(array $lines): void
    {
        self::assertFalse($this->ifMatch($lines)->matches($this->document()));
    }

    /**
     * Test that a model that is not versioned never matches a tag, but passes
     * the wildcard.
     *
     * @return void
     */
    public function testModelThatIsNotVersionedOnlyMatchesTheWildcard(): void
    {
        $tag = (new Tag)->setRawAttributes(['id' => 1], true);

        self::assertFalse($this->ifMatch(['W/"' . sha1(Tag::class . '|1|') . '"'])->matches($tag));
        self::assertTrue($this->ifMatch(['*'])->matches($tag));
    }

    /**
     * Test that the joined field value is read from every line, or null when
     * none is sent.
     *
     * @return void
     */
    public function testHeaderValueJoinsEveryLine(): void
    {
        $request = Request::create('/');

        self::assertNull(IfMatch::headerValue($request));

        $request->headers->set('If-Match', ['"a"', 'W/"b"']);

        self::assertSame('"a",W/"b"', IfMatch::headerValue($request));
    }

    /**
     * Test that the field captured on a context is read back, and that its
     * absence sets no precondition.
     *
     * @return void
     */
    public function testFromContextReadsTheCapturedField(): void
    {
        $tag = EntityTag::for($this->document());

        self::assertNull(IfMatch::fromContext($this->context([])));
        self::assertNull(IfMatch::fromContext($this->context([ServiceContext::METADATA_IF_MATCH => null])));
        self::assertTrue(IfMatch::fromContext($this->context([ServiceContext::METADATA_IF_MATCH => (string) $tag]))?->matches($this->document()));
    }

    /**
     * Test that a captured field that is not a string matches nothing.
     *
     * @return void
     */
    public function testFromContextRefusesAFieldThatIsNotAString(): void
    {
        $ifMatch = IfMatch::fromContext($this->context([ServiceContext::METADATA_IF_MATCH => ['*']]));

        self::assertNotNull($ifMatch);
        self::assertFalse($ifMatch->matches($this->document()));
    }

    /**
     * Read the precondition the given field lines set, each "%s" standing for
     * the document's opaque tag.
     *
     * @param  list<string>  $lines
     * @return \SineMacula\ApiToolkit\Http\Concurrency\IfMatch
     */
    private function ifMatch(array $lines): IfMatch
    {
        $opaque  = substr((string) EntityTag::for($this->document()), 3, -1);
        $request = Request::create('/');

        $request->headers->set('If-Match', array_map(static fn (string $line): string => str_replace('%s', $opaque, $line), $lines));

        $ifMatch = IfMatch::fromRequest($request);

        self::assertNotNull($ifMatch);

        return $ifMatch;
    }

    /**
     * Build a stored document at version 6.
     *
     * @return \Tests\Fixtures\Models\Document
     */
    private function document(): Document
    {
        return (new Document)->setRawAttributes(['id' => 4, 'version' => 6], true);
    }

    /**
     * Build a context carrying the given metadata.
     *
     * @param  array<string, mixed>  $metadata
     * @return \SineMacula\ApiToolkit\Services\ServiceContext
     */
    private function context(array $metadata): ServiceContext
    {
        return ServiceContext::for(new StubActor, ServiceSource::HTTP, $metadata);
    }
}
