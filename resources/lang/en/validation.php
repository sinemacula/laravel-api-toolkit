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
        'relation_names'       => 'The :operator filter must list relation names.',
    ],

    'order' => [
        'unpermitted_direction' => 'The ":direction" direction is not permitted on the ":key" key, which accepts :accepts.',
        'random_cursor'         => 'Random order cannot be paged by cursor; use offset pagination.',
        'nullable_cursor'       => 'The ":key" key admits null, so it cannot order a cursor-paginated read; use offset pagination.',
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

];
