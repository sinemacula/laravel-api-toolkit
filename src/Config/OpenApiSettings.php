<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Config;

use SineMacula\ApiToolkit\Enums\AudiencePosture;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;

/**
 * The typed OpenAPI exporter settings.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class OpenApiSettings
{
    /** @var string What a security driver mapping accepts */
    private const string SCHEME = 'an array with a non-empty string name and an array definition';

    /** @var list<string> The keys an audience may declare */
    private const array AUDIENCE_KEYS = ['posture', 'info', 'servers'];

    /** @var list<string> The keys an info block may declare */
    private const array INFO_KEYS = ['title', 'version', 'description'];

    /**
     * Return the path the OpenAPI document is written to by default.
     *
     * @return string
     */
    public static function output(): string
    {
        return ToolkitConfig::readString('openapi.output');
    }

    /**
     * Return the directory of the OpenAPI Markdown documentation.
     *
     * @return string
     */
    public static function docsPath(): string
    {
        return ToolkitConfig::readString('openapi.docs_path');
    }

    /**
     * Return the audience exported by default.
     *
     * @return string
     */
    public static function defaultAudience(): string
    {
        return ToolkitConfig::readString('openapi.default_audience');
    }

    /**
     * Return the default OpenAPI info block.
     *
     * @return array<string, string>
     */
    public static function info(): array
    {
        return self::checkedInfo(ToolkitConfig::key('openapi.info'), ToolkitConfig::readMap('openapi.info'));
    }

    /**
     * Return the global OpenAPI servers, validated where they are exported.
     *
     * @return array<array-key, mixed>
     */
    public static function servers(): array
    {
        return ToolkitConfig::readMap('openapi.servers');
    }

    /**
     * Determine whether every operation is exported with an operationId.
     *
     * @return bool
     */
    public static function shouldExportOperationIds(): bool
    {
        return ToolkitConfig::readBoolean('openapi.operation_ids');
    }

    /**
     * Return the configured order of the exported tags.
     *
     * @return list<string>
     */
    public static function tags(): array
    {
        return ToolkitConfig::readList('openapi.tags');
    }

    /**
     * Return the audiences keyed by name, each checked for shape; servers are
     * validated where they are exported.
     *
     * @return array<array-key, array<string, mixed>>
     */
    public static function audiences(): array
    {
        $audiences = [];

        foreach (ToolkitConfig::readMap('openapi.audiences') as $name => $audience) {
            $audiences[$name] = self::checkedAudience(ToolkitConfig::key('openapi.audiences.' . $name), $audience);
        }

        return $audiences;
    }

    /**
     * Return the security scheme mappings keyed by auth driver.
     *
     * @return array<string, array{name: string, definition: array<string, mixed>}>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    public static function securityDrivers(): array
    {
        $schemes = [];

        foreach (ToolkitConfig::readMap('openapi.security.drivers') as $driver => $scheme) {

            if (!is_string($driver) || !self::isScheme($scheme)) {
                throw InvalidConfigurationException::mustBe(ToolkitConfig::key('openapi.security.drivers.' . $driver), self::SCHEME, $scheme);
            }

            $schemes[$driver] = ['name' => $scheme['name'], 'definition' => $scheme['definition']];
        }

        return $schemes;
    }

    /**
     * Return the namespace prefixes left out of every OpenAPI document.
     *
     * @return list<string>
     */
    public static function excludedNamespaces(): array
    {
        return ToolkitConfig::readList('openapi.exclude.namespaces');
    }

    /**
     * Return the extra namespaces scanned for documented ApiExceptions.
     *
     * @return list<string>
     */
    public static function exceptionNamespaces(): array
    {
        return ToolkitConfig::readList('openapi.exception_namespaces');
    }

    /**
     * Determine whether a driver mapping carries a name and a definition.
     *
     * @param  mixed  $scheme
     * @return bool
     *
     * @phpstan-assert-if-true array{name: non-empty-string, definition: array<string, mixed>} $scheme
     */
    private static function isScheme(mixed $scheme): bool
    {
        return is_array($scheme)
            && is_string($scheme['name'] ?? null)
            && $scheme['name'] !== ''
            && is_array($scheme['definition'] ?? null);
    }

    /**
     * Check an audience definition for shape.
     *
     * @param  string  $key
     * @param  mixed  $audience
     * @return array<string, mixed>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    private static function checkedAudience(string $key, mixed $audience): array
    {
        if (!is_array($audience)) {
            throw InvalidConfigurationException::mustBe($key, ToolkitConfig::AN_ARRAY, $audience);
        }

        foreach (array_keys($audience) as $name) {
            if (!in_array($name, self::AUDIENCE_KEYS, true)) {
                throw InvalidConfigurationException::unknown($key . '.' . $name);
            }
        }

        $posture = $audience['posture'] ?? null;

        if ($posture !== null && (!is_string($posture) || AudiencePosture::tryFrom($posture) === null)) {
            throw InvalidConfigurationException::mustBe($key . '.posture', 'one of allowlist, blocklist', $posture);
        }

        if (array_key_exists('info', $audience)) {
            self::checkedInfo($key . '.info', $audience['info']);
        }

        /** @var array<string, mixed> */
        return $audience;
    }

    /**
     * Check an info block for shape.
     *
     * @param  string  $key
     * @param  mixed  $info
     * @return array<string, string>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    private static function checkedInfo(string $key, mixed $info): array
    {
        if (!is_array($info)) {
            throw InvalidConfigurationException::mustBe($key, ToolkitConfig::AN_ARRAY, $info);
        }

        foreach ($info as $name => $value) {

            if (!in_array($name, self::INFO_KEYS, true)) {
                throw InvalidConfigurationException::unknown($key . '.' . $name);
            }

            if (!is_string($value) || $value === '') {
                throw InvalidConfigurationException::mustBe($key . '.' . $name, ToolkitConfig::A_NON_EMPTY_STRING, $value);
            }
        }

        return $info;
    }
}
