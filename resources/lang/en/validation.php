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

    'search' => [
        'not_permitted'  => 'The search parameter is not permitted for this resource.',
        'word_too_short' => 'Every word in the search term must be at least :min characters.',
        'too_long'       => 'The search term may not be longer than :max characters.',
        'too_many_words' => 'The search term may not carry more than :max words.',
    ],

];
