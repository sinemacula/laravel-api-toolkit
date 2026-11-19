<?php

declare(strict_types = 1);

return [

    'query' => [
        'undeclared_key'     => 'The ":key" key is not a permitted query parameter for this resource.',
        'malformed_encoding' => 'The :parameter parameter must be valid UTF-8.',
    ],

    'fields' => [
        'not_keyed'    => 'The fields parameter must be keyed by resource type, e.g. fields[users]=name.',
        'unknown_type' => 'The ":type" resource type is not known.',
        'undeclared'   => 'The ":name" field is not declared on the ":type" resource type.',
    ],

    'aggregates' => [
        'not_keyed'    => 'The aggregates parameter must be keyed by resource type, e.g. aggregates[users]=posts_count.',
        'unknown_type' => 'The ":type" resource type is not known.',
        'undeclared'   => 'The ":name" aggregate is not declared on the ":type" resource type.',
    ],

    'filters' => [
        'not_object'           => 'The filters parameter must be a JSON object.',
        'unpermitted_operator' => 'The ":operator" operator is not permitted on the ":key" key for this resource, which accepts :accepts.',
        'no_operators'         => 'The ":key" key accepts no registered filter operators.',
        'unnamed_key'          => 'The :position filter must name a field or a condition. To match any of several values, give the field a condition such as $in.',
        'not_document'         => 'The :position filter must be a document of conditions.',
        'empty_document'       => 'The :position filter must carry at least one condition.',
        'not_group'            => 'The :position filter must be a non-empty list of filter documents.',
        'not_condition'        => 'The :position filter is not a condition. A field accepts only its registered condition tokens, such as $eq or $in.',
        'fieldless_condition'  => 'The :position condition must be given under a field.',
        'no_effect'            => 'The :position filter adds no condition, so it would match every row.',
        'relation_names'       => 'The :position filter must name a relation.',
        'value_null'           => 'The :operator condition on ":column" cannot be null; $null matches a missing value and $notNull a present one.',
        'value_scalar'         => 'The :operator condition on ":column" must be a single value.',
        'value_list'           => 'The :operator condition on ":column" must be a list of values.',
        'value_bounds'         => 'The :operator condition on ":column" must be a list of two values, [low, high].',
        'value_presence'       => 'The :operator condition on ":column" takes only true; $null matches a missing value and $notNull a present one.',
        'value_type'           => 'The :operator condition on ":column" must be :expected.',
        'item_type'            => 'Item :index of the :operator condition on ":column" must be :expected.',
        'expected'             => [
            'scalar'          => 'a single value other than null',
            'integer'         => 'an integer',
            'integer_between' => 'an integer from :min to :max',
            'number'          => 'a finite number',
            'single'          => 'a number within single-precision range',
            'boolean'         => 'true, false, 0 or 1',
            'date'            => 'a date as YYYY-MM-DD',
            'datetime'        => 'a date-time as YYYY-MM-DDTHH:MM[:SS[.fraction]], optionally ending in Z or an offset such as +02:00, or a date as YYYY-MM-DD',
            'uuid'            => 'a UUID',
            'text'            => 'a string or a number',
            'nul'             => 'free of NUL characters',
            'enum'            => 'one of :values',
        ],
    ],

    'order' => [
        'unpermitted_direction'  => 'The ":direction" direction is not permitted on the ":key" key, which accepts :accepts.',
        'random_cursor'          => 'Random order cannot be paged by cursor; use offset pagination.',
        'nullable_cursor'        => 'The ":key" key admits null, so it cannot order a cursor-paginated read; use offset pagination.',
        'relevance_needs_search' => 'The relevance order ranks search matches, so it needs a search term.',
        'relevance_cursor'       => 'Relevance order cannot be paged by cursor; use offset pagination.',
        'relevance_direction'    => 'The relevance key accepts asc only.',
    ],

    'cursor' => [
        'malformed' => 'The cursor could not be read; restart from the first page.',
        'mismatch'  => 'The cursor does not match the requested order; restart from the first page.',
        'keyless'   => 'This collection has no key to page by cursor; use offset pagination.',
    ],

    'search' => [
        'not_permitted'  => 'The search parameter is not permitted for this resource.',
        'word_too_short' => 'Every word in the search term must be at least :min characters.',
        'too_long'       => 'The search term may not be longer than :max characters.',
        'too_many_words' => 'The search term may not carry more than :max words.',
    ],

    'trashed' => [
        'not_permitted' => 'The trashed parameter is not permitted for this resource.',
    ],

];
