<?php

declare(strict_types = 1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Tests that every source class with logic has a test named after it.
 *
 * The diff mutation gate runs only the tests named after the classes a change
 * touches, so a class without one aborts the gate or escapes it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
final class NameMappedTestsTest extends TestCase
{
    /** @var list<string> The test suite directories PHPUnit runs. */
    private const array SUITES = ['Unit', 'Integration', 'Feature'];

    /**
     * Test that each class, trait or enum declaring a method body has a test
     * named after it.
     *
     * @return void
     */
    public function testEveryClassWithLogicHasANameMappedTest(): void
    {
        $root   = dirname(__DIR__, 2);
        $suites = array_merge(...array_map(static fn (string $suite): array => self::files($root . '/tests/' . $suite), self::SUITES));
        $tests  = array_map(static fn (\SplFileInfo $file): string => $file->getBasename('.php'), $suites);

        $unmapped = [];

        foreach (self::files($root . '/src') as $file) {

            $name = $file->getBasename('.php');

            if (in_array($name . 'Test', $tests, true) || !self::declaresLogic((string) file_get_contents($file->getPathname()), $name)) {
                continue;
            }

            $unmapped[] = substr($file->getPathname(), strlen($root) + 1);
        }

        sort($unmapped);

        self::assertSame([], $unmapped);
    }

    /**
     * List the PHP files beneath the directory.
     *
     * @param  string  $directory
     * @return list<\SplFileInfo>
     */
    private static function files(string $directory): array
    {
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $files[] = $file;
        }

        return $files;
    }

    /**
     * Determine whether the code declares the named class, trait or enum and at
     * least one method with a body.
     *
     * @param  string  $code
     * @param  string  $name
     * @return bool
     */
    private static function declaresLogic(string $code, string $name): bool
    {
        $tokens   = array_values(array_filter(\PhpToken::tokenize($code), static fn (\PhpToken $token): bool => !$token->isIgnorable()));
        $declared = false;
        $logic    = false;

        foreach ($tokens as $index => $token) {

            $next = $tokens[$index + 1] ?? null;

            $declared = $declared || ($token->is([T_CLASS, T_TRAIT, T_ENUM]) && $next?->text === $name);
            $logic    = $logic    || ($token->is(T_FUNCTION) && $next?->text !== '(' && self::opensBody(array_slice($tokens, $index)));
        }

        return $declared && $logic;
    }

    /**
     * Determine whether the function the tokens start with has a body rather
     * than ending at its signature.
     *
     * @param  list<\PhpToken>  $tokens
     * @return bool
     */
    private static function opensBody(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token->text === '{' || $token->text === ';') {
                return $token->text === '{';
            }
        }

        return false;
    }
}
