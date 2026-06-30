<?php

declare(strict_types = 1);

use Illuminate\Database\Eloquent\Casts\AsStringable;

return [

    /*
    |---------------------------------------------------------------------------
    | API Toolkit Cache Configuration
    |---------------------------------------------------------------------------
    |
    | This section defines the caching options for the API Toolkit. The settings
    | are used to control how the toolkit caches data to improve performance and
    | efficiency. The 'prefix' is used to namespace toolkit cache entries to
    | avoid key collisions with other parts of your application.
    |
    */

    'cache' => [
        'prefix' => 'api-toolkit',
    ],

    /*
    |---------------------------------------------------------------------------
    | Exception Handling Configuration
    |---------------------------------------------------------------------------
    |
    | This section controls how the API exception handler renders exceptions.
    |
    | 'render_strategy' determines when exceptions are rendered as JSON:
    |
    |   'always_json'        - Always render exceptions as JSON, regardless of
    |                          the request's Accept header or debug mode.
    |   'json_when_expected' - Only render as JSON when the request expects a
    |                          JSON response (i.e. Accept: application/json).
    |   'auto'               - Render as JSON unless the request does not expect
    |                          JSON and the application is in debug mode, in
    |                          which case Laravel's default rendering is used.
    |
    | 'include_debug_info' controls whether exception responses include debug
    | metadata such as stack traces, file paths, and exception messages. When
    | set to null, the value of 'app.debug' is used as a fallback. It is
    | strongly recommended to set this to false in production environments.
    |
    */

    'exceptions' => [

        'render_strategy' => env('API_EXCEPTION_RENDER_STRATEGY', 'auto'),

        'include_debug_info' => null,

        // Lower-case substrings used to redact matching request keys (e.g.
        // password, *_token, *secret*) from the request data written to the
        // exception log context, preventing credentials from leaking to logs.
        'sensitive_keys' => ['password', 'token', 'secret', 'authorization'],

    ],

    /*
    |---------------------------------------------------------------------------
    | API Resource Configuration
    |---------------------------------------------------------------------------
    |
    | Here you can specify settings for how your application manages API
    | resources, particularly with regard to polymorphic relations. These
    | settings help control and fine-tune the dynamic resolution of resource
    | types based on model instances. This ensures flexibility and
    | maintainability of your API's.
    |
    | `enable_dynamic_morph_mapping`: When set to true, this option enables
    | automatic resolution of morph mappings for polymorphic relations. This
    | means the system will dynamically determine the resource mappings at
    | runtime based on the configured resource classes, allowing for a flexible,
    | type-safe API design.
    |
    | `resource_map`: This array should contain the mappings of model classes to
    | their corresponding resource classes. This is used to define explicit
    | morph maps for Eloquent models when using polymorphic relationships,
    | enhancing the API's ability to serialize and deserialize these relations
    | correctly.
    |
    | `fixed_fields`: This array should contain all globally fixed fields i.e.
    | the fields that should always be present in resource responses.
    |
    */

    'resources' => [

        'enable_dynamic_morph_mapping' => env('DYNAMIC_MORPH_MAPPING', true),

        'resource_map' => [
            // This should be filled with the application's resource map
            // e.g. User::class => UserResource::class
        ],

        // When enabled, all registered resource schemas are validated during
        // the boot phase. Recommended for non-production environments.
        // e.g. env('VALIDATE_SCHEMAS', !app()->isProduction())
        'validate_schemas' => false,

        'fixed_fields' => ['id', '_type'],

        // When enabled, the repository-driven query narrows the base-table
        // SELECT to only the columns the resolved field set needs plus a
        // per-model safety set, falling back to SELECT * whenever any resolved
        // field's column reads are unknown. Default OFF; retained as a
        // per-environment kill switch.
        'narrow_columns' => env('API_TOOLKIT_NARROW_COLUMNS', false),

    ],

    /*
    |---------------------------------------------------------------------------
    | OpenAPI Exporter Configuration
    |---------------------------------------------------------------------------
    |
    | This section controls the opt-in OpenAPI 3.1 components exporter. The
    | exporter walks the registered resource map, the operator grammar, and the
    | error catalogue to emit a schema-valid components document. It is invoked
    | explicitly via the `api-toolkit:export-openapi` Artisan command and never
    | runs as part of normal request handling.
    |
    | `output`: The default filesystem path the exported document is written to
    | when the command is run without an explicit `--output` option.
    |
    */

    'openapi' => [

        'output' => env('API_OPENAPI_OUTPUT', base_path('openapi.json')),

    ],

    /*
    |---------------------------------------------------------------------------
    | API Query Parser Configuration
    |---------------------------------------------------------------------------
    |
    | This section configures the API Query Parser, which interprets and handles
    | the parameters passed through API requests. This setup allows for the
    | customization of the parser behavior including default values and aliases
    | used throughout your application to access the parser.
    |
    */

    'parser' => [

        'alias' => 'api.query',

        'register_middleware' => env('API_PARSER_REGISTER_MIDDLEWARE', true),

        // Hard ceiling for a client-supplied `limit`. Requests above it are
        // clamped (not rejected) to prevent unbounded page sizes exhausting
        // memory. Set to 0 (or null) to disable the ceiling.
        'max_limit' => env('API_PARSER_MAX_LIMIT', 100),

        'defaults' => [
            'limit' => env('API_PARSER_DEFAULT_LIMIT', 50),
        ],

    ],

    /*
    |---------------------------------------------------------------------------
    | API Repositories Configuration
    |---------------------------------------------------------------------------
    |
    | This configuration governs the behavior of repositories acting as a layer
    | between your application and the database, particularly in how data is
    | prepared before being passed to the model. The `cast_map` array specifies
    | how various Laravel casts should be handled in the repository to ensure
    | data integrity and type safety before model-level casting is applied.
    |
    | The `cache` block configures the opt-in repository cache (enabled per
    | repository via the Cacheable trait). The default mode keys cache entries
    | per executed query, so a filtered or by-id read never returns the full
    | table. Each option may be overridden per repository via a property:
    |
    |   - `ttl`             → `protected int $cacheTtl`
    |   - `store`           → `protected ?string $cacheStoreName`
    |   - `max_rows`        → `protected ?int $cacheMaxRows`
    |   - `max_bytes`       → `protected ?int $cacheMaxBytes`
    |   - `reference_ttl`   → `protected int $cacheReferenceTtl`
    |   - `negative_ttl`    → `protected ?int $cacheNegativeTtl`
    |   - (key prefix)      → `protected ?string $cacheKeyPrefix`
    |   - (reference mode)  → `protected bool $cacheReferenceTable`
    |
    | `max_rows` and `max_bytes` form the size guard: results larger than either
    | limit are still fetched and returned, but not stored. Set either to null
    | to disable that bound. `registry_enabled` controls how non-taggable stores
    | invalidate per-query entries: when true a per-table key registry is
    | kept so every live entry can be forgotten on a write; when false
    | invalidation falls back to TTL expiry only (a documented degraded
    | behaviour). `negative_ttl` is the shorter lifetime applied to negatively
    | cached null/miss reads, bounding how long a stale "not found" is served
    | and how much memory probe-fill can occupy; it defaults to 10 seconds.
    |
    */

    'repositories' => [

        'cache' => [

            'ttl' => is_numeric($cacheTtl = env('API_TOOLKIT_REPOSITORY_CACHE_TTL', 3600)) ? (int) $cacheTtl : 3600,

            'store' => env('API_TOOLKIT_REPOSITORY_CACHE_STORE'),

            'max_rows' => is_numeric($cacheMaxRows = env('API_TOOLKIT_REPOSITORY_CACHE_MAX_ROWS', 1000)) ? (int) $cacheMaxRows : 1000,

            'max_bytes' => is_numeric($cacheMaxBytes = env('API_TOOLKIT_REPOSITORY_CACHE_MAX_BYTES', 262144)) ? (int) $cacheMaxBytes : 262144,

            'reference_ttl' => is_numeric($cacheReferenceTtl = env('API_TOOLKIT_REPOSITORY_CACHE_REFERENCE_TTL', 3600)) ? (int) $cacheReferenceTtl : 3600,

            'negative_ttl' => is_numeric($cacheNegativeTtl = env('API_TOOLKIT_REPOSITORY_CACHE_NEGATIVE_TTL', 10)) ? (int) $cacheNegativeTtl : 10,

            'registry_enabled' => env('API_TOOLKIT_REPOSITORY_CACHE_REGISTRY_ENABLED', true),

        ],

        'cast_map' => [
            'string' => [
                'string',
                'date',
                'datetime',
                'datetime:.*',
                'immutable_date',
                'immutable_datetime',
                'decimal:.*',
                'double',
                'encrypted',
                'float',
                'real',
                'hashed',
                AsStringable::class,
            ],
            'integer' => ['integer', 'int'],
            'boolean' => ['boolean', 'bool'],
            'array'   => ['array', 'collection', 'encrypted:array', 'encrypted:collection'],
            'object'  => ['object', 'encrypted:object'],
        ],

        // Query-access posture for filtering and sorting. 'allowlist' (the 2.0
        // default) exposes only the columns/relations a resource declares
        // filterable/sortable/traversable and rejects everything else;
        // 'blocklist' restores the prior opt-out behaviour where every column
        // except the searchable_exclusions below is queryable.
        'query_posture' => env('API_TOOLKIT_QUERY_POSTURE', 'allowlist'),

        // When true (the default), an undeclared filter/sort/relation key under
        // the allowlist posture is rejected with a validation error naming the
        // key (fail-closed). Set to false to silently drop undeclared keys
        // instead (the prior fail-quiet behaviour).
        'reject_undeclared' => env('API_TOOLKIT_REJECT_UNDECLARED', true),

        // Columns excluded from the blocklist posture's searchable set. Allows
        // both bare columns and table-scoped columns e.g. users.password. The
        // default covers the stock Laravel + Fortify auth column family so the
        // filter layer's sensitive set stays a superset of the export layer's
        // ignored_fields even under the blocklist opt-out.
        'searchable_exclusions' => [
            'password',
            'token',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_confirmed_at',
            'email_verified_at',
        ],

    ],

    /*
    |---------------------------------------------------------------------------
    | Deferred Writes Configuration
    |---------------------------------------------------------------------------
    |
    | This section configures the deferred write pool, which buffers insert
    | operations in memory and flushes them as bulk INSERT statements at the
    | end of the request lifecycle. The pool is opt-in per repository via the
    | Deferrable trait.
    |
    | `chunk_size` controls the maximum number of records per INSERT statement.
    | This should stay below the database parameter binding limit divided by
    | the number of columns in the widest deferred table.
    |
    | `pool_limit` sets the maximum number of total buffered records before
    | an automatic flush is triggered, preventing unbounded memory growth.
    |
    | `on_failure` controls the behavior when a chunk insert fails during flush.
    | Supported values: 'collect' (default), 'throw', 'log'.
    |   - 'collect' (safe default): catch all failures, continue, and retain
    |     the failed records in the buffer for the next flush attempt. No
    |     record is dropped and no exception escapes, so a boundary flush
    |     surfaces failures loudly without disrupting the lifecycle.
    |   - 'throw': throw WritePoolFlushException on the first failure, carrying
    |     the partial result, and preserve the failed and unprocessed records
    |     in the buffer. Best for callers that own an explicit flush site.
    |   - 'log' (opt-in best-effort): catch, log error, continue, and clear the
    |     buffer. Failed records are dropped, so use this only for genuinely
    |     disposable writes such as audit, analytics, or telemetry.
    |
    | `transactional` wraps each table's chunk set in a database transaction so
    | that table's inserts are applied all-or-nothing. Disabled by default to
    | preserve per-chunk performance and the existing partial-persist behavior.
    |
    | `rethrow_at_boundary` re-throws a WritePoolFlushException after escalating
    | it at the lifecycle boundary. Only applies under the 'throw' strategy and
    | is disabled by default so the boundary is never hard-crashed.
    |
    | `invalidate_query_cache` invalidates the per-query repository cache for
    | every table the boundary flush touched, so a deferred insert never leaves
    | a stale cached collection behind. Best-effort: it covers default-config
    | Cacheable repositories; a repository on a custom cache store or key prefix
    | must invalidate manually. Enabled by default.
    |
    | Durability window: buffered writes live only in PHP memory until the
    | boundary flush. A crash, out-of-memory condition, or SIGKILL before the
    | flush loses any unflushed records. For true durability use a real queue.
    |
    */

    'deferred_writes' => [

        'chunk_size' => is_numeric($chunkSize = env('DEFERRED_WRITES_CHUNK_SIZE', 500)) ? (int) $chunkSize : 500,

        'pool_limit' => is_numeric($poolLimit = env('DEFERRED_WRITES_POOL_LIMIT', 10000)) ? (int) $poolLimit : 10000,

        'on_failure' => env('DEFERRED_WRITES_ON_FAILURE', 'collect'),

        'transactional' => (bool) env('DEFERRED_WRITES_TRANSACTIONAL', false),

        'rethrow_at_boundary' => (bool) env('DEFERRED_WRITES_RETHROW_AT_BOUNDARY', false),

        'invalidate_query_cache' => (bool) env('DEFERRED_WRITES_INVALIDATE_QUERY_CACHE', true),

    ],

    /*
    |---------------------------------------------------------------------------
    | Cache Lifecycle Configuration
    |---------------------------------------------------------------------------
    |
    | This section configures automatic cache invalidation for long-running
    | PHP environments such as Laravel Octane and queue workers. When enabled,
    | the toolkit automatically flushes all cached metadata at the appropriate
    | lifecycle boundaries to prevent stale data.
    |
    | Both options are enabled by default. Engagement is gated on runtime
    | detection (LARAVEL_OCTANE server variable / non-sync queue connection),
    | so standard PHP-FPM deployments incur no additional overhead - detection
    | returns false and the flush is skipped. Operators running Octane or queue
    | workers who wish to opt out may set API_TOOLKIT_LIFECYCLE_OCTANE=false
    | or API_TOOLKIT_LIFECYCLE_QUEUE=false in their environment.
    |
    */

    'lifecycle' => [

        'octane' => env('API_TOOLKIT_LIFECYCLE_OCTANE', true),

        'queue' => env('API_TOOLKIT_LIFECYCLE_QUEUE', true),

    ],

    /*
    |---------------------------------------------------------------------------
    | API Notification Configuration
    |---------------------------------------------------------------------------
    |
    | Here you may specify the custom settings for API notifications. This
    | includes options such as enabling or disabling logging for notification
    | events.
    |
    | 'enable_logging' controls whether logging is enabled for notification
    | events.
    |
    | 'excluded_classes' is an array of fully-qualified notification class
    | names that should be excluded from the notification audit log.
    |
    */

    'notifications' => [

        'enable_logging' => env('ENABLE_NOTIFICATION_LOGGING', true),

        'excluded_classes' => [
            // Add notification class-strings to exclude from logging.
        ],

    ],

    /*
    |---------------------------------------------------------------------------
    | API Middleware Configuration
    |---------------------------------------------------------------------------
    |
    | This section controls the middleware registrations performed by the API
    | Toolkit service provider. Each middleware registration can be
    | independently enabled, disabled, or customised. All options default to
    | the current behavior, so no configuration changes are required for
    | existing consumers.
    |
    | `maintenance_mode_swap`: Controls whether the toolkit replaces Laravel's
    | built-in PreventRequestsDuringMaintenance middleware with the toolkit's
    | version. When enabled, the toolkit's middleware is prepended to the global
    | middleware stack, taking precedence over Laravel's default. Disable this
    | if you manage maintenance mode middleware in your own bootstrap/app.php.
    |   - `enabled`: true to swap (default), false to skip.
    |
    | `detect_capabilities`: Controls the registration of the
    | DetectsCapabilities middleware, which resolves the typed request
    | capabilities (trashed visibility and PDF negotiation)
    | once per request. Capabilities resolve lazily on first access even
    | when disabled; the middleware simply precomputes them.
    |   - `enabled`: true to register (default), false to skip entirely.
    |   - `scope`: 'global' to push to the global middleware stack (default),
    |              'api' to append to the 'api' middleware group only.
    |              Ignored when `enabled` is false.
    |
    | `json_pretty_print`: Controls the registration of the JsonPrettyPrint
    | middleware, which allows API consumers to request pretty-printed JSON
    | responses via a query parameter.
    |   - `enabled`: true to register (default), false to skip entirely.
    |   - `scope`: 'global' to push to the global middleware stack (default),
    |              'api' to append to the 'api' middleware group only.
    |              Ignored when `enabled` is false.
    |
    | `throttle`: Controls the throttle middleware alias override. When enabled,
    | the toolkit registers its own ThrottleRequests middleware (which provides
    | API-friendly rate limit responses) as the 'throttle' alias, automatically
    | selecting the Redis variant when the default cache driver is Redis.
    |   - `enabled`: true to override the alias (default), false to skip.
    |   - `class`: A fully-qualified class name to use as the throttle
    |              middleware instead of the toolkit's default. Set to null
    |              (default) for automatic detection. Use this to key guests
    |              by an app-specific identifier instead of their client IP:
    |              point it at a class that uses ThrottleRequestsTrait and
    |              overrides resolveRequestSignature().
    |
    */

    'middleware' => [

        'maintenance_mode_swap' => [
            'enabled' => true,
        ],

        'detect_capabilities' => [
            'enabled' => true,
            'scope'   => 'global',
        ],

        'json_pretty_print' => [
            'enabled' => true,
            'scope'   => 'global',
        ],

        'throttle' => [
            'enabled' => true,
            'class'   => null,
        ],

    ],

    /*
    |---------------------------------------------------------------------------
    | API Maintenance Mode Configuration
    |---------------------------------------------------------------------------
    |
    | Here you may specify the URIs that should be accessible even when the API
    | is in maintenance mode. This helps in keeping essential endpoints
    | operational during downtime.
    |
    | The 'except' array lists the endpoints that will bypass the maintenance
    | mode check. Add endpoints here to ensure they remain reachable.
    |
    */

    'maintenance_mode' => [

        'except' => [],

    ],

];
