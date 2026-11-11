<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Naming;

use SineMacula\ApiToolkit\OpenApi\Exceptions\SchemaNameCollisionException;

/**
 * Guards a component schema name map against two sources claiming one name.
 *
 * Resource, envelope and enum schemas share one map, so a collision would
 * silently overwrite a schema.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SchemaNameCollisionGuard
{
    /** The message describing a component schema name collision */
    private const string COLLISION_MESSAGE = 'The OpenAPI component schema name "%s" is derived by both [%s] and [%s]. Disambiguate one with the #[SchemaName] attribute.';

    /**
     * Record a claim on the name, throwing when another source holds it.
     *
     * @param  array<string, string>  $claimed
     * @param  string  $name
     * @param  string  $source
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\SchemaNameCollisionException
     */
    public function claim(array &$claimed, string $name, string $source): void
    {
        $existing = $claimed[$name] ?? null;

        if ($existing !== null && $existing !== $source) {
            throw new SchemaNameCollisionException(sprintf(self::COLLISION_MESSAGE, $name, $existing, $source));
        }

        $claimed[$name] = $source;
    }
}
