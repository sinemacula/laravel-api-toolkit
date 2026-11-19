<?php

declare(strict_types = 1);

namespace Tests\Integration\Lifecycle;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector;

/**
 * Tenant isolation suite for the SQLite engine.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(IndexEligibilityInspector::class)]
#[CoversClass(SchemaIntrospector::class)]
final class SqliteTenantSchemaIsolationTest extends TenantSchemaIsolationTestCase
{
    /**
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    #[\Override]
    protected function engine(): string
    {
        return 'sqlite';
    }
}
