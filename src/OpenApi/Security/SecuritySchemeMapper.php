<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Security;

use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Config\OpenApiSettings;

/**
 * Maps a Laravel auth guard driver to an OpenAPI security scheme.
 *
 * Bearer drivers share a plain bearer scheme, so the document never claims a
 * token format it cannot know.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SecuritySchemeMapper
{
    /** The scheme name assumed for the cookie session driver */
    private const string SESSION_COOKIE_FALLBACK = 'session';

    /**
     * Resolve the scheme name and definition for the given driver.
     *
     * @param  string  $driver
     * @return array{name: string, definition: array<string, mixed>}|null
     */
    public function schemeFor(string $driver): ?array
    {
        return $this->map()[$driver] ?? null;
    }

    /**
     * Resolve the definition for a scheme name, a configured driver winning
     * over the built-in ones.
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
     * Build the driver-keyed scheme map, the configured drivers ahead of the
     * built-in ones they leave unmapped.
     *
     * @return array<string, array{name: string, definition: array<string, mixed>}>
     */
    private function map(): array
    {
        return OpenApiSettings::securityDrivers() + $this->defaults();
    }

    /**
     * List the built-in driver-to-scheme mappings.
     *
     * @return array<string, array{name: string, definition: array<string, mixed>}>
     */
    private function defaults(): array
    {
        return [
            'jwt'     => ['name' => 'bearerAuth', 'definition' => ['type' => 'http', 'scheme' => 'bearer']],
            'basic'   => ['name' => 'basicAuth', 'definition' => ['type' => 'http', 'scheme' => 'basic']],
            'sanctum' => ['name' => 'bearerAuth', 'definition' => ['type' => 'http', 'scheme' => 'bearer']],
            'token'   => ['name' => 'bearerAuth', 'definition' => ['type' => 'http', 'scheme' => 'bearer']],
            'session' => ['name' => 'cookieAuth', 'definition' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => $this->sessionCookie()]],
        ];
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
