<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\OpenApi\Docs\Enums\DocKind;
use SineMacula\ApiToolkit\OpenApi\Exceptions\TagConfigurationException;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes;

/**
 * Builds the top-level tags list of an audience's document.
 *
 * The configured order leads and the remaining tags follow in natural,
 * case-insensitive order. Descriptions are read from `tags/<name>.md`.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class TagList
{
    /** The message for a tag order that is not a list of distinct names */
    private const string MALFORMED_MESSAGE = 'Unable to export the OpenAPI tags: api-toolkit.openapi.tags must be a list of distinct, non-empty tag names.';

    /** The message for a tag order naming a tag no audience uses */
    private const string UNUSED_MESSAGE = 'Unable to export the OpenAPI tags: api-toolkit.openapi.tags names [%s], which no audience uses.';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\KeyedDocFiles  $files
     */
    public function __construct(

        /** The resolver of the keyed description files. */
        private KeyedDocFiles $files = new KeyedDocFiles,
    ) {}

    /**
     * Build the audience's tag list, or null when no tag order is configured
     * and no tag file exists, or the audience uses no tag.
     *
     * @param  string  $audience
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes  $routes
     * @return list<array{name: string, description?: string}>|null
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\TagConfigurationException
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\OrphanedDocumentationException
     */
    public function for(string $audience, DocumentedRoutes $routes): ?array
    {
        $order = $this->configuredOrder();

        if ($order === [] && $this->files->files(DocKind::TAG) === []) {
            return null;
        }

        $keys = [];

        foreach ($routes->audiences() as $name) {
            $keys[$name] = $routes->tags($name);
        }

        $this->assertUsed($order, array_merge(...array_values($keys)));

        $descriptions = $this->files->resolve(DocKind::TAG, $keys, $audience);
        $tags         = $this->sort($routes->tags($audience), $order);

        if ($tags === []) {
            return null;
        }

        return array_map(
            static fn (string $tag): array => ($descriptions[$tag] ?? '') === '' ? ['name' => $tag] : ['name' => $tag, 'description' => $descriptions[$tag]],
            $tags,
        );
    }

    /**
     * Read the configured tag order, failing when it is malformed.
     *
     * @return list<string>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\TagConfigurationException
     */
    private function configuredOrder(): array
    {
        $order = Config::get('api-toolkit.openapi.tags') ?? [];

        if (!$this->isTagList($order)) {
            throw new TagConfigurationException(self::MALFORMED_MESSAGE);
        }

        return $order;
    }

    /**
     * Determine whether the value is a list of distinct, non-empty strings.
     *
     * @param  mixed  $order
     * @return bool
     *
     * @phpstan-assert-if-true list<string> $order
     */
    private function isTagList(mixed $order): bool
    {
        if (!is_array($order) || !array_is_list($order)) {
            return false;
        }

        return array_filter($order, static fn (mixed $tag): bool => is_string($tag) && $tag !== '') === $order
            && array_unique($order)                                                                 === $order;
    }

    /**
     * Assert that every ordered tag is used by some audience.
     *
     * @param  list<string>  $order
     * @param  list<string>  $used
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\TagConfigurationException
     */
    private function assertUsed(array $order, array $used): void
    {
        foreach ($order as $tag) {
            if (!in_array($tag, $used, true)) {
                throw new TagConfigurationException(sprintf(self::UNUSED_MESSAGE, $tag));
            }
        }
    }

    /**
     * Order the tags: the configured ones first, then the rest by natural,
     * case-insensitive comparison.
     *
     * @param  list<string>  $tags
     * @param  list<string>  $order
     * @return list<string>
     */
    private function sort(array $tags, array $order): array
    {
        $rest = array_diff($tags, $order);

        usort($rest, static fn (string $a, string $b): int => strnatcasecmp($a, $b) ?: strcmp($a, $b));

        return [...array_intersect($order, $tags), ...$rest];
    }
}
