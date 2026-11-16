<?php

declare(strict_types = 1);

namespace Tests\Integration\Config;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\TestCase;

/**
 * Tests that every environment variable the package config reads is named after
 * the config key it sets.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
final class EnvironmentVariableNamesTest extends TestCase
{
    /** @var string The variable Laravel itself owns, read as a default. */
    private const string APPLICATION_VARIABLE = 'APP_ENV';

    /**
     * Test that each variable sets exactly one key and is named after it.
     *
     * @return void
     */
    public function testEveryVariableIsNamedAfterItsKey(): void
    {
        $names = $this->variableNames();

        self::assertNotEmpty($names);

        foreach (array_unique($names) as $name) {
            $off = Arr::dot($this->loadConfigWith($name, '0'));
            $on  = Arr::dot($this->loadConfigWith($name, '1'));

            $changed = $this->changedKeys($off, $on);

            self::assertCount(1, $changed, sprintf('%s sets %d keys, not one: %s', $name, count($changed), implode(', ', $changed)));
            self::assertSame('API_TOOLKIT_' . strtoupper(str_replace('.', '_', $changed[0])), $name, sprintf('%s sets the key %s', $name, $changed[0]));
        }
    }

    /**
     * Test that the config reads the environment through env calls alone.
     *
     * @return void
     */
    public function testEveryVariableIsReadThroughEnv(): void
    {
        $tokens = $this->tokens();

        $reads = array_filter(
            array_map(fn (int $index): ?string => $this->otherEnvironmentRead($tokens, $index), array_keys($tokens)),
            static fn (?string $read): bool => $read !== null,
        );

        self::assertSame([], array_values($reads));
    }

    /**
     * Test that no variable is read by more than one setting.
     *
     * @return void
     */
    public function testEveryVariableIsReadOnce(): void
    {
        $names = $this->variableNames();

        self::assertNotEmpty($names);
        self::assertSame(array_values(array_unique($names)), $names);
    }

    /**
     * Collect the first argument of every env call in the config file, failing
     * on one that is not a single literal string.
     *
     * @return list<string>
     */
    private function variableNames(): array
    {
        $tokens = $this->tokens();
        $names  = [];

        foreach (array_keys($tokens) as $index) {
            if (!$this->isEnvCall($tokens, $index)) {
                continue;
            }

            $names[] = $this->literalArgument($tokens, $index);
        }

        return array_values(array_filter($names, static fn (string $name): bool => $name !== self::APPLICATION_VARIABLE));
    }

    /**
     * Determine whether the token at the given index opens an env call.
     *
     * @param  list<array{int, string, int}|string>  $tokens
     * @param  int  $index
     * @return bool
     */
    private function isEnvCall(array $tokens, int $index): bool
    {
        $token = $tokens[$index];

        return is_array($token)
            && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
            && strtolower(ltrim($token[1], '\\')) === 'env'
            && ($tokens[$index + 1] ?? null)      === '(';
    }

    /**
     * Describe the read of the environment bypassing env at the given index.
     *
     * @param  list<array{int, string, int}|string>  $tokens
     * @param  int  $index
     * @return string|null
     */
    private function otherEnvironmentRead(array $tokens, int $index): ?string
    {
        $token = $tokens[$index];

        if (!is_array($token)) {
            return null;
        }

        $next     = $tokens[$index + 1] ?? null;
        $segments = explode('\\', $token[1]);
        $name     = strtolower(end($segments));

        $read = match ($token[0]) {
            T_VARIABLE                                         => in_array($token[1], ['$_ENV', '$_SERVER', '$GLOBALS'], true),
            T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED => ($name === 'getenv' && $next === '(')
                || ($name === 'env' && is_array($next) && $next[0] === T_DOUBLE_COLON),
            default => false,
        };

        return $read ? sprintf('%s on line %d', $token[1], $token[2]) : null;
    }

    /**
     * Return the keys whose value differs between the two flattened loads,
     * including a key present in only one of them.
     *
     * @param  array<array-key, mixed>  $off
     * @param  array<array-key, mixed>  $on
     * @return list<string>
     */
    private function changedKeys(array $off, array $on): array
    {
        $keys = array_map(strval(...), array_keys($off + $on));

        return array_values(array_filter(
            $keys,
            static fn (string $key): bool => !array_key_exists($key, $off) || !array_key_exists($key, $on) || $off[$key] !== $on[$key],
        ));
    }

    /**
     * Tokenise the config file, dropping whitespace and comments.
     *
     * @return list<array{int, string, int}|string>
     */
    private function tokens(): array
    {
        return array_values(array_filter(
            token_get_all((string) file_get_contents($this->configPath())),
            static fn (mixed $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
    }

    /**
     * Return the env call's first argument, failing unless it is one literal
     * string.
     *
     * @param  list<array{int, string, int}|string>  $tokens
     * @param  int  $index
     * @return string
     */
    private function literalArgument(array $tokens, int $index): string
    {
        $argument = $tokens[$index + 2] ?? null;

        if (!is_array($argument) || $argument[0] !== T_CONSTANT_ENCAPSED_STRING || !in_array($tokens[$index + 3] ?? null, [',', ')'], true)) {
            $line = $tokens[$index];

            self::fail(sprintf('The env call on line %d does not name its variable as one literal string', is_array($line) ? $line[2] : 0));
        }

        return substr($argument[1], 1, -1);
    }

    /**
     * Load the config file with the given variable set to the given value,
     * restoring the variable's previous state afterwards.
     *
     * @param  string  $name
     * @param  string  $value
     * @return array<array-key, mixed>
     */
    private function loadConfigWith(string $name, string $value): array
    {
        $hadServer = array_key_exists($name, $_SERVER);
        $hadEnv    = array_key_exists($name, $_ENV);
        $server    = $_SERVER[$name] ?? null;
        $env       = $_ENV[$name]    ?? null;

        $_SERVER[$name] = $value;
        $_ENV[$name]    = $value;

        try {
            $path = $this->configPath();

            $config = (static fn (): mixed => require $path)();

            self::assertIsArray($config);

            return $config;
        } finally {
            if ($hadServer) {
                $_SERVER[$name] = $server;
            } else {
                unset($_SERVER[$name]);
            }

            if ($hadEnv) {
                $_ENV[$name] = $env;
            } else {
                unset($_ENV[$name]);
            }
        }
    }

    /**
     * Return the path to the package config file.
     *
     * @return string
     */
    private function configPath(): string
    {
        return dirname(__DIR__, 3) . '/config/api-toolkit.php';
    }
}
