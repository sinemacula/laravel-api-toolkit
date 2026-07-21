<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\Concerns\DerivedTabularSchema;
use SineMacula\Exporter\Schema\Column;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\TestCase;

/**
 * Tests for the DerivedTabularSchema column carrier.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DerivedTabularSchema::class)]
final class DerivedTabularSchemaTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that the constructor forwards the request to the parent schema, so
     * the schema carries the exact request it was built for.
     *
     * @return void
     */
    public function testConstructorForwardsRequestToParentSchema(): void
    {
        $request = Request::create('/');

        $schema = new DerivedTabularSchema($request, []);

        self::assertSame($request, $this->getProperty($schema, 'request'));
    }

    /**
     * Test that the schema returns the ordered columns it was built with.
     *
     * @return void
     */
    public function testColumnsReturnsTheProvidedColumnsInOrder(): void
    {
        $columns = [Column::make('id'), Column::make('name')];

        $schema = new DerivedTabularSchema(Request::create('/'), $columns);

        self::assertSame($columns, $schema->columns());
    }

    /**
     * Test that an empty column list is returned unchanged.
     *
     * @return void
     */
    public function testColumnsReturnsEmptyListWhenNoColumnsProvided(): void
    {
        $schema = new DerivedTabularSchema(Request::create('/'), []);

        self::assertSame([], $schema->columns());
    }
}
