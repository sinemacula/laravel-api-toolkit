<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Pagination;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Repositories\Pagination\RawCursorPaginator;
use Tests\Fixtures\Casts\PostalAddress;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\Event;
use Tests\TestCase;

/**
 * Tests for the RawCursorPaginator class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RawCursorPaginator::class)]
final class RawCursorPaginatorTest extends TestCase
{
    /** @var array<string, mixed> The options the paginator reads its ordered columns from */
    private const array OPTIONS = ['path' => '/events', 'parameters' => ['events.value', 'events.id']];

    /**
     * Test that a page taken over keeps its items, size, cursor, options and
     * whether more rows follow.
     *
     * @return void
     */
    public function testTakenOverPageKeepsItsState(): void
    {
        $cursor = new Cursor(['events.id' => 1]);
        $raw    = RawCursorPaginator::of(new CursorPaginator(['a', 'b', 'c'], 2, $cursor, self::OPTIONS));

        self::assertSame(['a', 'b'], $raw->items());
        self::assertSame(2, $raw->perPage());
        self::assertSame($cursor, $raw->cursor());
        self::assertSame(self::OPTIONS, $raw->getOptions());
        self::assertTrue($raw->hasMorePages());
    }

    /**
     * Test that a backward page taken over keeps its order and stays on the
     * first page when nothing precedes it.
     *
     * @return void
     */
    public function testTakenOverBackwardPageKeepsItsOrder(): void
    {
        $raw = RawCursorPaginator::of(new CursorPaginator(['b', 'a'], 2, new Cursor(['events.id' => 3], false), self::OPTIONS));

        self::assertSame(['a', 'b'], $raw->items());
        self::assertTrue($raw->onFirstPage());
    }

    /**
     * Provide casts whose value is not the value the row stores.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function castProvider(): iterable
    {
        yield 'microsecond date-time' => ['datetime', '2026-01-01 10:00:00.250000'];
        yield 'pure enum' => [Platform::class, 'MOBILE_APP'];
        yield 'backed enum' => [UserStatus::class, 'active'];
        yield 'decimal' => ['decimal:2', '12.5'];
        yield 'castable value object' => [PostalAddress::class, '1 Main Street'];
    }

    /**
     * Test that a cursor carries a cast column as the row stores it.
     *
     * @param  string  $cast
     * @param  string  $stored
     * @return void
     *
     * @throws \Exception
     */
    #[DataProvider('castProvider')]
    public function testCursorCarriesTheStoredValueOfACastColumn(string $cast, string $stored): void
    {
        $event = $this->event($cast, ['id' => 7, 'value' => $stored]);

        self::assertSame(['events.value' => $stored, 'events.id' => 7], $this->paginator()->getParametersForItem($event));
    }

    /**
     * Test that the model behind a resource is read.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function testModelBehindAResourceIsRead(): void
    {
        $event = $this->event('datetime', ['id' => 7, 'value' => '2026-01-01 10:00:00.250000']);

        self::assertSame(['events.value' => '2026-01-01 10:00:00.250000', 'events.id' => 7], $this->paginator()->getParametersForItem(new JsonResource($event)));
    }

    /**
     * Test that an attribute named for the qualified column is read before the
     * column's own, as the framework reads it.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function testAttributeNamedForTheQualifiedColumnIsReadFirst(): void
    {
        $event = $this->event('string', ['id' => 7, 'value' => 'own', 'events.value' => 'aliased']);

        self::assertSame(['events.value' => 'aliased', 'events.id' => 7], $this->paginator()->getParametersForItem($event));
    }

    /**
     * Test that a column the row does not hold is carried as null and an empty
     * parameter is skipped.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function testMissingColumnIsNullAndEmptyParameterSkipped(): void
    {
        $paginator = new RawCursorPaginator([], 2, null, ['parameters' => ['events.value', null, 'events.id']]);

        self::assertSame(['events.value' => null, 'events.id' => 7], $paginator->getParametersForItem($this->event('string', ['id' => 7])));
    }

    /**
     * Test that an item that is not a model is read as the framework reads it.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function testItemThatIsNotAModelIsReadAsTheFrameworkReadsIt(): void
    {
        self::assertSame(['events.value' => 'x', 'events.id' => 7], $this->paginator()->getParametersForItem((object) ['value' => 'x', 'id' => 7]));
    }

    /**
     * Build an empty paginator ordered by the value then the key.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Pagination\RawCursorPaginator<int, mixed>
     */
    private function paginator(): RawCursorPaginator
    {
        return new RawCursorPaginator([], 2, null, self::OPTIONS);
    }

    /**
     * Build an event read from the given stored attributes, its value cast as
     * given.
     *
     * @param  string  $cast
     * @param  array<string, mixed>  $stored
     * @return \Tests\Fixtures\Models\Event
     */
    private function event(string $cast, array $stored): Event
    {
        return (new Event)->mergeCasts(['value' => $cast])->setRawAttributes($stored, true);
    }
}
