<?php

declare(strict_types = 1);

namespace Tests\Unit\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Query\ApiQuerySnapshot;

/**
 * Tests for the ApiQuerySnapshot value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiQuerySnapshot::class)]
final class ApiQuerySnapshotTest extends TestCase
{
    /** @var array<string, mixed> A selection naming every key a snapshot carries */
    private const array SELECTION = [
        'fields'     => ['users' => 'name,email'],
        'aggregates' => ['users' => 'posts_count'],
        'filters'    => '{"status":{"$eq":"paid"}}',
        'search'     => 'john',
        'order'      => 'name:desc',
        'trashed'    => 'with',
    ];

    /**
     * Test that a snapshot holds every carried key as given.
     *
     * @return void
     */
    public function testSnapshotHoldsEveryCarriedKey(): void
    {
        self::assertSame(self::SELECTION, (new ApiQuerySnapshot(self::SELECTION))->parameters);
    }

    /**
     * Provide parameters a snapshot never carries, with the refusal each
     * raises.
     *
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function uncarriedParameterProvider(): iterable
    {
        yield 'an unknown key' => [['unknown' => 'x'], 'An API query snapshot cannot carry "unknown".'];
        yield 'the page size' => [['limit' => '20'], 'An API query snapshot cannot carry "limit".'];
        yield 'the page, cursor and mode' => [
            ['page' => '2', 'fields' => [], 'cursor' => 'x', 'pagination' => 'cursor'],
            'An API query snapshot cannot carry "page", "cursor", "pagination".',
        ];
    }

    /**
     * Test that a snapshot refuses any parameter outside the carried keys.
     *
     * @param  array<string, mixed>  $parameters
     * @param  string  $message
     * @return void
     */
    #[DataProvider('uncarriedParameterProvider')]
    public function testSnapshotRefusesAnUncarriedParameter(array $parameters, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ApiQuerySnapshot($parameters);
    }

    /**
     * Test that without() returns a copy lacking the given keys and leaves the
     * original whole.
     *
     * @return void
     */
    public function testWithoutReturnsACopyLackingTheGivenKeys(): void
    {
        $snapshot = new ApiQuerySnapshot(self::SELECTION);
        $narrowed = $snapshot->without('order', 'fields');

        self::assertNotSame($snapshot, $narrowed);
        self::assertSame(['aggregates', 'filters', 'search', 'trashed'], array_keys($narrowed->parameters));
        self::assertSame(self::SELECTION, $snapshot->parameters);
    }

    /**
     * Test that a snapshot survives PHP serialisation and its parameters a JSON
     * round trip.
     *
     * @return void
     *
     * @throws \JsonException
     */
    public function testSnapshotSurvivesSerialisation(): void
    {
        $snapshot = new ApiQuerySnapshot(self::SELECTION);

        $restored = unserialize(serialize($snapshot));

        /** @var array<string, mixed> $stored */
        $stored = json_decode(json_encode($snapshot->parameters, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        self::assertInstanceOf(ApiQuerySnapshot::class, $restored);
        self::assertSame(self::SELECTION, $restored->parameters);
        self::assertSame(self::SELECTION, (new ApiQuerySnapshot($stored))->parameters);
    }
}
