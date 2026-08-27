<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Support\Facades\Config;

/**
 * Reads the OpenAPI audience registry from the toolkit config.
 *
 * Audiences are declared as config, each keyed by name and optionally carrying
 * a posture controlling how routes join it. This reader is the single place the
 * exporter resolves the configured audience names, the default audience, a
 * named audience's posture, and its OpenAPI info block, so the command and the
 * assembler agree on the same registry regardless of config-cache state. An
 * audience with no explicit posture, or one absent from the registry, resolves
 * to the blocklist posture.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class AudienceConfiguration
{
    /** The audience assumed when none is configured */
    private const string DEFAULT_AUDIENCE = 'public';

    /** The document title assumed when none is configured */
    private const string DEFAULT_INFO_TITLE = 'API Components';

    /** The document version assumed when none is configured */
    private const string DEFAULT_INFO_VERSION = '1.0.0';

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

        return is_string($posture) && $posture !== '' ? $posture : AudienceResolver::POSTURE_BLOCKLIST;
    }

    /**
     * Resolve the OpenAPI info object for the given audience.
     *
     * Each key resolves independently, preferring a per-audience info value
     * over the top-level info default, which in turn wins over the shipped hard
     * default. Only non-empty values are emitted; title and version are always
     * present.
     *
     * @param  string  $audience
     * @return array<string, mixed>
     */
    public function infoFor(string $audience): array
    {
        $info = [
            'title'   => $this->infoValue($audience, 'title')   ?? self::DEFAULT_INFO_TITLE,
            'version' => $this->infoValue($audience, 'version') ?? self::DEFAULT_INFO_VERSION,
        ];

        $description = $this->infoValue($audience, 'description');

        if ($description !== null) {
            $info['description'] = $description;
        }

        return $info;
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

    /**
     * Resolve a single non-empty info string for the audience, falling from the
     * per-audience block to the top-level block and finally to null.
     *
     * @param  string  $audience
     * @param  string  $key
     * @return string|null
     */
    private function infoValue(string $audience, string $key): ?string
    {
        $perAudience = $this->audienceInfo($audience)[$key] ?? null;

        if (is_string($perAudience) && $perAudience !== '') {
            return $perAudience;
        }

        $topLevel = $this->topLevelInfo()[$key] ?? null;

        return is_string($topLevel) && $topLevel !== '' ? $topLevel : null;
    }

    /**
     * Read the per-audience info block from config.
     *
     * @param  string  $audience
     * @return array<string, mixed>
     */
    private function audienceInfo(string $audience): array
    {
        $definition = $this->audiences()[$audience] ?? [];
        $info       = is_array($definition) ? ($definition['info'] ?? null) : null;

        return is_array($info) ? $info : [];
    }

    /**
     * Read the top-level info default block from config.
     *
     * @return array<string, mixed>
     */
    private function topLevelInfo(): array
    {
        $info = Config::get('api-toolkit.openapi.info');

        return is_array($info) ? $info : [];
    }
}
