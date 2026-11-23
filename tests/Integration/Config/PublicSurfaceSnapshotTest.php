<?php

declare(strict_types = 1);

namespace Tests\Integration\Config;

use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use SineMacula\ApiToolkit\Config\ToolkitConfig;
use SineMacula\ApiToolkit\Events\CacheFlushed;
use SineMacula\ApiToolkit\Events\WritePoolFlushFailed;
use Tests\TestCase;

/**
 * Freezes the package surface consumers wire into their deployments, so any
 * rename is a reviewed change to this snapshot.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
final class PublicSurfaceSnapshotTest extends TestCase
{
    /** @var list<string> The environment variables the config file reads. */
    private const array ENVIRONMENT_VARIABLES = [
        'API_TOOLKIT_CACHE_RETRY_AFTER',
        'API_TOOLKIT_DEFERRED_WRITES_CHUNK_SIZE',
        'API_TOOLKIT_DEFERRED_WRITES_INVALIDATE_QUERY_CACHE',
        'API_TOOLKIT_DEFERRED_WRITES_ON_FAILURE',
        'API_TOOLKIT_DEFERRED_WRITES_POOL_LIMIT',
        'API_TOOLKIT_DEFERRED_WRITES_RETHROW_AT_BOUNDARY',
        'API_TOOLKIT_DEFERRED_WRITES_TRANSACTIONAL',
        'API_TOOLKIT_EXCEPTIONS_RENDER_STRATEGY',
        'API_TOOLKIT_LIFECYCLE_MIGRATIONS',
        'API_TOOLKIT_NOTIFICATIONS_ENABLE_LOGGING',
        'API_TOOLKIT_NOTIFICATIONS_LOG_CHANNEL',
        'API_TOOLKIT_OPENAPI_DEFAULT_AUDIENCE',
        'API_TOOLKIT_OPENAPI_DOCS_PATH',
        'API_TOOLKIT_OPENAPI_OUTPUT',
        'API_TOOLKIT_PARSER_DEFAULTS_LIMIT',
        'API_TOOLKIT_PARSER_MAX_LIMIT',
        'API_TOOLKIT_PARSER_REGISTER_MIDDLEWARE',
        'API_TOOLKIT_QUERY_COST_MAX_AGGREGATES',
        'API_TOOLKIT_QUERY_COST_MAX_BYTES',
        'API_TOOLKIT_QUERY_COST_MAX_DEPTH',
        'API_TOOLKIT_QUERY_COST_MAX_IN_ITEMS',
        'API_TOOLKIT_QUERY_COST_MAX_NODES',
        'API_TOOLKIT_QUERY_COST_MAX_OFFSET',
        'API_TOOLKIT_QUERY_COST_MAX_ORDER_KEYS',
        'API_TOOLKIT_QUERY_COST_MAX_PARSE_DEPTH',
        'API_TOOLKIT_REPOSITORIES_ALLOW_RANDOM_ORDER',
        'API_TOOLKIT_RESOURCES_ENABLE_DYNAMIC_MORPH_MAPPING',
        'API_TOOLKIT_RESOURCES_NARROW_COLUMNS',
        'API_TOOLKIT_RESOURCES_RELATION_LIMIT',
        'API_TOOLKIT_RESOURCES_VALIDATE_SCHEMAS',
        'API_TOOLKIT_SEARCH_INDEX_PROOF_TTL',
        'API_TOOLKIT_SEARCH_MAX_LENGTH',
        'API_TOOLKIT_SEARCH_MAX_WORDS',
        'API_TOOLKIT_SEARCH_MIN_WORD_LENGTH',
        'API_TOOLKIT_SERVICES_CORRELATION_HEADER',
    ];

    /** @var list<string> Every setting the config file ships, by full key. */
    private const array CONFIG_KEYS = [
        'api-toolkit.cache.prefix',
        'api-toolkit.cache.retry_after',
        'api-toolkit.deferred_writes.chunk_size',
        'api-toolkit.deferred_writes.invalidate_query_cache',
        'api-toolkit.deferred_writes.on_failure',
        'api-toolkit.deferred_writes.pool_limit',
        'api-toolkit.deferred_writes.rethrow_at_boundary',
        'api-toolkit.deferred_writes.transactional',
        'api-toolkit.exceptions.include_debug_info',
        'api-toolkit.exceptions.map',
        'api-toolkit.exceptions.render_strategy',
        'api-toolkit.lifecycle.migrations',
        'api-toolkit.maintenance_mode.except',
        'api-toolkit.middleware.json_pretty_print.enabled',
        'api-toolkit.middleware.json_pretty_print.scope',
        'api-toolkit.middleware.maintenance_mode_swap.enabled',
        'api-toolkit.middleware.throttle.class',
        'api-toolkit.middleware.throttle.enabled',
        'api-toolkit.notifications.enable_logging',
        'api-toolkit.notifications.excluded_classes',
        'api-toolkit.notifications.log_channel',
        'api-toolkit.openapi.audiences',
        'api-toolkit.openapi.default_audience',
        'api-toolkit.openapi.docs_path',
        'api-toolkit.openapi.exception_namespaces',
        'api-toolkit.openapi.exclude.namespaces',
        'api-toolkit.openapi.info',
        'api-toolkit.openapi.operation_ids',
        'api-toolkit.openapi.output',
        'api-toolkit.openapi.security.drivers',
        'api-toolkit.openapi.servers',
        'api-toolkit.openapi.tags',
        'api-toolkit.parser.alias',
        'api-toolkit.parser.defaults.limit',
        'api-toolkit.parser.max_limit',
        'api-toolkit.parser.register_middleware',
        'api-toolkit.query_cost.max_aggregates',
        'api-toolkit.query_cost.max_bytes',
        'api-toolkit.query_cost.max_depth',
        'api-toolkit.query_cost.max_in_items',
        'api-toolkit.query_cost.max_nodes',
        'api-toolkit.query_cost.max_offset',
        'api-toolkit.query_cost.max_order_keys',
        'api-toolkit.query_cost.max_parse_depth',
        'api-toolkit.repositories.allow_random_order',
        'api-toolkit.resources.enable_dynamic_morph_mapping',
        'api-toolkit.resources.fixed_fields',
        'api-toolkit.resources.narrow_columns',
        'api-toolkit.resources.paths',
        'api-toolkit.resources.relation_limit',
        'api-toolkit.resources.resource_map',
        'api-toolkit.resources.sensitive_columns',
        'api-toolkit.resources.validate_schemas',
        'api-toolkit.search.index_proof_ttl',
        'api-toolkit.search.max_length',
        'api-toolkit.search.max_words',
        'api-toolkit.search.min_word_length',
        'api-toolkit.search.unverified_connections',
        'api-toolkit.services.correlation_header',
    ];

    /** @var list<string> The package's Artisan commands with their arguments and options. */
    private const array COMMANDS = [
        'api-toolkit:config:validate',
        'api-toolkit:docs:generate',
        'api-toolkit:export-openapi [--output [OUTPUT]] [--audience [AUDIENCE]] [--all] [--allow-empty]',
        'api-toolkit:invalidate-metadata',
        'api-toolkit:make:resource [--model [MODEL]] [--type [TYPE]] [--force] [--] <name>',
        'api-toolkit:resources:cache',
        'api-toolkit:resources:clear',
        'api-toolkit:validate-schemas [--allow-empty] [--allow-unverified]',
    ];

    /** @var list<class-string> The events the package dispatches. */
    private const array EVENTS = [
        CacheFlushed::class,
        WritePoolFlushFailed::class,
    ];

    /**
     * Test that the environment variable names are unchanged.
     *
     * @return void
     */
    public function testEnvironmentVariablesAreUnchanged(): void
    {
        preg_match_all('/\benv\(\'(API_TOOLKIT_\w+)\'/', (string) file_get_contents($this->packagePath('config/api-toolkit.php')), $matches);

        $names = $matches[1];

        sort($names);

        self::assertSame(self::ENVIRONMENT_VARIABLES, $names);
    }

    /**
     * Test that the shipped config keys are unchanged.
     *
     * @return void
     */
    public function testConfigKeysAreUnchanged(): void
    {
        $keys = $this->settingKeys(ToolkitConfig::defaults(), '');

        sort($keys);

        self::assertSame(self::CONFIG_KEYS, $keys);
    }

    /**
     * Test that the Artisan command signatures are unchanged.
     *
     * @return void
     */
    public function testCommandSignaturesAreUnchanged(): void
    {
        assert($this->app !== null);

        /** @var array<string, \Symfony\Component\Console\Command\Command> $commands */
        $commands = $this->app->make(Kernel::class)->all();

        $signatures = [];

        foreach ($commands as $name => $command) {

            if (!str_starts_with($command::class, 'SineMacula\ApiToolkit\\')) {
                continue;
            }

            $signatures[] = trim($name . ' ' . $command->getNativeDefinition()->getSynopsis());
        }

        sort($signatures);

        self::assertSame(self::COMMANDS, $signatures);
    }

    /**
     * Test that the dispatched event classes are unchanged.
     *
     * @return void
     */
    public function testEventClassesAreUnchanged(): void
    {
        $events = array_map(
            static fn (string $file): string => 'SineMacula\ApiToolkit\Events\\' . basename($file, '.php'),
            glob($this->packagePath('src/Events/*.php')) ?: [],
        );

        sort($events);

        self::assertSame(self::EVENTS, $events);
    }

    /**
     * List the full key of every setting beneath the given section.
     *
     * @param  array<array-key, mixed>  $section
     * @param  string  $path
     * @return list<string>
     */
    private function settingKeys(array $section, string $path): array
    {
        $keys = [];

        foreach ($section as $name => $default) {

            $child = ltrim($path . '.' . $name, '.');

            $keys = ToolkitConfig::isSection($child, $default)
                ? [...$keys, ...$this->settingKeys($default, $child)]
                : [...$keys, ToolkitConfig::key($child)];
        }

        return $keys;
    }

    /**
     * Resolve a path relative to the package root.
     *
     * @param  string  $path
     * @return string
     */
    private function packagePath(string $path): string
    {
        return dirname(__DIR__, 3) . '/' . $path;
    }
}
