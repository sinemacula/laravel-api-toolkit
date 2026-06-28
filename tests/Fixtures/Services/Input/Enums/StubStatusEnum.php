<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Input\Enums;

/**
 * Minimal backed enum for RuleCompiler enum-rule fixture tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum StubStatusEnum: string
{
    /** Active status. */
    case ACTIVE = 'active';

    /** Inactive status. */
    case INACTIVE = 'inactive';
}
