<?php

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
    | API Auto Discovery Configuration
    |---------------------------------------------------------------------------
    |
    | Auto discovery can derive model/resource and repository maps from
    | convention-based class locations. This can significantly reduce manual
    | map maintenance in larger applications.
    |
    | `enabled`: Master toggle for all auto discovery behavior.
    |
    | `cache`: Discovery map cache controls. Lower TTL values pick up new files
    | faster. Higher values reduce filesystem scan frequency.
    |
    | `modules`: Optional module-root settings for modular applications.
    | - `paths`: Absolute module root directories. If empty, discovery will also
    |   attempt known conventions (`module_path()`, `base_path('modules')`).
    | - `namespace_prefixes`: Namespace roots prepended to each module name.
    |   Example: ["Verifast\\"] + module directory "Applications" produces
    |   "Verifast\\Applications\\...".
    |
    */

    'auto_discovery' => [

        'enabled' => env('API_TOOLKIT_AUTO_DISCOVERY_ENABLED', false),

        'cache' => [
            'enabled' => env('API_TOOLKIT_AUTO_DISCOVERY_CACHE_ENABLED', true),
            'ttl'     => env('API_TOOLKIT_AUTO_DISCOVERY_CACHE_TTL', 300),
        ],

        'modules' => [
            'enabled' => env('API_TOOLKIT_AUTO_DISCOVERY_MODULES_ENABLED', true),
            'paths'   => array_values(array_filter(
                array_map('trim', explode(',', env('API_TOOLKIT_AUTO_DISCOVERY_MODULE_PATHS', ''))),
                static fn (string $path): bool => $path !== '',
            )),
            'namespace_prefixes' => array_values(array_filter(
                array_map('trim', explode(',', env('API_TOOLKIT_AUTO_DISCOVERY_MODULE_NAMESPACE_PREFIXES', ''))),
                static fn (string $prefix): bool => $prefix !== '',
            )),
        ],

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
            // e.g. \App\Models\User::class => \App\Http\Resources\UserResource::class
        ],

        'auto_discovery' => [

            // Enable model -> resource map auto discovery.
            'enabled' => env('API_TOOLKIT_AUTO_DISCOVERY_RESOURCES_ENABLED', false),

            // Include conventional root:
            // app/Models -> App\Http\Resources\*Resource
            'include_standard_root' => true,

            // Additional roots to scan for models.
            // Format:
            // ['path' => '/abs/path/to/models', 'namespace' => 'Vendor\\Domain\\Models\\']
            'roots' => [],

            // Namespace conversion and suffix used to derive resources.
            'model_namespace_segment'    => '\Models\\',
            'resource_namespace_segment' => '\Http\Resources\\',
            'resource_suffix'            => 'Resource',
        ],

        'fixed_fields' => ['id', '_type'],

    ],

    /*
    |---------------------------------------------------------------------------
    | Resource Export Configuration
    |---------------------------------------------------------------------------
    |
    | This configuration controls the options for exporting resources in
    | different formats. You can enable or disable resource exporting and
    | specify the formats that are supported for export.
    |
    | Supported Formats: "csv", "xml"
    |
    */

    'exports' => [

        'enabled' => env('RESOURCE_EXPORT_ENABLED', true),

        'supported_formats' => explode(',', env('RESOURCE_EXPORT_FORMATS', 'csv,xml')),

        'ignored_fields' => ['_type', 'password', 'token', 'remember_token'],

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
    | `repository_map`: This array should contain the mappings of alias to
    | the corresponding repository class. This is used to dynamically resolve
    | the repositories within the HasRepositories trait.
    |
    | `cache_resolved_instances`: Controls whether resolved repositories are
    | cached between calls. This is automatically bypassed when running under
    | Laravel Octane to avoid leaking mutable state across requests.
    |
    */

    'repositories' => [

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

        // This configuration allows both columns, and columns for specific
        // tables e.g. users.password
        'searchable_exclusions' => ['password'],

        'repository_map' => [
            // This should be filled with the application's repository map
            // e.g. 'users' => \App\Repositories\UserRepository::class
        ],

        'auto_discovery' => [

            // Enable alias -> repository map auto discovery.
            'enabled' => env('API_TOOLKIT_AUTO_DISCOVERY_REPOSITORIES_ENABLED', false),

            // Include conventional root:
            // app/Repositories -> App\Repositories\*
            'include_standard_root' => true,

            // Additional roots to scan for repositories.
            // Format:
            // ['path' => '/abs/path/to/repositories', 'namespace' => 'Vendor\\Domain\\Repositories\\']
            'roots' => [],

            // Optional alias overrides by class name.
            // Example:
            // \App\Repositories\UserRepository::class => 'users'
            'alias_overrides' => [],

            // Optional class contract hooks for alias resolution.
            'alias_constant' => 'REPOSITORY_ALIAS',
            'alias_method'   => 'repositoryAlias',
        ],

        'cache_resolved_instances' => env('API_REPOSITORIES_CACHE_RESOLVED_INSTANCES', true),

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
    */

    'notifications' => [

        'enable_logging' => env('ENABLE_NOTIFICATION_LOGGING', true),

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

    /*
    |---------------------------------------------------------------------------
    | Logging Configuration
    |---------------------------------------------------------------------------
    |
    | This section defines the logging settings for external log providers such
    | as AWS CloudWatch. Enabling CloudWatch logging allows logs to be stored
    | and managed in AWS for monitoring and analysis.
    |
    */

    'logging' => [

        'cloudwatch' => [
            'enabled' => env('ENABLE_CLOUDWATCH_LOGGING', false),
        ],

        'request_context' => [
            'include_payload' => env('API_EXCEPTION_LOG_INCLUDE_PAYLOAD', true),
            'sensitive_keys'  => array_values(array_filter(
                array_map('trim', explode(',', env(
                    'API_EXCEPTION_LOG_SENSITIVE_KEYS',
                    'password,password_confirmation,current_password,token,api_token,access_token,refresh_token,secret,authorization',
                ))),
                static fn (string $key): bool => $key !== '',
            )),
        ],

    ],

];
