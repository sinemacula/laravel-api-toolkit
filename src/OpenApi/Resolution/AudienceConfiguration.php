<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;

/**
 * Reads the OpenAPI audience registry from the toolkit config.
 *
 * Audiences are declared as config, each keyed by name and optionally carrying
 * a posture controlling how routes join it. This reader is the single place the
 * exporter resolves the configured audience names, the default audience, and a
 * named audience's posture, so the command and the assembler agree on the same
 * registry regardless of config-cache state. An audience with no explicit
 * posture, or one absent from the registry, resolves to the blocklist posture.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class AudienceConfiguration
{
    /** The audience assumed when none is configured */
    private const string DEFAULT_AUDIENCE = 'public';

    /**
     * Return the configured audience names in declaration order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map('strval', array_keys($this->audiences()));
    }

    /**
     * Determine whether the given audience name is configured.
     *
     * @param  string  $audience
     * @return bool
     */
    public function has(string $audience): bool
    {
        return array_key_exists($audience, $this->audiences());
    }

    /**
     * Resolve the default audience, falling back to the shipped public audience
     * when none is configured.
     *
     * @return string
     */
    public function defaultAudience(): string
    {
        $configured = Config::get('api-toolkit.openapi.default_audience');

        return is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_AUDIENCE;
    }

    /**
     * Resolve the posture for the given audience, defaulting to the blocklist
     * posture when the audience is unconfigured or declares no posture.
     *
     * @param  string  $audience
     * @return string
     */
    public function postureFor(string $audience): string
    {
        $definition = $this->audiences()[$audience] ?? [];
        $posture    = is_array($definition) ? ($definition['posture'] ?? null) : null;

        return is_string($posture) && $posture !== '' ? $posture : QuerySurface::POSTURE_BLOCKLIST;
    }

    /**
     * Read the raw audience registry from config.
     *
     * @return array<string, mixed>
     */
    private function audiences(): array
    {
        $audiences = Config::get('api-toolkit.openapi.audiences');

        return is_array($audiences) ? $audiences : [];
    }
}
