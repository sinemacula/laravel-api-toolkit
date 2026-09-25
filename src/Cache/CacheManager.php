<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Cache;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Events\CacheFlushed;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Search\IndexProof;
use SineMacula\ApiToolkit\Search\SearchPlan;

/**
 * Centralized orchestrator for flushing all toolkit caches.
 *
 * Registered as a singleton in the container. Delegates to all known cache site
 * flush methods and dispatches the CacheFlushed event upon completion.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CacheManager
{
    /**
     * Create a new cache manager instance.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @param  \SineMacula\ApiToolkit\Cache\MetadataKeyRegistry  $registry
     * @return void
     */
    public function __construct(

        /** The service container for resolving cache site instances. */
        private Container $container,

        /** The registry of toolkit metadata keys to forget on flush. */
        private MetadataKeyRegistry $registry,
    ) {}

    /**
     * Retire every toolkit metadata entry in every process sharing the store,
     * then flush this process's caches.
     *
     * A flush only forgets the keys this process registered, so metadata an
     * earlier process wrote survives it. Replacing the generation leaves those
     * entries unreachable instead, which is what a schema change needs.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\MetadataInvalidationException
     */
    public function invalidateMetadata(): void
    {
        $this->generation()->advance();

        $this->flush();
    }

    /**
     * Flush all toolkit caches and dispatch the flushed event.
     *
     * The generation is re-read after a flush rather than replaced, so a
     * long-lived worker picks up an invalidation made elsewhere without
     * invalidating anything itself.
     *
     * @return void
     */
    public function flush(): void
    {
        foreach ($this->registry->keys() as $key) {
            Cache::memo()->forget($key); // @phpstan-ignore method.notFound
        }

        $this->registry->clear();
        $this->generation()->forget();

        SchemaCompiler::clearCache();
        ValueResolver::clearCache();
        EagerLoadPlanner::clearCache();
        FieldResolver::clearCache();
        FieldColumnMapper::clearCache();
        SearchPlan::clearCache();
        IndexProof::clearCache();

        $this->container->make(SchemaIntrospectionProvider::class)->flush();

        $this->resetQueryParser();

        event(new CacheFlushed);
    }

    /**
     * Resolve the generation every toolkit metadata key is namespaced by.
     *
     * @return \SineMacula\ApiToolkit\Cache\MetadataGeneration
     */
    private function generation(): MetadataGeneration
    {
        return $this->container->make(MetadataGeneration::class);
    }

    /**
     * Reset the query parser if it is bound in the container.
     *
     * @return void
     */
    private function resetQueryParser(): void
    {
        $alias = Config::get('api-toolkit.parser.alias', 'api.query');

        if (!$this->container->bound($alias)) {
            return;
        }

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser = $this->container->make($alias);
        $parser->reset();
    }
}
