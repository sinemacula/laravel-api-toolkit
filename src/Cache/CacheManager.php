<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Cache;

use Illuminate\Contracts\Container\Container;
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
 * Resets in-process toolkit caches and invalidates the shared metadata.
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
     * @return void
     */
    public function __construct(

        /** The service container for resolving cache site instances */
        private Container $container,
    ) {}

    /**
     * Retire the shared metadata by advancing its generation, then flush this
     * process's caches.
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
     * Reset this process's toolkit state and dispatch the flushed event.
     *
     * The shared store is left alone; forgetting the generation lets a
     * long-lived worker pick up an invalidation made elsewhere.
     *
     * @return void
     */
    public function flush(): void
    {
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
        $alias = Config::get('api-toolkit.parser.alias');
        $alias = is_string($alias) ? $alias : 'api.query';

        if (!$this->container->bound($alias)) {
            return;
        }

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser = $this->container->make($alias);
        $parser->reset();
    }
}
