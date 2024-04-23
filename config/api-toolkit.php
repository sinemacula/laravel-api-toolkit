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
        'prefix' => 'sm-api-toolkit'
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
    | `morph_map`: This array should contain the mappings of model classes to
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

        'morph_map' => [
            // This should be filled with the application's morph map
            // e.g. \App\Models\User::class => \App\Http\Resources\UserResource::class
        ],

        'fixed_fields' => ['id', '_type']

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
            'limit' => env('API_PARSER_DEFAULT_LIMIT', 50)
        ]

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
    */

    'repositories' => [

        'cast_map'              => [
            'string'  => [
                'string',
                'date',
                'datetime',
                'immutable_date',
                'immutable_datetime',
                'decimal:.*',
                'double',
                'encrypted',
                'float',
                'real',
                'hashed',
                AsStringable::class
            ],
            'integer' => ['integer', 'int'],
            'boolean' => ['boolean', 'bool'],
            'array'   => ['array', 'collection', 'encrypted:array', 'encrypted:collection'],
            'object'  => ['object', 'encrypted:object']
        ],

        // This configuration allows both columns, and columns for specific
        // tables e.g. users.password
        'searchable_exclusions' => ['password']

    ]

];
