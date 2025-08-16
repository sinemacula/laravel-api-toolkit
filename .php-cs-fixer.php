<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = Finder::create()
    ->in([
        __DIR__ . '/config',
        __DIR__ . '/src',
        __DIR__ . '/tests'
    ])
    ->name('*.php')
    ->notName('*.blade.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config)
    ->setFinder($finder)
    ->setUsingCache(true)
    ->setRiskyAllowed(true)
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRules([

        /*
        |-----------------------------------------------------------------------
        | Preset
        |-----------------------------------------------------------------------
        */
        '@PSR12'                                           => true,

        /*
        |-----------------------------------------------------------------------
        | Alias rules
        |-----------------------------------------------------------------------
        */
        'backtick_to_shell_exec'                           => true,
        'modernize_strpos'                                 => true,
        'no_alias_language_construct_call'                 => true,
        'no_mixed_echo_print'                              => ['use' => 'echo'],
        'pow_to_exponentiation'                            => true,
        'random_api_migration'                             => [
            'replacements' => [
                'getrandmax' => 'mt_getrandmax',
                'rand'       => 'mt_rand',
                'srand'      => 'mt_srand'
            ]
        ],
        'set_type_to_cast'                                 => true,

        /*
        |-----------------------------------------------------------------------
        | Array notation rules
        |-----------------------------------------------------------------------
        */
        'array_syntax'                                     => ['syntax' => 'short'],
        'no_multiline_whitespace_around_double_arrow'      => true,
        'no_whitespace_before_comma_in_array'              => true,
        'normalize_index_brace'                            => true,
        'return_to_yield_from'                             => true,
        'trim_array_spaces'                                => true,
        'whitespace_after_comma_in_array'                  => ['ensure_single_space' => true],

        /*
        |-----------------------------------------------------------------------
        | Attribute notation rules
        |-----------------------------------------------------------------------
        */
        'attribute_empty_parentheses'                      => ['use_parentheses' => false],
        'ordered_attributes'                               => ['sort_algorithm' => 'alpha', 'order' => []],

        /*
        |-----------------------------------------------------------------------
        | Basic rules
        |-----------------------------------------------------------------------
        */
        'braces_position'                                  => [
            'allow_single_line_anonymous_functions'     => true,
            'allow_single_line_empty_anonymous_classes' => true,
            'anonymous_classes_opening_brace'           => 'same_line',
            'anonymous_functions_opening_brace'         => 'same_line',
            'classes_opening_brace'                     => 'next_line_unless_newline_at_signature_end',
            'control_structures_opening_brace'          => 'same_line',
            'functions_opening_brace'                   => 'next_line_unless_newline_at_signature_end'
        ],
        'encoding'                                         => true,
        'no_multiple_statements_per_line'                  => true,
        'no_trailing_comma_in_singleline'                  => [
            'elements' => [
                'arguments',
                'array',
                'array_destructuring',
                'group_import'
            ]
        ],
        'non_printable_character'                          => ['use_escape_sequences_in_strings' => false],
        'numeric_literal_separator'                        => ['strategy' => 'no_separator'],
        'single_line_empty_body'                           => true,

        /*
        |-----------------------------------------------------------------------
        | Casing rules
        |-----------------------------------------------------------------------
        */
        'class_reference_name_casing'                      => true,
        'constant_case'                                    => ['case' => 'lower'],
        'integer_literal_case'                             => true,
        'lowercase_keywords'                               => true,
        'lowercase_static_reference'                       => true,
        'magic_constant_casing'                            => true,
        'magic_method_casing'                              => true,
        'native_function_casing'                           => true,
        'native_type_declaration_casing'                   => true,

        /*
        |-----------------------------------------------------------------------
        | Cast notation rules
        |-----------------------------------------------------------------------
        */
        'cast_spaces'                                      => ['space' => 'single'],
        'lowercase_cast'                                   => true,
        'modernize_types_casting'                          => true,
        'no_short_bool_cast'                               => true,
        'no_unset_cast'                                    => true,
        'short_scalar_cast'                                => true,
        'class_attributes_separation'                      => [
            'elements' => [
                'const'        => 'only_if_meta',
                'method'       => 'one',
                'property'     => 'only_if_meta',
                'trait_import' => 'none',
                'case'         => 'none'
            ]
        ],
        'class_definition'                                 => [
            'inline_constructor_arguments'        => true,
            'multi_line_extends_each_single_line' => false,
            'single_item_single_line'             => true,
            'single_line'                         => true,
            'space_before_parenthesis'            => true
        ],
        'no_blank_lines_after_class_opening'               => true,
        'no_null_property_initialization'                  => true,
        'no_php4_constructor'                              => true,
        'no_unneeded_final_method'                         => true,
        'ordered_class_elements'                           => [
            'order' => [
                'use_trait',
                'case',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'destruct',
                'magic',
                'phpunit',
                'method_public',
                'method_protected',
                'method_private'
            ]
        ],
        'ordered_interfaces'                               => [
            'direction' => 'ascend',
            'order'     => 'alpha'
        ],
        'ordered_traits'                                   => true,
        'ordered_types'                                    => [
            'null_adjustment' => 'always_last',
            'sort_algorithm'  => 'alpha'
        ],
        'phpdoc_readonly_class_comment_to_keyword'         => true,
        'protected_to_private'                             => true,
        'self_accessor'                                    => true,
        'self_static_accessor'                             => true,
        'single_class_element_per_statement'               => [
            'elements' => ['const', 'property']
        ],
        'single_trait_insert_per_statement'                => false,
        'visibility_required'                              => [
            'elements' => ['const', 'method', 'property']
        ],

        /*
        |-----------------------------------------------------------------------
        | Comment rules
        |-----------------------------------------------------------------------
        */
        'header_comment'                                   => false,
        'multiline_comment_opening_closing'                => true,
        'no_empty_comment'                                 => true,
        'no_trailing_whitespace_in_comment'                => true,
        'single_line_comment_spacing'                      => true,
        'single_line_comment_style'                        => [
            'comment_types' => ['asterisk', 'hash']
        ],

        /*
        |-----------------------------------------------------------------------
        | Constant notation rules
        |-----------------------------------------------------------------------
        */
        'native_constant_invocation'                       => false,

        /*
        |-----------------------------------------------------------------------
        | Control structure rules
        |-----------------------------------------------------------------------
        */
        'control_structure_braces'                         => true,
        'control_structure_continuation_position'          => ['position' => 'same_line'],
        'elseif'                                           => true,
        'empty_loop_body'                                  => ['style' => 'semicolon'],
        'empty_loop_condition'                             => ['style' => 'while'],
        'include'                                          => true,
        'no_alternative_syntax'                            => true,
        'no_break_comment'                                 => false,
        'no_superfluous_elseif'                            => true,
        'no_unneeded_braces'                               => true,
        'no_unneeded_control_parentheses'                  => true,
        'no_useless_else'                                  => true,
        'simplified_if_return'                             => true,
        'switch_case_semicolon_to_colon'                   => true,
        'switch_case_space'                                => true,
        'switch_continue_to_break'                         => true,
        'trailing_comma_in_multiline'                      => false,
        'yoda_style'                                       => false,

        /*
        |-----------------------------------------------------------------------
        | Function annotation rules
        |-----------------------------------------------------------------------
        */
        'combine_nested_dirname'                           => true,
        'function_declaration'                             => [
            'closure_fn_spacing'       => 'one',
            'closure_function_spacing' => 'one'
        ],
        'implode_call'                                     => true,
        'lambda_not_used_import'                           => true,
        'method_argument_space'                            => [
            'attribute_placement'              => 'standalone',
            'keep_multiple_spaces_after_comma' => false,
            'on_multiline'                     => 'ignore'
        ],
        'no_spaces_after_function_name'                    => true,
        'no_unreachable_default_argument_value'            => true,
        'no_useless_sprintf'                               => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'phpdoc_to_param_type'                             => [
            'scalar_types' => true,
            'union_types'  => true
        ],
        // Don't enable this as it will break Laravel extended classes
        'phpdoc_to_property_type'                          => false,
        'phpdoc_to_return_type'                            => [
            'scalar_types' => true,
            'union_types'  => true
        ],
        'regular_callable_call'                            => true,
        'return_type_declaration'                          => [
            'space_before' => 'none'
        ],
        'single_line_throw'                                => true,
        'static_lambda'                                    => false,
        'use_arrow_functions'                              => true,
        'void_return'                                      => true,

        /*
        |-----------------------------------------------------------------------
        | Import rules
        |-----------------------------------------------------------------------
        */
        'fully_qualified_strict_types'                     => [
            'import_symbols' => true,
            'phpdoc_tags'    => [],
        ],
        'global_namespace_import'                          => [
            'import_classes'   => true,
            'import_constants' => false,
            'import_functions' => false
        ],
        'group_import'                                     => false,
        'no_leading_import_slash'                          => true,
        'no_unneeded_import_alias'                         => true,
        'no_unused_imports'                                => false,
        'ordered_imports'                                  => ['sort_algorithm' => 'alpha'],
        'single_import_per_statement'                      => true,
        'single_line_after_imports'                        => true,

        /*
        |-----------------------------------------------------------------------
        | Language construct rules
        |-----------------------------------------------------------------------
        */
        'class_keyword'                                    => true,
        'combine_consecutive_issets'                       => true,
        'combine_consecutive_unsets'                       => true,
        'declare_equal_normalize'                          => ['space' => 'single'],
        'declare_parentheses'                              => true,
        'dir_constant'                                     => true,
        'error_suppression'                                => false,
        'explicit_indirect_variable'                       => true,
        'function_to_constant'                             => true,
        'get_class_to_class_keyword'                       => true,
        'is_null'                                          => false,
        'no_unset_on_property'                             => false,
        'nullable_type_declaration'                        => ['syntax' => 'question_mark'],
        'single_space_around_construct'                    => true,

        /*
        |-----------------------------------------------------------------------
        | List notation rules
        |-----------------------------------------------------------------------
        */
        'list_syntax'                                      => [
            'syntax' => 'short'
        ],

        /*
        |-----------------------------------------------------------------------
        | Namespace notation rules
        |-----------------------------------------------------------------------
        */
        'blank_line_after_namespace'                       => true,
        'blank_lines_before_namespace'                     => true,
        'clean_namespace'                                  => true,
        'no_leading_namespace_whitespace'                  => true,

        /*
        |-----------------------------------------------------------------------
        | Naming rules
        |-----------------------------------------------------------------------
        */
        'no_homoglyph_names'                               => true,

        /*
        |-----------------------------------------------------------------------
        | Operator rules
        |-----------------------------------------------------------------------
        */
        'assign_null_coalescing_to_coalesce_equal'         => true,
        'binary_operator_spaces'                           => [
            'default' => 'align_single_space_minimal'
        ],
        'concat_space'                                     => ['spacing' => 'one'],
        'increment_style'                                  => ['style' => 'post'],
        'logical_operators'                                => true,
        'long_to_shorthand_operator'                       => true,
        'new_with_parentheses'                             => [
            'anonymous_class' => false,
            'named_class'     => false
        ],
        'no_space_around_double_colon'                     => true,
        'no_useless_concat_operator'                       => [
            'juggle_simple_strings' => true
        ],
        'no_useless_nullsafe_operator'                     => true,
        'not_operator_with_space'                          => false,
        'not_operator_with_successor_space'                => false,
        'object_operator_without_whitespace'               => true,
        'operator_linebreak'                               => [
            'only_booleans' => false,
            'position'      => 'beginning'
        ],
        'standardize_increment'                            => true,
        'standardize_not_equals'                           => true,
        'ternary_operator_spaces'                          => true,
        'ternary_to_elvis_operator'                        => false,
        'ternary_to_null_coalescing'                       => true,
        'unary_operator_spaces'                            => true,

        /*
        |-----------------------------------------------------------------------
        | PHP tag rules
        |-----------------------------------------------------------------------
        */
        'blank_line_after_opening_tag'                     => true,
        'echo_tag_syntax'                                  => [
            'format'                         => 'short',
            'shorten_simple_statements_only' => false
        ],
        'full_opening_tag'                                 => true,
        'linebreak_after_opening_tag'                      => true,
        'no_closing_tag'                                   => true,

        /*
        |-----------------------------------------------------------------------
        | PHPUnit rules
        |-----------------------------------------------------------------------
        */
        'php_unit_assert_new_names'                        => true,
        'php_unit_attributes'                              => [
            'keep_annotations' => false
        ],
        'php_unit_construct'                               => [
            'assertions' => ['assertEquals', 'assertNotEquals', 'assertNotSame', 'assertSame']
        ],
        'php_unit_data_provider_method_order'              => [
            'placement' => 'before'
        ],
        'php_unit_data_provider_name'                      => false,
        'php_unit_data_provider_return_type'               => true,
        'php_unit_data_provider_static'                    => true,
        'php_unit_dedicate_assert'                         => false,
        'php_unit_dedicate_assert_internal_type'           => [
            'target' => 'newest'
        ],
        'php_unit_expectation'                             => [
            'target' => 'newest'
        ],
        'php_unit_fqcn_annotation'                         => true,
        'php_unit_internal_class'                          => [
            'types' => ['normal', 'final']
        ],
        'php_unit_method_casing'                           => [
            'case' => 'camel_case'
        ],
        'php_unit_mock'                                    => [
            'target' => 'newest'
        ],
        'php_unit_mock_short_will_return'                  => true,
        'php_unit_namespaced'                              => [
            'target' => 'newest'
        ],
        'php_unit_no_expectation_annotation'               => [
            'target'          => 'newest',
            'use_class_const' => true
        ],
        'php_unit_set_up_tear_down_visibility'             => true,
        'php_unit_size_class'                              => false,
        'php_unit_strict'                                  => false,
        'php_unit_test_annotation'                         => [
            'style' => 'prefix'
        ],
        'php_unit_test_case_static_method_calls'           => [
            'call_type' => 'static'
        ],
        'php_unit_test_class_requires_covers'              => true,

        /*
        |-----------------------------------------------------------------------
        | PHPDoc rules
        |-----------------------------------------------------------------------
        */
        'align_multiline_comment'                          => [
            'comment_type' => 'phpdocs_like'
        ],
        'general_phpdoc_annotation_remove'                 => false,
        'general_phpdoc_tag_rename'                        => true,
        'no_blank_lines_after_phpdoc'                      => true,
        'no_empty_phpdoc'                                  => true,
        'no_superfluous_phpdoc_tags'                       => false,
        'phpdoc_add_missing_param_annotation'              => [
            'only_untyped' => false
        ],
        'phpdoc_align'                                     => [
            'align'   => 'left',
            'spacing' => ['param' => 2]
        ],
        'phpdoc_annotation_without_dot'                    => true,
        'phpdoc_array_type'                                => true,
        'phpdoc_indent'                                    => true,
        'phpdoc_inline_tag_normalizer'                     => true,
        'phpdoc_line_span'                                 => [
            'const'    => 'single',
            'property' => 'single',
            'method'   => 'multi'
        ],
        'phpdoc_list_type'                                 => false,
        'phpdoc_no_access'                                 => true,
        'phpdoc_no_alias_tag'                              => true,
        'phpdoc_no_empty_return'                           => false,
        'phpdoc_no_package'                                => true,
        'phpdoc_no_useless_inheritdoc'                     => true,
        'phpdoc_order_by_value'                            => true,
        'phpdoc_order'                                     => [
            'order' => ['param', 'return', 'throws']
        ],
        'phpdoc_param_order'                               => true,
        'phpdoc_return_self_reference'                     => [
            'replacements' => [
                'this'    => 'self',
                '@this'   => 'self',
                '$self'   => 'self',
                '@self'   => 'self',
                '$static' => 'static',
                '@static' => 'static'
            ]
        ],
        'phpdoc_scalar'                                    => true,
        'phpdoc_separation'                                => [
            'groups' => [
                ['author', 'copyright', 'license'],
                ['category', 'package', 'subpackage'],
                ['property', 'property-read', 'property-write'],
                ['deprecated', 'link', 'see', 'since'],
                ['param', 'return'],
                ['throws']
            ]
        ],
        'phpdoc_single_line_var_spacing'                   => true,
        'phpdoc_summary'                                   => true,
        'phpdoc_tag_casing'                                => true,
        'phpdoc_tag_type'                                  => true,
        'phpdoc_to_comment'                                => [
            'allow_before_return_statement' => true
        ],
        'phpdoc_trim_consecutive_blank_line_separation'    => true,
        'phpdoc_trim'                                      => true,
        'phpdoc_types'                                     => true,
        'phpdoc_types_order'                               => [
            'null_adjustment' => 'always_last',
            'sort_algorithm'  => 'alpha'
        ],
        'phpdoc_var_annotation_correct_order'              => true,
        'phpdoc_var_without_name'                          => true,

        /*
        |-----------------------------------------------------------------------
        | Return notation rules
        |-----------------------------------------------------------------------
        */
        'no_useless_return'                                => true,
        'return_assignment'                                => true,
        'simplified_null_return'                           => true,

        /*
        |-----------------------------------------------------------------------
        | Semicolon rules
        |-----------------------------------------------------------------------
        */
        'multiline_whitespace_before_semicolons'           => [
            'strategy' => 'no_multi_line'
        ],
        'no_empty_statement'                               => true,
        'no_singleline_whitespace_before_semicolons'       => true,
        'semicolon_after_instruction'                      => true,
        'space_after_semicolon'                            => [
            'remove_in_empty_for_expressions' => true
        ],

        /*
        |-----------------------------------------------------------------------
        | Strict rules
        |-----------------------------------------------------------------------
        */
        'declare_strict_types'                             => false,
        'strict_comparison'                                => true,
        'strict_param'                                     => true,

        /*
        |-----------------------------------------------------------------------
        | String notation rules
        |-----------------------------------------------------------------------
        */
        'explicit_string_variable'                         => true,
        'heredoc_closing_marker'                           => true,
        'heredoc_to_nowdoc'                                => true,
        'multiline_string_to_heredoc'                      => true,
        'no_binary_string'                                 => true,
        'no_trailing_whitespace_in_string'                 => false,
        'simple_to_complex_string_variable'                => true,
        'single_quote'                                     => [
            'strings_containing_single_quote_chars' => true
        ],
        'string_implicit_backslashes'                      => true,
        'string_line_ending'                               => true,
        'string_length_to_empty'                           => true,

        /*
        |-----------------------------------------------------------------------
        | Whitespace rules
        |-----------------------------------------------------------------------
        */
        'array_indentation'                                => true,
        'blank_line_before_statement'                      => [
            'statements' => ['do', 'for', 'foreach', 'if', 'switch', 'try', 'while']
        ],
        'blank_line_between_import_groups'                 => false,
        'compact_nullable_type_declaration'                => true,
        'heredoc_indentation'                              => true,
        'indentation_type'                                 => true,
        'line_ending'                                      => true,
        'method_chaining_indentation'                      => true,
        'no_extra_blank_lines'                             => true,
        'no_spaces_around_offset'                          => [
            'positions' => ['inside', 'outside']
        ],
        'no_trailing_whitespace'                           => true,
        'no_whitespace_in_blank_line'                      => true,
        'single_blank_line_at_eof'                         => true,
        'spaces_inside_parentheses'                        => true,
        'statement_indentation'                            => true,
        'type_declaration_spaces'                          => [
            'elements' => ['constant', 'function', 'property']
        ],
        'types_spaces'                                     => true

    ]);
