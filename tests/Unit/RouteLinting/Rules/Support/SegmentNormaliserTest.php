<?php

namespace Tests\Unit\RouteLinting\Rules\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Contracts\Inflector;
use SineMacula\ApiToolkit\RouteLinting\Rules\Support\SegmentNormaliser;
use Tests\TestCase;

/**
 * Tests for the SegmentNormaliser 6-step pipeline.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SegmentNormaliser::class)]
class SegmentNormaliserTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\RouteLinting\Contracts\Inflector */
    private Inflector $inflector;

    /** @var \SineMacula\ApiToolkit\RouteLinting\Rules\Support\SegmentNormaliser */
    private SegmentNormaliser $normaliser;

    /**
     * Set up a stub inflector and normaliser before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Stub inflector: singularises by stripping a trailing 's', otherwise returns the word as-is
        $this->inflector = new class implements Inflector {
            /**
             * @param  string  $value
             * @return string
             */
            public function singular(string $value): string
            {
                return str_ends_with($value, 's') ? substr($value, 0, -1) : $value;
            }

            /**
             * @param  string  $value
             * @return bool
             */
            public function isPlural(string $value): bool
            {
                return str_ends_with($value, 's');
            }
        };

        $this->normaliser = new SegmentNormaliser($this->inflector);
    }

    /**
     * Test that api prefix, version segments, and route parameters are all dropped
     * so only meaningful resource words survive.
     *
     * @return void
     */
    public function testDropsParametersAndVersionAndApiPrefix(): void
    {
        // Arrange
        $uri = 'api/v1/{user}/getUsers';

        // Act
        $words = $this->normaliser->normalise($uri, []);

        // Assert — 'api', 'v1', '{user}' are dropped; 'getUsers' decomposes to 'get' + 'users' -> 'get' + 'user'
        static::assertSame(['get', 'user'], $words);
    }

    /**
     * Test that compound segments decompose correctly across camelCase, kebab, and snake boundaries.
     *
     * @return void
     */
    public function testDecomposesCompoundCamelKebabSnake(): void
    {
        // Arrange — three separate URIs each with a different delimiter style
        $camel = 'getUsers';
        $kebab = 'user-profiles';
        $snake = 'get_users';

        // Act
        $camelWords = $this->normaliser->normalise($camel, []);
        $kebabWords = $this->normaliser->normalise($kebab, []);
        $snakeWords = $this->normaliser->normalise($snake, []);

        // Assert — each decomposes into its constituent words (singularised by the stub)
        static::assertSame(['get', 'user'], $camelWords);
        static::assertSame(['user', 'profile'], $kebabWords);
        static::assertSame(['get', 'user'], $snakeWords);
    }

    /**
     * Test that words are lowercased and singularised via the injected inflector.
     *
     * @return void
     */
    public function testLowercasesAndSingularises(): void
    {
        // Arrange
        $uri = 'getUsers';

        // Act
        $words = $this->normaliser->normalise($uri, []);

        // Assert — 'getUsers' -> decomposed ['get', 'Users'] -> lowercased ['get', 'users'] -> singularised ['get', 'user']
        static::assertSame(['get', 'user'], $words);
    }

    /**
     * Test that a URI consisting only of parameters, version, and api segments yields an empty result.
     *
     * @return void
     */
    public function testOnlyParametersYieldsEmpty(): void
    {
        // Arrange
        $uri = 'api/v1/{user}';

        // Act
        $words = $this->normaliser->normalise($uri, []);

        // Assert
        static::assertSame([], $words);
    }

    /**
     * Test that an empty URI yields an empty result.
     *
     * @return void
     */
    public function testEmptyUriYieldsEmpty(): void
    {
        // Act
        $words = $this->normaliser->normalise('', []);

        // Assert
        static::assertSame([], $words);
    }

    /**
     * Test that a segment with mixed delimiters decomposes across all boundaries.
     *
     * @return void
     */
    public function testMixedDelimitersDecomposeAcrossAllBoundaries(): void
    {
        // Arrange — 'get_userProfiles' has both snake and camelCase boundaries
        $uri = 'get_userProfiles';

        // Act
        $words = $this->normaliser->normalise($uri, []);

        // Assert — decomposed to 'get', 'user', 'Profiles' -> lowercased -> singularised
        static::assertSame(['get', 'user', 'profile'], $words);
    }

    /**
     * Test that words present in the uncountables list bypass singularisation.
     *
     * @return void
     */
    public function testUncountableWordsBypassSingularisation(): void
    {
        // Arrange — 'media' ends in 's' so the stub would singularise it to 'medi',
        // but declaring it uncountable must prevent that
        $uri          = 'medias';
        $uncountables = ['medias'];

        // Act
        $words = $this->normaliser->normalise($uri, $uncountables);

        // Assert — 'medias' is returned as-is, not stripped to 'media' by the stub
        static::assertSame(['medias'], $words);
    }

    /**
     * Test that optional route parameters (with trailing ?) are dropped.
     *
     * @return void
     */
    public function testOptionalRouteParametersAreDropped(): void
    {
        // Arrange
        $uri = 'users/{user?}/posts';

        // Act
        $words = $this->normaliser->normalise($uri, []);

        // Assert — '{user?}' is a route parameter and must be discarded
        static::assertSame(['user', 'post'], $words);
    }

    /**
     * Test that a segment containing '{' but not ending at '}' is kept as a
     * literal — step 2 regex requires the segment to end exactly at '}'.
     *
     * Targets PregMatchRemoveDollar on the step-2 pattern: without '$' the
     * mutant would drop '{id}extra' (it starts with '{' and contains '}'),
     * but the original keeps it because '{}' is not at the end of the string.
     *
     * @return void
     */
    public function testSegmentWithBraceSuffixIsNotDroppedAsParameter(): void
    {
        // Arrange — '{id}extra' starts with '{' and contains '}' but is not a pure
        // route parameter; step 2 must keep it; `posts` is a normal resource segment
        $uri = '{id}extra/posts';

        // Act
        $words = $this->normaliser->normalise($uri, []);

        // Assert — '{id}extra' survives step 2 and passes through as a word;
        // step 4 treats it as a single segment (no camelCase/kebab/snake boundary),
        // step 5 lowercases it, step 6 singularises it (no trailing 's' so unchanged);
        // 'posts' → 'post' via stub singulariser
        static::assertSame(['{id}extra', 'post'], $words);
    }

    /**
     * Test that version-like prefix strings that contain digits but do not
     * start with 'v' are not dropped — step 3 regex requires the '^' anchor.
     *
     * Targets PregMatchRemoveCaret on the step-3 pattern: without '^' the
     * mutant matches any segment ending in 'v<digits>', dropping 'av2' even
     * though it is not a version prefix.
     *
     * @return void
     */
    public function testSegmentContainingVersionPatternMidstringIsKept(): void
    {
        // Arrange — 'av2' contains 'v2' but does not start with 'v', so it is
        // a real resource segment and must survive step 3
        $uri = 'av2/users';

        // Act
        $words = $this->normaliser->normalise($uri, []);

        // Assert — 'av2' is kept; 'users' singularises to 'user'
        static::assertSame(['av2', 'user'], $words);
    }

    /**
     * Test that a version segment with trailing literal characters is not dropped
     * — step 3 regex requires the '$' anchor so only pure version tokens are removed.
     *
     * Targets PregMatchRemoveDollar on the step-3 pattern: without '$' the
     * mutant matches 'v1more' (starts with 'v' followed by digits), dropping it
     * even though it is not a pure version prefix.
     *
     * @return void
     */
    public function testSegmentStartingWithVersionPatternButHasSuffixIsKept(): void
    {
        // Arrange — 'v1more' starts with 'v1' but has extra text, so it is not a
        // pure version segment and must survive step 3
        $uri = 'v1more/users';

        // Act
        $words = $this->normaliser->normalise($uri, []);

        // Assert — 'v1more' is kept; 'users' singularises to 'user'
        static::assertSame(['v1more', 'user'], $words);
    }

    /**
     * Test that version segments in uppercase are still dropped — step 3 uses
     * the 'i' flag for case-insensitive matching.
     *
     * Targets PregMatchRemoveFlags on the step-3 pattern: without 'i' the mutant
     * keeps 'V1' because the pattern '/^v\d+$/' does not match an uppercase 'V'.
     *
     * @return void
     */
    public function testUppercaseVersionSegmentIsDropped(): void
    {
        // Arrange — 'V1' is a version prefix in uppercase; must be dropped
        $uri = 'V1/users';

        // Act
        $words = $this->normaliser->normalise($uri, []);

        // Assert — 'V1' is dropped; only 'users' → 'user' survives
        static::assertSame(['user'], $words);
    }

    /**
     * Test that the 'api' prefix is dropped case-insensitively — step 3
     * applies strtolower() before comparing to 'api'.
     *
     * Targets UnwrapStrToLower on the step-3 strtolower(): without lowercasing,
     * 'API' would not equal 'api' and would be kept as a segment.
     *
     * @return void
     */
    public function testUppercaseApiPrefixIsDropped(): void
    {
        // Arrange — 'API' in uppercase must be treated as the API prefix and dropped
        $uri = 'API/users';

        // Act
        $words = $this->normaliser->normalise($uri, []);

        // Assert — 'API' is dropped case-insensitively; only 'users' → 'user' survives
        static::assertSame(['user'], $words);
    }
}
