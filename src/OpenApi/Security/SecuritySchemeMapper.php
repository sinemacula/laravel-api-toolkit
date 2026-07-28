<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Security;

use Illuminate\Support\Facades\Config;

/**
 * Maps a Laravel auth guard driver to an OpenAPI security scheme.
 *
 * The toolkit ships no authentication of its own; it reads the standard auth
 * config so a custom auth package needs zero cooperation. Each supported driver
 * resolves to a stable scheme name (shared by every driver of the same shape,
 * so a bearer-token guard and a JWT guard collapse to one scheme) and its
 * OpenAPI definition. The built-in map covers the stock drivers; a config entry
 * under `api-toolkit.openapi.security.drivers` adds or overrides a driver,
 * keyed by driver name and carrying a scheme name and definition. A driver with
 * no mapping resolves to null so the exporter skips it rather than inventing a
 * scheme.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SecuritySchemeMapper
{
    /** The scheme name assumed for the cookie session driver */
    private const string SESSION_COOKIE_FALLBACK = 'session';

    /**
     * Resolve the scheme name and definition for the given driver, or null when
     * the driver has no built-in mapping and no config override.
     *
     * @param  string  $driver
     * @return array{name: string, definition: array<string, mixed>}|null
     */
    public function schemeFor(string $driver): ?array
    {
        return $this->map()[$driver] ?? null;
    }

    /**
     * Resolve the definition for a scheme name, or null when no mapped driver
     * emits that name. The first driver declaring the name wins, keeping the
     * canonical definition deterministic when drivers share a scheme shape.
     *
     * @param  string  $name
     * @return array<string, mixed>|null
     */
    public function definitionFor(string $name): ?array
    {
        foreach ($this->map() as $scheme) {
            if ($scheme['name'] === $name) {
                return $scheme['definition'];
            }
        }

        return null;
    }

    /**
     * Build the driver-keyed scheme map, merging the config overrides over the
     * built-in defaults.
     *
     * @return array<string, array{name: string, definition: array<string, mixed>}>
     */
    private function map(): array
    {
        return array_merge($this->defaults(), $this->overrides());
    }

    /**
     * List the built-in driver-to-scheme mappings.
     *
     * @return array<string, array{name: string, definition: array<string, mixed>}>
     */
    private function defaults(): array
    {
        return [
            'jwt'     => ['name' => 'bearerAuth', 'definition' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT']],
            'basic'   => ['name' => 'basicAuth', 'definition' => ['type' => 'http', 'scheme' => 'basic']],
            'sanctum' => ['name' => 'bearerAuth', 'definition' => ['type' => 'http', 'scheme' => 'bearer']],
            'token'   => ['name' => 'bearerAuth', 'definition' => ['type' => 'http', 'scheme' => 'bearer']],
            'session' => ['name' => 'cookieAuth', 'definition' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => $this->sessionCookie()]],
        ];
    }

    /**
     * Read the driver-to-scheme overrides from config, keeping only entries
     * that carry both a scheme name and a definition.
     *
     * @return array<string, array{name: string, definition: array<string, mixed>}>
     */
    private function overrides(): array
    {
        $configured = Config::get('api-toolkit.openapi.security.drivers');

        if (!is_array($configured)) {
            return [];
        }

        $overrides = [];

        foreach ($configured as $driver => $scheme) {

            if (!is_string($driver) || !$this->isValidScheme($scheme)) {
                continue;
            }

            $overrides[$driver] = ['name' => $scheme['name'], 'definition' => $scheme['definition']];
        }

        return $overrides;
    }

    /**
     * Determine whether a config override entry carries a string scheme name
     * and an array definition.
     *
     * @param  mixed  $scheme
     * @return bool
     *
     * @phpstan-assert-if-true array{name: string, definition: array<string, mixed>} $scheme
     */
    private function isValidScheme(mixed $scheme): bool
    {
        return is_array($scheme) && is_string($scheme['name'] ?? null) && is_array($scheme['definition'] ?? null);
    }

    /**
     * Resolve the session cookie name backing the cookie security scheme.
     *
     * @return string
     */
    private function sessionCookie(): string
    {
        $cookie = Config::get('session.cookie');

        return is_string($cookie) && $cookie !== '' ? $cookie : self::SESSION_COOKIE_FALLBACK;
    }
}
