<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Cache;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Events\CacheFlushed;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Centralized orchestrator for flushing all toolkit caches.
 *
 * Registered as a singleton in the container. Delegates to all known cache
 * site flush methods and dispatches the CacheFlushed event upon completion.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CacheManager
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
        private readonly Container $container,

        /** The registry of toolkit metadata keys to forget on flush. */
        private readonly MetadataKeyRegistry $registry,

    ) {}

    /**
     * Flush all toolkit caches and dispatch the flushed event.
     *
     * @return void
     */
    public function flush(): void
    {
        foreach ($this->registry->keys() as $key) {
            Cache::memo()->forget($key); // @phpstan-ignore method.notFound
        }

        $this->registry->clear();

        SchemaCompiler::clearCache();
        ValueResolver::clearCache();
        EagerLoadPlanner::clearCache();

        $this->container->make(SchemaIntrospectionProvider::class)->flush();

        $this->resetQueryParser();

        event(new CacheFlushed);
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
