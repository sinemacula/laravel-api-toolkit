<?php

declare(strict_types = 1);

return [

    'query' => [
        'undeclared_key' => 'The ":key" key is not a permitted query parameter for this resource.',
    ],

    'filters' => [
        'not_object'           => 'The filters parameter must be a JSON object.',
        'unpermitted_operator' => 'The ":operator" operator is not permitted on the ":key" key for this resource, which accepts :accepts.',
        'unnamed_key'          => 'The :position filter must name a field or a condition. To match any of several values, give the field a condition such as $in.',
        'not_document'         => 'The :position filter must be a document of conditions.',
        'empty_document'       => 'The :position filter must carry at least one condition.',
        'not_group'            => 'The :position filter must be a non-empty list of filter documents.',
        'not_condition'        => 'The :position filter is not a condition. A field accepts only its registered condition tokens, such as $eq or $in.',
        'fieldless_condition'  => 'The :position condition must be given under a field.',
        'no_effect'            => 'The :position filter adds no condition, so it would match every row.',
        'relation_names'       => 'The :position filter must name a relation.',
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
