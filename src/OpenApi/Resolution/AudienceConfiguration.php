<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use SineMacula\ApiToolkit\Config\OpenApiSettings;
use SineMacula\ApiToolkit\Enums\AudiencePosture;

/**
 * Reads the OpenAPI audience registry from the toolkit config.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class AudienceConfiguration
{
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
     * List every audience an export documents for: the configured ones, the
     * default audience and the given one, in that order.
     *
     * @param  string  $audience
     * @return list<string>
     */
    public function exportedAudiences(string $audience): array
    {
        return array_values(array_unique([...$this->names(), $this->defaultAudience(), $audience]));
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
     * Resolve the default audience.
     *
     * @return string
     */
    public function defaultAudience(): string
    {
        return OpenApiSettings::defaultAudience();
    }

    /**
     * Resolve the posture for the given audience, defaulting to blocklist.
     *
     * @param  string  $audience
     * @return \SineMacula\ApiToolkit\Enums\AudiencePosture
     */
    public function postureFor(string $audience): AudiencePosture
    {
        $posture = $this->audiences()[$audience]['posture'] ?? null;

        return is_string($posture) ? AudiencePosture::from($posture) : AudiencePosture::BLOCKLIST;
    }

    /**
     * Resolve the OpenAPI info object for the given audience.
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
     * Resolve the OpenAPI servers for the given audience.
     *
     * An audience's own list, even an empty one, replaces the global list.
     *
     * @param  string  $audience
     * @return list<array<string, mixed>>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ServerConfigurationException
     */
    public function serversFor(string $audience): array
    {
        $servers   = $this->audiences()[$audience]['servers'] ?? null;
        $validator = new ServerListValidator;

        if ($servers !== null) {
            return $validator->validate($servers, $audience);
        }

        return $validator->validate(OpenApiSettings::servers());
    }

    /**
     * Read the audience registry from config.
     *
     * @return array<string, array<string, mixed>>
     */
    private function audiences(): array
    {
        return OpenApiSettings::audiences();
    }

    /**
     * Resolve a non-empty info string, per-audience before top-level.
     *
     * @param  string  $audience
     * @param  string  $key
     * @return string|null
     */
    private function infoValue(string $audience, string $key): ?string
    {
        /** @var array<string, string> $perAudience */
        $perAudience = $this->audiences()[$audience]['info'] ?? [];

        return $perAudience[$key] ?? OpenApiSettings::info()[$key] ?? null;
    }
}
