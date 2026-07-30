<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

/**
 * Reduces a component-schema map to the schemas an audience actually reaches.
 *
 * A per-audience document must not leak the schema of a resource that is not
 * documented in that audience. The resolver seeds the reachable set from every
 * schema reference found in the audience's paths, unions the always-shared
 * schema names the responses depend on, then walks the transitive `$ref`
 * closure through the schema map until it reaches a fixed point. The returned
 * map keeps the input order and contains only the reachable schemas, so an
 * unreachable resource is dropped while every reference in the surviving
 * document still resolves.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ReachableSchemaResolver
{
    /** The path prefix under which component schemas are referenced */
    private const string SCHEMA_REF_PREFIX = '#/components/schemas/';

    /**
     * Reduce the schema map to those reachable from the seed structure via the
     * transitive reference closure, always retaining the named shared schemas.
     *
     * @param  array<string, array<string, mixed>>  $schemas
     * @param  array<array-key, mixed>  $seed
     * @param  list<string>  $alwaysKeep
     * @return array<string, array<string, mixed>>
     */
    public function reachable(array $schemas, array $seed, array $alwaysKeep): array
    {
        $reachable = $this->collectReferences($seed);

        foreach ($alwaysKeep as $name) {
            $reachable[$name] = true;
        }

        $pending = array_keys($reachable);

        while ($pending !== []) {

            $name   = array_pop($pending);
            $schema = $schemas[$name] ?? null;

            if ($schema === null) {
                continue;
            }

            foreach (array_keys($this->collectReferences($schema)) as $reference) {

                if (isset($reachable[$reference])) {
                    continue;
                }

                $reachable[$reference] = true;
                $pending[]             = $reference;
            }
        }

        return array_intersect_key($schemas, $reachable);
    }

    /**
     * Collect the component schema names referenced anywhere within the node,
     * as a set keyed by schema name.
     *
     * @param  mixed  $node
     * @return array<string, true>
     */
    private function collectReferences(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }

        $references = [];

        foreach ($node as $key => $value) {

            if ($key === '$ref' && is_string($value) && str_starts_with($value, self::SCHEMA_REF_PREFIX)) {
                $references[substr($value, strlen(self::SCHEMA_REF_PREFIX))] = true;

                continue;
            }

            $references += $this->collectReferences($value);
        }

        return $references;
    }
}
