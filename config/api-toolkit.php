<?php

declare(strict_types = 1);

use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentableRouteFilter;

return [

    /*
    | Cache
    |
    | `prefix` namespaces the toolkit's cache keys.
    |
    | `retry_after` is the seconds a failing cache store is bypassed for, during
    | which metadata is computed uncached and reported once. 0 retries, and
    | reports, on every call.
    */

    'cache' => [
        'prefix'      => 'api-toolkit',
        'retry_after' => env('API_TOOLKIT_CACHE_RETRY_AFTER', 30),
    ],

    /*
    | Exceptions
    |
    | `render_strategy` decides when exceptions render as JSON:
    |   'always_json'        - always.
    |   'json_when_expected' - only when the request expects JSON.
    |   'auto'               - always, except a non-JSON request in debug mode,
    |                          which falls back to Laravel's rendering.
    |
    | `include_debug_info` adds the message, file, line and trace of the
    | underlying exception to the response meta. Null follows app.debug; keep it
    | false in production.
    */

    'exceptions' => [

        'render_strategy' => env('API_EXCEPTION_RENDER_STRATEGY', 'auto'),

        'include_debug_info' => null,

    ],

    /*
    | Resources
    |
    | `enable_dynamic_morph_mapping` registers each resource's RESOURCE_TYPE as
    | its model's morph alias. The application's own aliases always win, and an
    | alias claimed by more than one model is registered for none.
    |
    | `paths` are scanned at boot for resources carrying the ForModel attribute.
    | Null scans the Http/Resources directory of the app and of each module; an
    | empty array disables discovery. The result is compiled by `php artisan
    | optimize`, so rebuild or clear it after changing the paths.
    |
    | `resource_map` holds explicit model to resource bindings. An entry wins
    | over a discovered binding, so use it to pick the canonical resource or to
    | bind one outside the scanned paths.
    |
    | `fixed_fields` are always present in resource responses.
    */

    'resources' => [

        'enable_dynamic_morph_mapping' => env('DYNAMIC_MORPH_MAPPING', true),

        'paths' => null,

        'resource_map' => [
            // e.g. User::class => UserResource::class
        ],

        // Validate every registered resource schema at boot. Defaults to on
        // outside production, treating an unset APP_ENV as production
        'validate_schemas' => env('VALIDATE_SCHEMAS', env('APP_ENV', 'production') !== 'production'),

        'fixed_fields' => ['id', '_type'],

        // Columns schema validation refuses as filterable, sortable or
        // searchable, so a credential cannot be guessed one comparison at a
        // time
        'sensitive_columns' => [
            'password',
            'token',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_confirmed_at',
            'email_verified_at',
        ],

        // Select only the columns the resolved fields need, falling back to
        // SELECT * when any field's column reads are unknown. Disable it if
        // code reads attributes outside the declared fields
        'narrow_columns' => env('API_TOOLKIT_NARROW_COLUMNS', true),

    ],

    /*
    | OpenAPI exporter
    |
    | Used by the `api-toolkit:export-openapi` and `api-toolkit:docs:generate`
    | commands only; nothing here runs during a request.
    |
    | `output` is the path the document is written to when no `--output` option
    | is given.
    |
    | `docs_path` is the directory of Markdown section files assembled, in
    | filename order, into every audience's info.description. It is where the
    | `api-toolkit-docs` publish tag and `api-toolkit:docs:generate` write to;
    | while it does not exist no description is added.
    */

    'openapi' => [

        'output' => env('API_OPENAPI_OUTPUT', base_path('openapi.json')),

        'docs_path' => env('API_OPENAPI_DOCS_PATH', resource_path('api-docs')),

        // The audience exported without --audience or --all; must be declared
        // in `audiences`
        'default_audience' => env('API_OPENAPI_DEFAULT_AUDIENCE', 'public'),

        // The default info block (title, version, description) for every
        // audience; an audience may override it per key
        'info' => [],

        // OpenAPI Server Objects exported as the top-level servers, e.g.
        // ['url' => 'https://api.example.com', 'description' => 'Production'].
        // An audience's own `servers` list replaces this one
        'servers' => [],

        // The documents the exporter can produce, keyed by audience name. A
        // `blocklist` posture (the default) documents every route not excluded
        // with #[NotDocumentedIn]; an `allowlist` posture documents only routes
        // opted in with #[DocumentedIn] or ->documentedIn(). An audience may
        // also set its own `info` and `servers`. Routes marked #[Undocumented]
        // appear in no audience
        'audiences' => [
            'public' => [],
        ],

        // Operation security is read from each route's auth / auth:<guards>
        // middleware and mapped by guard driver to a security scheme; a route
        // without auth is documented as public
        'security' => [

            // Extra or replacement driver mappings, keyed by auth driver name,
            // each with a scheme `name` and an OpenAPI security scheme
            // `definition`. Built in: jwt, basic, sanctum, token, session
            'drivers' => [],

        ],

        // Routes whose handler lives under one of these namespace prefixes are
        // left out of every document. Setting it replaces the default list of
        // framework and first-party tooling namespaces
        'exclude' => [
            'namespaces' => DocumentableRouteFilter::DEFAULT_NAMESPACES,
        ],

        // Extra namespace prefixes, such as an installed package's, to scan for
        // ApiException subclasses declaring a CODE; the application and its
        // modules are always scanned
        'exception_namespaces' => [],

    ],

    /*
    | Query parser
    |
    | `alias` is the container binding the parser is resolved by, and
    | `register_middleware` registers the middleware that parses each request.
    */

    'parser' => [

        'alias' => 'api.query',

        'register_middleware' => env('API_PARSER_REGISTER_MIDDLEWARE', true),

        // The largest `limit` a client may request; a larger one is rejected
        // with a 422, not reduced. 0 or null disables it
        'max_limit' => env('API_PARSER_MAX_LIMIT', 100),

        'defaults' => [
            'limit' => env('API_PARSER_DEFAULT_LIMIT', 50),
        ],

    ],

    /*
    | Repositories
    |
    | The per-query repository cache is configured in the
    | sinemacula/laravel-repositories package, under `repositories.cache`.
    */

    'repositories' => [

        // Allow the `random` order keyword. Off by default because a random
        // sort reads the whole table; while off it is treated as a column name
        'allow_random_order' => env('API_TOOLKIT_ALLOW_RANDOM_ORDER', false),

        // Seconds a relation-detection lookup is cached, bounding a key space
        // that client input can grow
        'relation_cache_ttl' => env('API_TOOLKIT_RELATION_CACHE_TTL', 86400),

    ],

    /*
    | Query cost
    |
    | Caps on the structural cost of a single request, which is rejected with a
    | 422 naming the parameter, the cap and the value supplied. Tune them to
    | your own traffic; 0 or null disables a cap.
    |
    | `max_bytes`       - byte length of the filter document.
    | `max_parse_depth` - nested object levels in the filter document.
    | `max_depth`       - levels the filter dispatcher descends; a logical group
    |                     or a relation subquery is one level.
    | `max_nodes`       - total filter keys visited.
    | `max_in_items`    - items in one operator value list, such as `$in`.
    | `max_order_keys`  - sort columns.
    | `max_aggregates`  - relation counts, sums and averages combined.
    | `max_offset`      - rows skipped to reach a page, (page - 1) x page size;
    |                     cursor pagination is exempt.
    */

    'query_cost' => [

        'max_bytes' => env('API_TOOLKIT_QUERY_MAX_BYTES', 8192),

        'max_parse_depth' => env('API_TOOLKIT_QUERY_MAX_PARSE_DEPTH', 16),

        'max_depth' => env('API_TOOLKIT_QUERY_MAX_DEPTH', 3),

        'max_nodes' => env('API_TOOLKIT_QUERY_MAX_NODES', 100),

        'max_in_items' => env('API_TOOLKIT_QUERY_MAX_IN_ITEMS', 500),

        'max_order_keys' => env('API_TOOLKIT_QUERY_MAX_ORDER_KEYS', 3),

        'max_aggregates' => env('API_TOOLKIT_QUERY_MAX_AGGREGATES', 5),

        'max_offset' => env('API_TOOLKIT_QUERY_MAX_OFFSET', 500000),

    ],

    /*
    | Free-text search
    |
    | Bounds on the `search` parameter, which matches only the root resource's
    | searchable columns. A term outside them is rejected with a 422.
    |
    | `min_word_length` is the shortest word a term may carry. It may be raised
    | but not lowered below 3, since shorter words are answered wrongly or by a
    | full scan on supported engines.
    |
    | `max_length` and `max_words` cap the length and word count of a term.
    |
    | Drivers ship for MySQL, PostgreSQL and SQLite; any other connection,
    | MariaDB included, refuses a search until a driver is registered for it.
    | The backing indexes are the application's to create: on MySQL an
    | anywhere-match needs one FULLTEXT ... WITH PARSER ngram index over exactly
    | its columns and cannot sit beside another strategy; on PostgreSQL prefix
    | and anywhere matches need a pg_trgm trigram index; an equality match needs
    | an ordinary index leading with the column.
    */

    'search' => [

        'min_word_length' => env('API_TOOLKIT_SEARCH_MIN_WORD_LENGTH', 3),

        'max_length' => env('API_TOOLKIT_SEARCH_MAX_LENGTH', 128),

        'max_words' => env('API_TOOLKIT_SEARCH_MAX_WORDS', 10),

        // Seconds the request-time index proof is shared before the catalogue
        // is read again; a migration or api-toolkit:invalidate-metadata retires
        // it sooner. 0 keeps it for one operation; a negative or non-numeric
        // value falls back to 60
        'index_proof_ttl' => env('API_TOOLKIT_SEARCH_INDEX_PROOF_TTL', 60),

        // Connection names (config/database.php keys) allowed to serve a search
        // no index can be proved for. A listed connection may scan the whole
        // table on every request, so name development connections only. SQLite
        // can never prove an index, so it must be listed to serve a search
        'unverified_connections' => [],

    ],

    /*
    | Deferred writes
    |
    | The write pool buffers inserts from repositories using the Deferrable
    | trait and flushes them as bulk INSERTs at the end of the request, command
    | or job. Buffered records are lost if the process dies before the flush.
    |
    | `chunk_size` is the most records per INSERT; keep it below the database's
    | binding limit divided by the widest deferred table's column count.
    |
    | `pool_limit` is the buffered record count that triggers a flush.
    |
    | `on_failure` decides what a failed chunk insert does:
    |   'collect' - keep the failed records for the next flush (default).
    |   'throw'   - throw WritePoolFlushException on the first failure, keeping
    |               the failed and unprocessed records.
    |   'log'     - log a warning and drop the failed records; only for
    |               disposable writes such as telemetry.
    |
    | `transactional` makes each table's inserts all-or-nothing.
    |
    | `rethrow_at_boundary` re-throws a 'throw' strategy failure at the
    | lifecycle boundary instead of only reporting it.
    |
    | `invalidate_query_cache` clears the repository query cache for every
    | table a boundary flush wrote to. Repositories on a custom cache store or
    | key prefix must invalidate themselves.
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
    | Cache lifecycle
    |
    | `octane` and `queue` reset the toolkit's in-process caches after every
    | Octane operation or queue job, so long-lived workers do not grow and pick
    | up invalidations made elsewhere. They only engage when actually serving
    | under Octane or a non-sync queue worker.
    |
    | `migrations` invalidates the cached schema metadata whenever a migration
    | run applies or rolls back a migration. Old-code workers can refill it
    | before the release goes live, so also run api-toolkit:invalidate-metadata
    | once it is.
    */

    'lifecycle' => [

        'octane' => env('API_TOOLKIT_LIFECYCLE_OCTANE', true),

        'queue' => env('API_TOOLKIT_LIFECYCLE_QUEUE', true),

        'migrations' => env('API_TOOLKIT_LIFECYCLE_MIGRATIONS', true),

    ],

    /*
    | Services
    |
    | `correlation_header` is the request header ServiceContext::fromRequest()
    | reads the correlation id from. A value of 1 to 128 printable ASCII
    | characters with no whitespace is used as given; otherwise an id is
    | generated. Null or an empty string always generates one.
    */

    'services' => [

        'correlation_header' => env('API_TOOLKIT_SERVICES_CORRELATION_HEADER', 'X-Request-Id'),

    ],

    /*
    | Notifications
    |
    | `enable_logging` writes an audit log entry for notification events.
    |
    | `excluded_classes` lists notification classes left out of the audit log.
    |
    | `log_channel` is the log channel the audit log is written to; null uses
    | the application's default channel.
    */

    'notifications' => [

        'enable_logging' => env('ENABLE_NOTIFICATION_LOGGING', true),

        'excluded_classes' => [
            // e.g. VerifyEmail::class
        ],

        'log_channel' => env('NOTIFICATION_LOG_CHANNEL'),

    ],

    /*
    | Middleware
    |
    | `maintenance_mode_swap.enabled` replaces Laravel's
    | PreventRequestsDuringMaintenance in the global stack with the toolkit's,
    | in the same position after HandleCors. Nothing is added if Laravel's is
    | not registered.
    |
    | `json_pretty_print` registers the JsonPrettyPrint middleware, letting
    | clients request pretty-printed JSON; `scope` is 'global' or 'api' (the api
    | middleware group only).
    |
    | `throttle.enabled` registers the toolkit's ThrottleRequests as the
    | 'throttle' alias, using the Redis variant when the default cache store is
    | 'redis'. `throttle.class` replaces it with your own class, for example one
    | using ThrottleRequestsTrait that overrides resolveRequestSignature() to
    | key guests by something other than their IP.
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
    | Maintenance mode
    |
    | `except` lists the URIs that stay reachable during maintenance mode.
    */

    'maintenance_mode' => [

        'except' => [],

    ],

];
