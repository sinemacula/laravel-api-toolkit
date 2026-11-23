<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Support;

use SineMacula\ApiToolkit\Config\HttpSettings;
use SineMacula\ApiToolkit\Config\LifecycleSettings;
use SineMacula\ApiToolkit\Config\OpenApiSettings;
use SineMacula\ApiToolkit\Config\QuerySettings;
use SineMacula\ApiToolkit\Config\ResourceSettings;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Enums\MiddlewareScope;
use SineMacula\ApiToolkit\Enums\RenderStrategy;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequests;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;

/**
 * Every toolkit setting with a value it accepts, the value that reads as, a
 * value it refuses and the message the refusal carries.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SettingCases
{
    /** @var string What a boolean setting accepts */
    private const string BOOLEAN = 'a boolean, or one of 1, 0, true, false, on, off, yes, no';

    /** @var string What a cap setting accepts */
    private const string CAP = 'an integer of at least 0, or null';

    /** @var string What a list setting accepts */
    private const string LIST = 'a list of non-empty strings';

    /**
     * Provide the cases of the settings the given class reads.
     *
     * @param  class-string  $settings
     * @return iterable<string, array{string, \Closure(): mixed, mixed, mixed, mixed, string}>
     */
    public static function of(string $settings): iterable
    {
        foreach (self::all() as $name => [$class, $key, $read, $accepted, $expected, $refused, $message]) {
            if ($class !== $settings) {
                continue;
            }

            yield $name => [$key, $read, $accepted, $expected, $refused, $message];
        }
    }

    /**
     * Provide the case of every setting, with the class that reads it.
     *
     * @return iterable<string, array{class-string, string, \Closure(): mixed, mixed, mixed, mixed, string}>
     */
    public static function all(): iterable
    {
        yield 'cache prefix' => [
            LifecycleSettings::class,
            'cache.prefix',
            LifecycleSettings::cachePrefix(...),
            'app',
            'app',
            '',
            self::refused('cache.prefix', 'a non-empty string', '\'\''),
        ];

        yield 'metadata lifetime' => [
            LifecycleSettings::class,
            'cache.metadata_ttl',
            LifecycleSettings::metadataTtl(...),
            '1',
            1,
            0,
            self::refused('cache.metadata_ttl', 'an integer of at least 1', '0'),
        ];

        yield 'cache retry' => [
            LifecycleSettings::class,
            'cache.retry_after',
            LifecycleSettings::cacheRetryAfter(...),
            '45',
            45,
            -1,
            self::refused('cache.retry_after', 'an integer of at least 0', '-1'),
        ];

        yield 'render strategy' => [
            HttpSettings::class,
            'exceptions.render_strategy',
            HttpSettings::renderStrategy(...),
            'json_when_expected',
            RenderStrategy::JSON_WHEN_EXPECTED,
            'json-when-expected',
            self::refused('exceptions.render_strategy', 'one of always_json, json_when_expected, auto', '\'json-when-expected\''),
        ];

        yield 'render strategy not a string' => [
            HttpSettings::class,
            'exceptions.render_strategy',
            HttpSettings::renderStrategy(...),
            'auto',
            RenderStrategy::AUTO,
            1,
            self::refused('exceptions.render_strategy', 'one of always_json, json_when_expected, auto', '1'),
        ];

        yield 'debug info' => [
            HttpSettings::class,
            'exceptions.include_debug_info',
            HttpSettings::shouldIncludeDebugInfo(...),
            'off',
            false,
            'maybe',
            self::refused('exceptions.include_debug_info', self::BOOLEAN . ', or null', '\'maybe\''),
        ];

        yield 'exception map' => [
            HttpSettings::class,
            'exceptions.map',
            HttpSettings::exceptionMap(...),
            ['A' => 'B', 'C' => 'D'],
            ['A' => 'B', 'C' => 'D'],
            'none',
            self::refused('exceptions.map', 'an array', '\'none\''),
        ];

        yield 'morph mapping' => [
            ResourceSettings::class,
            'resources.enable_dynamic_morph_mapping',
            ResourceSettings::shouldMapMorphTypes(...),
            'yes',
            true,
            'enabled',
            self::refused('resources.enable_dynamic_morph_mapping', self::BOOLEAN, '\'enabled\''),
        ];

        yield 'paths' => [
            ResourceSettings::class,
            'resources.paths',
            ResourceSettings::paths(...),
            ['app/Http/Resources'],
            ['app/Http/Resources'],
            false,
            self::refused('resources.paths', self::LIST . ', or null', 'false'),
        ];

        yield 'resource map' => [
            ResourceSettings::class,
            'resources.resource_map',
            ResourceSettings::resourceMap(...),
            [User::class => UserResource::class, Post::class => PostResource::class],
            [User::class => UserResource::class, Post::class => PostResource::class],
            [User::class => 5],
            self::refused('resources.resource_map', 'a map of model classes to resource classes', '5'),
        ];

        yield 'validate schemas' => [
            ResourceSettings::class,
            'resources.validate_schemas',
            ResourceSettings::shouldValidateSchemas(...),
            '0',
            false,
            '',
            self::refused('resources.validate_schemas', self::BOOLEAN, '\'\''),
        ];

        yield 'fixed fields' => [
            ResourceSettings::class,
            'resources.fixed_fields',
            ResourceSettings::fixedFields(...),
            ['id', '_type'],
            ['id', '_type'],
            ['id' => 'id'],
            self::refused('resources.fixed_fields', self::LIST, 'array'),
        ];

        yield 'sensitive columns' => [
            ResourceSettings::class,
            'resources.sensitive_columns',
            ResourceSettings::sensitiveColumns(...),
            ['secret'],
            ['secret'],
            [''],
            self::refused('resources.sensitive_columns.0', 'a non-empty string', '\'\''),
        ];

        yield 'narrow columns' => [
            ResourceSettings::class,
            'resources.narrow_columns',
            ResourceSettings::shouldNarrowColumns(...),
            'off',
            false,
            'narrow',
            self::refused('resources.narrow_columns', self::BOOLEAN, '\'narrow\''),
        ];

        yield 'relation limit' => [
            ResourceSettings::class,
            'resources.relation_limit',
            ResourceSettings::relationLimit(...),
            null,
            0,
            -1,
            self::refused('resources.relation_limit', self::CAP, '-1'),
        ];

        yield 'output' => [
            OpenApiSettings::class,
            'openapi.output',
            OpenApiSettings::output(...),
            '/tmp/openapi.json',
            '/tmp/openapi.json',
            null,
            self::refused('openapi.output', 'a non-empty string', 'null'),
        ];

        yield 'docs path' => [
            OpenApiSettings::class,
            'openapi.docs_path',
            OpenApiSettings::docsPath(...),
            'docs',
            'docs',
            42,
            self::refused('openapi.docs_path', 'a non-empty string', '42'),
        ];

        yield 'default audience' => [
            OpenApiSettings::class,
            'openapi.default_audience',
            OpenApiSettings::defaultAudience(...),
            'partner',
            'partner',
            '',
            self::refused('openapi.default_audience', 'a non-empty string', '\'\''),
        ];

        yield 'info' => [
            OpenApiSettings::class,
            'openapi.info',
            OpenApiSettings::info(...),
            ['title' => 'API', 'version' => '2.0'],
            ['title' => 'API', 'version' => '2.0'],
            ['title' => 3],
            self::refused('openapi.info.title', 'a non-empty string', '3'),
        ];

        yield 'servers' => [
            OpenApiSettings::class,
            'openapi.servers',
            OpenApiSettings::servers(...),
            [['url' => 'https://api.example.com']],
            [['url' => 'https://api.example.com']],
            'https://api.example.com',
            self::refused('openapi.servers', 'an array', '\'https://api.example.com\''),
        ];

        yield 'operation ids' => [
            OpenApiSettings::class,
            'openapi.operation_ids',
            OpenApiSettings::shouldExportOperationIds(...),
            'on',
            true,
            'sometimes',
            self::refused('openapi.operation_ids', self::BOOLEAN, '\'sometimes\''),
        ];

        yield 'tags' => [
            OpenApiSettings::class,
            'openapi.tags',
            OpenApiSettings::tags(...),
            ['Users'],
            ['Users'],
            'Users',
            self::refused('openapi.tags', self::LIST, '\'Users\''),
        ];

        yield 'audiences' => [
            OpenApiSettings::class,
            'openapi.audiences',
            OpenApiSettings::audiences(...),
            ['public' => [], 'partner' => ['posture' => 'allowlist', 'servers' => []]],
            ['public' => [], 'partner' => ['posture' => 'allowlist', 'servers' => []]],
            ['partner' => ['posture' => 'allow_list']],
            self::refused('openapi.audiences.partner.posture', 'one of allowlist, blocklist', '\'allow_list\''),
        ];

        yield 'security drivers' => [
            OpenApiSettings::class,
            'openapi.security.drivers',
            OpenApiSettings::securityDrivers(...),
            ['a' => ['name' => 'aAuth', 'definition' => []], 'b' => ['name' => 'bAuth', 'definition' => []]],
            ['a' => ['name' => 'aAuth', 'definition' => []], 'b' => ['name' => 'bAuth', 'definition' => []]],
            ['passport' => ['name' => '', 'definition' => []]],
            self::refused('openapi.security.drivers.passport', 'an array with a non-empty string name and an array definition', 'array'),
        ];

        yield 'excluded namespaces' => [
            OpenApiSettings::class,
            'openapi.exclude.namespaces',
            OpenApiSettings::excludedNamespaces(...),
            ['App\Internal\\'],
            ['App\Internal\\'],
            [5],
            self::refused('openapi.exclude.namespaces.0', 'a non-empty string', '5'),
        ];

        yield 'exception namespaces' => [
            OpenApiSettings::class,
            'openapi.exception_namespaces',
            OpenApiSettings::exceptionNamespaces(...),
            [],
            [],
            'App',
            self::refused('openapi.exception_namespaces', self::LIST, '\'App\''),
        ];

        yield 'parser alias' => [
            HttpSettings::class,
            'parser.alias',
            HttpSettings::parserAlias(...),
            'query',
            'query',
            '',
            self::refused('parser.alias', 'a non-empty string', '\'\''),
        ];

        yield 'parser middleware' => [
            HttpSettings::class,
            'parser.register_middleware',
            HttpSettings::shouldRegisterParserMiddleware(...),
            '1',
            true,
            'always',
            self::refused('parser.register_middleware', self::BOOLEAN, '\'always\''),
        ];

        yield 'page size ceiling' => [
            QuerySettings::class,
            'parser.max_limit',
            QuerySettings::maxLimit(...),
            '0',
            0,
            '-1',
            self::refused('parser.max_limit', self::CAP, '\'-1\''),
        ];

        yield 'default page size' => [
            QuerySettings::class,
            'parser.defaults.limit',
            QuerySettings::defaultLimit(...),
            ' 25 ',
            25,
            '0',
            self::refused('parser.defaults.limit', 'an integer of at least 1', '\'0\''),
        ];

        yield 'random order' => [
            QuerySettings::class,
            'repositories.allow_random_order',
            QuerySettings::canOrderRandomly(...),
            'no',
            false,
            'random',
            self::refused('repositories.allow_random_order', self::BOOLEAN, '\'random\''),
        ];

        yield 'query cost cap' => [
            QuerySettings::class,
            'query_cost.max_nodes',
            static fn (): int => QuerySettings::costCap('max_nodes'),
            '7',
            7,
            '8k',
            self::refused('query_cost.max_nodes', self::CAP, '\'8k\''),
        ];

        yield 'shortest word' => [
            QuerySettings::class,
            'search.min_word_length',
            QuerySettings::minWordLength(...),
            '4',
            4,
            2,
            self::refused('search.min_word_length', 'an integer of at least 3', '2'),
        ];

        yield 'longest term' => [
            QuerySettings::class,
            'search.max_length',
            QuerySettings::maxSearchLength(...),
            64,
            64,
            0,
            self::refused('search.max_length', 'an integer of at least 1', '0'),
        ];

        yield 'most words' => [
            QuerySettings::class,
            'search.max_words',
            QuerySettings::maxSearchWords(...),
            '4',
            4,
            '1e3',
            self::refused('search.max_words', 'an integer of at least 1', '\'1e3\''),
        ];

        yield 'index proof' => [
            QuerySettings::class,
            'search.index_proof_ttl',
            QuerySettings::indexProofTtl(...),
            0,
            0,
            '10.0',
            self::refused('search.index_proof_ttl', 'an integer of at least 0', '\'10.0\''),
        ];

        yield 'unverified connections' => [
            QuerySettings::class,
            'search.unverified_connections',
            QuerySettings::unverifiedConnections(...),
            ['sqlite'],
            ['sqlite'],
            'sqlite',
            self::refused('search.unverified_connections', self::LIST, '\'sqlite\''),
        ];

        yield 'chunk size' => [
            LifecycleSettings::class,
            'deferred_writes.chunk_size',
            LifecycleSettings::chunkSize(...),
            '700',
            700,
            0,
            self::refused('deferred_writes.chunk_size', 'an integer of at least 1', '0'),
        ];

        yield 'pool limit' => [
            LifecycleSettings::class,
            'deferred_writes.pool_limit',
            LifecycleSettings::poolLimit(...),
            900,
            900,
            'lots',
            self::refused('deferred_writes.pool_limit', 'an integer of at least 1', '\'lots\''),
        ];

        yield 'flush strategy' => [
            LifecycleSettings::class,
            'deferred_writes.on_failure',
            LifecycleSettings::flushStrategy(...),
            'throw',
            FlushStrategy::THROW,
            'Collect',
            self::refused('deferred_writes.on_failure', 'one of log, throw, collect', '\'Collect\''),
        ];

        yield 'transactional' => [
            LifecycleSettings::class,
            'deferred_writes.transactional',
            LifecycleSettings::isFlushTransactional(...),
            'true',
            true,
            'sometimes',
            self::refused('deferred_writes.transactional', self::BOOLEAN, '\'sometimes\''),
        ];

        yield 'rethrow' => [
            LifecycleSettings::class,
            'deferred_writes.rethrow_at_boundary',
            LifecycleSettings::shouldRethrowAtBoundary(...),
            0,
            false,
            2,
            self::refused('deferred_writes.rethrow_at_boundary', self::BOOLEAN, '2'),
        ];

        yield 'invalidate cache' => [
            LifecycleSettings::class,
            'deferred_writes.invalidate_query_cache',
            LifecycleSettings::shouldInvalidateQueryCache(...),
            1,
            true,
            [],
            self::refused('deferred_writes.invalidate_query_cache', self::BOOLEAN, 'array'),
        ];

        yield 'migrations' => [
            LifecycleSettings::class,
            'lifecycle.migrations',
            LifecycleSettings::shouldInvalidateOnMigrations(...),
            'off',
            false,
            null,
            self::refused('lifecycle.migrations', self::BOOLEAN, 'null'),
        ];

        yield 'correlation header' => [
            HttpSettings::class,
            'services.correlation_header',
            HttpSettings::correlationHeader(...),
            '',
            null,
            5,
            self::refused('services.correlation_header', 'a string, or null', '5'),
        ];

        yield 'notification logging' => [
            LifecycleSettings::class,
            'notifications.enable_logging',
            LifecycleSettings::shouldLogNotifications(...),
            'no',
            false,
            'disabled',
            self::refused('notifications.enable_logging', self::BOOLEAN, '\'disabled\''),
        ];

        yield 'excluded notifications' => [
            LifecycleSettings::class,
            'notifications.excluded_classes',
            LifecycleSettings::excludedNotifications(...),
            ['App\Welcome'],
            ['App\Welcome'],
            [null],
            self::refused('notifications.excluded_classes.0', 'a non-empty string', 'null'),
        ];

        yield 'log channel' => [
            LifecycleSettings::class,
            'notifications.log_channel',
            LifecycleSettings::notificationLogChannel(...),
            'audit',
            'audit',
            [],
            self::refused('notifications.log_channel', 'a string, or null', 'array'),
        ];

        yield 'maintenance swap' => [
            HttpSettings::class,
            'middleware.maintenance_mode_swap.enabled',
            HttpSettings::shouldSwapMaintenanceMode(...),
            'on',
            true,
            'swap',
            self::refused('middleware.maintenance_mode_swap.enabled', self::BOOLEAN, '\'swap\''),
        ];

        yield 'pretty print' => [
            HttpSettings::class,
            'middleware.json_pretty_print.enabled',
            HttpSettings::shouldPrettyPrintJson(...),
            'off',
            false,
            'pretty',
            self::refused('middleware.json_pretty_print.enabled', self::BOOLEAN, '\'pretty\''),
        ];

        yield 'pretty print scope' => [
            HttpSettings::class,
            'middleware.json_pretty_print.scope',
            HttpSettings::jsonPrettyPrintScope(...),
            'api',
            MiddlewareScope::API,
            'API',
            self::refused('middleware.json_pretty_print.scope', 'one of global, api', '\'API\''),
        ];

        yield 'throttle' => [
            HttpSettings::class,
            'middleware.throttle.enabled',
            HttpSettings::shouldRegisterThrottle(...),
            'yes',
            true,
            'throttle',
            self::refused('middleware.throttle.enabled', self::BOOLEAN, '\'throttle\''),
        ];

        yield 'throttle class' => [
            HttpSettings::class,
            'middleware.throttle.class',
            HttpSettings::throttleClass(...),
            ThrottleRequests::class,
            ThrottleRequests::class,
            false,
            self::refused('middleware.throttle.class', 'a string, or null', 'false'),
        ];

        yield 'maintenance exceptions' => [
            HttpSettings::class,
            'maintenance_mode.except',
            HttpSettings::maintenanceExcept(...),
            ['/health'],
            ['/health'],
            '/health',
            self::refused('maintenance_mode.except', self::LIST, '\'/health\''),
        ];
    }

    /**
     * Build the message refusing a value of the given setting.
     *
     * @param  string  $key
     * @param  string  $expectation
     * @param  string  $given
     * @return string
     */
    private static function refused(string $key, string $expectation, string $given): string
    {
        return sprintf('The api-toolkit.%s setting must be %s; [%s] given.', $key, $expectation, $given);
    }
}
