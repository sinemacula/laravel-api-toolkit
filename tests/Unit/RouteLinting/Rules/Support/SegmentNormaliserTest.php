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
}
