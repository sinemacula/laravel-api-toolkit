<?php

declare(strict_types = 1);

use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentableRouteFilter;

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
        // The handler's DEFAULT_SENSITIVE_KEYS is the single source of truth,
        // reused here so the shipped default and the hard fallback cannot
        // drift.
        'sensitive_keys' => ApiExceptionHandler::DEFAULT_SENSITIVE_KEYS,

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
    | `paths`: The filesystem paths scanned at boot for resources carrying the
    | ForModel attribute. Null (the default) resolves at runtime to the
    | application's own resource directory plus each module's, derived from
    | app_path() so a modular application is covered with no configuration.
    | Give an explicit array to override the roots, or an empty array to
    | disable discovery. Discovered bindings merge beneath the resource_map (an
    | explicit entry always wins), so annotating a resource is the primary way
    | to bind it to its model. Missing paths are skipped.
    |
    | `resource_map`: Explicit model to resource overrides. An entry here wins
    | over a discovered binding, acting as the canonical-resource tiebreak when
    | a model has more than one resource, and as the binding mechanism for
    | resource classes that live outside the scanned paths.
    |
    | `fixed_fields`: This array should contain all globally fixed fields i.e.
    | the fields that should always be present in resource responses.
    |
    */

    'resources' => [

        'enable_dynamic_morph_mapping' => env('DYNAMIC_MORPH_MAPPING', true),

        'paths' => null,

        'resource_map' => [
            // Explicit overrides only; resources inside the scanned paths are
            // bound via the ForModel attribute
            // e.g. User::class => UserResource::class
        ],

        // When enabled, all registered resource schemas are validated during
        // the boot phase. Defaults to enabled outside production; an unset
        // APP_ENV counts as production so a misconfigured host never pays the
        // validation cost by default.
        'validate_schemas' => env('VALIDATE_SCHEMAS', env('APP_ENV', 'production') !== 'production'),

        'fixed_fields' => ['id', '_type'],

        // Columns that may never be declared filterable or sortable. Schema
        // validation refuses a resource that declares one, so a credential or
        // verification column cannot become an oracle a client narrows on one
        // comparison at a time without ever reading the value. The default
        // covers the stock Laravel + Fortify auth column family, keeping the
        // query layer's sensitive set a superset of the export layer's. A
        // published file that omits the key falls back to this same list.
        'sensitive_columns' => [
            'password',
            'token',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_confirmed_at',
            'email_verified_at',
        ],

        // When enabled, the repository-driven query narrows the base-table
        // SELECT to only the columns the resolved field set needs plus a
        // per-model safety set, falling back to SELECT * whenever any resolved
        // field's column reads are unknown. Enabled by default; the env acts as
        // a per-environment kill switch for code that reads attributes outside
        // the declared field set on API-path models.
        'narrow_columns' => env('API_TOOLKIT_NARROW_COLUMNS', true),

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
    | `docs_path`: The directory of committed Markdown section files assembled,
    | in filename (sorted) order, into every audience's info.description so the
    | rendered documentation opens with the manual before listing the endpoints.
    | Defaults to the application's own resource directory - the same target the
    | `api-toolkit-docs` publish tag writes to - so publishing then editing the
    | shipped templates is picked up automatically. The manual is opt-in: until
    | the directory exists it resolves to empty and no description is injected.
    |
    */

    'openapi' => [

        'output' => env('API_OPENAPI_OUTPUT', base_path('openapi.json')),

        'docs_path' => env('API_OPENAPI_DOCS_PATH', resource_path('api-docs')),

        // The audience exported when the command runs without an explicit
        // --audience option (and without --all). Must name one of the audiences
        // declared below; defaults to the shipped 'public' audience.
        'default_audience' => env('API_OPENAPI_DEFAULT_AUDIENCE', 'public'),

        // The default OpenAPI info block, applied to every audience that does
        // not override a given key. Any key set here (e.g. title, version, an
        // optional description) becomes the fallback for all audiences; title
        // and version fall back to shipped hard defaults when omitted here too.
        // Empty by default.
        'info' => [],

        // The audiences the exporter can produce a document for. One codebase
        // yields multiple documents, each tailored to who it is for (e.g.
        // 'public', 'internal', 'partner'). An audience is keyed by name and
        // may declare a 'posture' controlling how routes join it.
        //
        // A 'blocklist' posture (the default when 'posture' is omitted)
        // documents every route except those explicitly excluded with
        // #[NotDocumentedIn] or #[Undocumented] (or the matching route macros).
        // An 'allowlist' posture documents nothing until a route opts in with
        // #[DocumentedIn] or ->documentedIn().
        //
        // An audience may also carry an 'info' block that overrides the
        // top-level 'info' defaults above, per key, for that audience's
        // document, e.g. a distinct title or description for the internal one.
        //
        // The shipped zero-config 'public' audience is a blocklist with no
        // exclusions, so it documents everything. Routes marked #[Undocumented]
        // appear in no audience at all.
        'audiences' => [
            'public' => [],
        ],

        // Per-operation security is derived from standard Laravel route
        // middleware, orthogonal to authorization: the exporter reads a route's
        // `auth`/`auth:*` middleware into a guard list (bare `auth` uses the
        // default guard; `auth:user,guest` is an OR of both), resolves each
        // guard's driver from `auth.guards.<guard>.driver`, and maps the driver
        // to an OpenAPI security scheme. A route with no `auth` middleware is
        // documented as public (`security: []`). Authorization middleware
        // (`can:`) is not authentication and yields no scheme.
        //
        // The built-in map covers the stock drivers: jwt -> bearer (JWT),
        // basic -> basic, sanctum/token -> bearer, session -> a cookie apiKey
        // named after `session.cookie`. Guards sharing a scheme shape collapse
        // to one scheme. Add or override a driver below, keyed by driver name,
        // each carrying a stable scheme `name` and its OpenAPI `definition`; an
        // entry wins over the built-in default. A driver with no mapping is
        // skipped rather than invented.
        'security' => [

            // Override or extend the built-in driver-to-scheme map. Key each
            // entry by the auth driver name; its value is an array with a
            // scheme 'name' (the securityScheme component key) and a
            // 'definition' (the OpenAPI security scheme object). Listed drivers
            // merge over and win against the built-in defaults (jwt, basic,
            // sanctum, token, session).
            'drivers' => [],

        ],

        // Routes whose handler is defined under one of these namespace prefixes
        // are excluded from every exported document. The shipped default blocks
        // the framework and common first-party tooling only (Laravel, Horizon,
        // Telescope, Sanctum, Ignition, and similar) so their routes never
        // pollute the documentation. The list replaces the default rather than
        // merging: add a prefix to hide another package, or remove one to
        // document it. Your own internal packages are absent from the default
        // and stay documented even when installed under vendor. A prefix
        // matches on a namespace boundary, so 'Illuminate\' excludes
        // Illuminate\Routing\Foo but never IlluminateApp\Foo.
        'exclude' => [
            'namespaces' => DocumentableRouteFilter::DEFAULT_NAMESPACES,
        ],

        // Additional PSR-4 namespace prefixes to scan for documentable
        // ApiException subclasses, on top of the application and its modules
        // (which are always scanned). The application scan deliberately skips
        // vendor, so an installed ecosystem package's exceptions are not
        // catalogued by default; list its root namespace here (e.g.
        // 'SineMacula\Authentication\') to include its error codes. Every
        // registered PSR-4 root at or below a listed prefix is scanned, and
        // only ApiException subclasses declaring a CODE are documented.
        'exception_namespaces' => [],

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
    | between your application and the database.
    |
    | The per-query repository cache (the Cacheable trait) now lives in the
    | sinemacula/laravel-repositories package and is configured there, under
    | `repositories.cache` (env `REPOSITORY_CACHE_*`).
    |
    */

    'repositories' => [

        // Whether the `random` order keyword may apply a random ordering.
        // Disabled by default because a random sort materialises and sorts the
        // whole table to return a single page. While disabled the keyword has
        // no special meaning and is gated like any other sort column.
        'allow_random_order' => env('API_TOOLKIT_ALLOW_RANDOM_ORDER', false),

        // Time-to-live, in seconds, for the cached relation-detection lookup
        // (whether a given key names an Eloquent relation on a model). Relation
        // structure is schema-static, so the default of one day still caches
        // effectively; the expiry is a defence-in-depth bound on the relation
        // cache key space so a key derived from client input cannot accumulate
        // permanently under a long-running worker.
        'relation_cache_ttl' => env('API_TOOLKIT_RELATION_CACHE_TTL', 86400),

    ],

    /*
    |---------------------------------------------------------------------------
    | Query Cost Configuration
    |---------------------------------------------------------------------------
    |
    | These caps bound the structural cost of a single request. Every part of an
    | amplified query is individually cheap and individually declared - it is
    | the multiplication that is expensive. A request that exceeds a cap is
    | rejected before any SQL is issued, with a 422 naming the parameter, the
    | position within it, the cap, the limit, and the value supplied, so the
    | client can correct the query itself.
    |
    | The shipped values are calibrated against the package's own fixture
    | schemas. They are not measured against production traffic, which is
    | exactly why they are configuration: raise or lower each one against what
    | your own API is asked for. Set a cap to 0 (or null) to disable it.
    |
    | `max_bytes` and `max_parse_depth` are enforced while the query string is
    | validated, before the filter document is interpreted: the first bounds its
    | byte length, the second the number of nested object levels it declares.
    |
    | The remaining caps are enforced as the criteria are applied. `max_depth`
    | bounds the levels the filter dispatcher descends (a logical group or a
    | relation subquery is one level) and `max_nodes` the total keys it visits;
    | both abort part-way through rather than after the whole tree is built.
    | `max_in_items` bounds a single operator value list, such as `$in`, and is
    | measured against the items an operator reads rather than the shape of the
    | value, so a list spelled as a delimited string is bounded the same way.
    | `max_order_keys` bounds the sort columns, and `max_aggregates` the
    | relation counts, sums, and averages combined, since each adds its own
    | correlated subquery. `max_offset` bounds the requested page number, beyond
    | which a paginated read scans and discards more rows than it returns; it
    | rejects rather than clamps, unlike the parser's `max_limit` ceiling.
    |
    */

    'query_cost' => [

        'max_bytes' => env('API_TOOLKIT_QUERY_MAX_BYTES', 8192),

        'max_parse_depth' => env('API_TOOLKIT_QUERY_MAX_PARSE_DEPTH', 16),

        'max_depth' => env('API_TOOLKIT_QUERY_MAX_DEPTH', 3),

        'max_nodes' => env('API_TOOLKIT_QUERY_MAX_NODES', 100),

        'max_in_items' => env('API_TOOLKIT_QUERY_MAX_IN_ITEMS', 500),

        'max_order_keys' => env('API_TOOLKIT_QUERY_MAX_ORDER_KEYS', 3),

        'max_aggregates' => env('API_TOOLKIT_QUERY_MAX_AGGREGATES', 5),

        'max_offset' => env('API_TOOLKIT_QUERY_MAX_OFFSET', 10000),

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
