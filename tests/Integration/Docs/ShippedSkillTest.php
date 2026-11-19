<?php

declare(strict_types = 1);

namespace Tests\Integration\Docs;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Tests that each shipped skill is well formed and names only commands,
 * classes, config keys and documentation sections that exist.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
final class ShippedSkillTest extends TestCase
{
    /**
     * Provide the path of every shipped skill.
     *
     * @return iterable<string, array{string}>
     */
    public static function skills(): iterable
    {
        foreach (glob(self::path('resources/boost/skills/*/SKILL.md')) ?: [] as $skill) {
            yield basename(dirname($skill)) => [$skill];
        }
    }

    /**
     * Test that the frontmatter is a mapping naming the skill after its
     * directory.
     *
     * @param  string  $skill
     * @return void
     */
    #[DataProvider('skills')]
    public function testFrontmatterNamesTheSkillAfterItsDirectory(string $skill): void
    {
        $frontmatter = self::frontmatter($skill);
        $name        = $frontmatter['name'] ?? null;

        self::assertFalse(array_is_list($frontmatter));
        self::assertIsString($name);
        self::assertSame(basename(dirname($skill)), $name);
        self::assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name);
    }

    /**
     * Test that the description is a non-empty string within the format's
     * length limit.
     *
     * @param  string  $skill
     * @return void
     */
    #[DataProvider('skills')]
    public function testDescriptionIsANonEmptyStringWithinTheLengthLimit(string $skill): void
    {
        $description = self::frontmatter($skill)['description'] ?? null;

        self::assertIsString($description);
        self::assertNotSame('', trim($description));
        self::assertLessThanOrEqual(1024, mb_strlen($description));
    }

    /**
     * Test that every Artisan command the skill runs is registered.
     *
     * @param  string  $skill
     * @return void
     */
    #[DataProvider('skills')]
    public function testEveryNamedCommandIsRegistered(string $skill): void
    {
        $commands = self::captures('/php artisan ([a-z][a-z:-]*[a-z])/', self::body($skill));

        self::assertNotEmpty($commands);
        self::assertSame([], array_values(array_diff($commands, array_keys(Artisan::all()))));
    }

    /**
     * Test that every fully qualified class the skill names or imports exists.
     *
     * @param  string  $skill
     * @return void
     */
    #[DataProvider('skills')]
    public function testEveryNamedClassExists(string $skill): void
    {
        $body    = self::body($skill);
        $classes = [...self::captures('/^use (SineMacula\\\[\w\\\]+);$/m', $body), ...self::captures('/`\\\?(SineMacula\\\[\w\\\]+)`/', $body)];

        self::assertNotEmpty($classes);
        self::assertSame([], array_values(array_filter(
            $classes,
            static fn (string $class): bool => !class_exists($class) && !interface_exists($class) && !enum_exists($class) && !trait_exists($class),
        )));
    }

    /**
     * Test that every config key the skill names is in the shipped config file.
     *
     * @param  string  $skill
     * @return void
     */
    #[DataProvider('skills')]
    public function testEveryNamedConfigKeyExists(string $skill): void
    {
        $config = require self::path('config/api-toolkit.php');
        $keys   = self::captures('/`api-toolkit\.([\w.]+)`/', self::body($skill));

        self::assertIsArray($config);
        self::assertNotEmpty($keys);
        self::assertSame([], array_values(array_filter($keys, static fn (string $key): bool => !Arr::has($config, $key))));
    }

    /**
     * Test that every README and UPGRADE section the skill cites is a heading
     * of that document.
     *
     * @param  string  $skill
     * @return void
     */
    #[DataProvider('skills')]
    public function testEveryCitedSectionIsAHeadingOfItsDocument(string $skill): void
    {
        preg_match_all('/\b(README|UPGRADE) "([^"]+)"/', (string) preg_replace('/\s+/', ' ', self::body($skill)), $citations, PREG_SET_ORDER);

        self::assertNotEmpty($citations);

        $headings = ['README' => self::headings('README.md'), 'UPGRADE' => self::headings('UPGRADE.md')];
        $missing  = array_filter($citations, static fn (array $citation): bool => !in_array($citation[2], $headings[$citation[1]], true));

        self::assertSame([], array_values(array_map(static fn (array $citation): string => $citation[0], $missing)));
    }

    /**
     * Test that every relative link resolves to a file inside the skill's own
     * directory, the only part of the package copied into an application.
     *
     * @param  string  $skill
     * @return void
     */
    #[DataProvider('skills')]
    public function testEveryRelativeLinkResolvesInsideTheSkillDirectory(string $skill): void
    {
        $directory = dirname($skill) . '/';
        $links     = array_filter(
            self::captures('/\]\(([^)#\s]+)/', self::body($skill)),
            static fn (string $link): bool => preg_match('/^[a-z][a-z0-9+.-]*:/i', $link) !== 1,
        );

        $broken = array_filter($links, static function (string $link) use ($directory): bool {
            $target = realpath($directory . $link);

            return $target === false || !is_file($target) || !str_starts_with($target, $directory);
        });

        self::assertSame([], array_values($broken));
    }

    /**
     * Return the parsed frontmatter of the given skill.
     *
     * @param  string  $skill
     * @return array<array-key, mixed>
     */
    private static function frontmatter(string $skill): array
    {
        self::assertSame(1, preg_match('/\A---\n(.*?)\n---\n/s', (string) file_get_contents($skill), $match));

        $frontmatter = Yaml::parse($match[1]);

        self::assertIsArray($frontmatter);

        return $frontmatter;
    }

    /**
     * Return the Markdown of the given skill after its frontmatter.
     *
     * @param  string  $skill
     * @return string
     */
    private static function body(string $skill): string
    {
        return (string) preg_replace('/\A---\n.*?\n---\n/s', '', (string) file_get_contents($skill));
    }

    /**
     * Return the headings of the given repository document, outside its code.
     *
     * @param  string  $document
     * @return list<string>
     */
    private static function headings(string $document): array
    {
        $prose = (string) preg_replace('/^```.*?^```$/ms', '', (string) file_get_contents(self::path($document)));

        return self::captures('/^#{1,6} (.+)$/m', $prose);
    }

    /**
     * Return the distinct first capture of every match of the given pattern.
     *
     * @param  string  $pattern
     * @param  string  $subject
     * @return list<string>
     */
    private static function captures(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Return the absolute path of the given repository file.
     *
     * @param  string  $file
     * @return string
     */
    private static function path(string $file): string
    {
        return dirname(__DIR__, 3) . '/' . $file;
    }
}
