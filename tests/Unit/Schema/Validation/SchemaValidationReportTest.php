<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationReport;
use SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;

/**
 * Tests for the SchemaValidationReport value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SchemaValidationReport::class)]
final class SchemaValidationReportTest extends TestCase
{
    /**
     * Test that the constructor keeps the defects, the unverified declarations
     * and the resource count apart.
     *
     * @return void
     */
    public function testConstructorSetsProperties(): void
    {
        $error       = new SchemaValidationError(UserResource::class, 'id', 'A defect');
        $declaration = new UnverifiedDeclaration(UserResource::class, 'name', 'a claim', User::class);

        $report = new SchemaValidationReport([$error], [$declaration], 3);

        self::assertSame([$error], $report->errors);
        self::assertSame([$declaration], $report->unverified);
        self::assertSame(3, $report->resourceCount);
    }
}
