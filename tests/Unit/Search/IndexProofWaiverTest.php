<?php

declare(strict_types = 1);

namespace Tests\Unit\Search;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Search\IndexProofWaiver;
use Tests\TestCase;

/**
 * Tests for the IndexProofWaiver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(IndexProofWaiver::class)]
final class IndexProofWaiverTest extends TestCase
{
    /**
     * Test that the shipped configuration waives the proof on the development
     * connection and on nothing else.
     *
     * @return void
     */
    public function testWaivesTheProofOnTheShippedDevelopmentConnectionOnly(): void
    {
        self::assertTrue(IndexProofWaiver::waives('sqlite'));
        self::assertFalse(IndexProofWaiver::waives('mysql'));
        self::assertFalse(IndexProofWaiver::waives('pgsql'));
    }

    /**
     * Test that a connection named in the configured list waives the proof.
     *
     * @return void
     */
    public function testWaivesTheProofOnAConfiguredConnection(): void
    {
        Config::set('api-toolkit.search.unverified_connections', ['sqlite', 'mysql']);

        self::assertTrue(IndexProofWaiver::waives('mysql'));
    }

    /**
     * Test that an empty list waives nothing, so removing the shipped entry
     * refuses an unprovable declaration everywhere.
     *
     * @return void
     */
    public function testWaivesNothingWhenTheListIsEmpty(): void
    {
        Config::set('api-toolkit.search.unverified_connections', []);

        self::assertFalse(IndexProofWaiver::waives('sqlite'));
    }

    /**
     * Test that a configured value that is not a list waives nothing rather
     * than being read as one entry.
     *
     * @return void
     */
    public function testWaivesNothingWhenTheConfiguredValueIsNotAList(): void
    {
        Config::set('api-toolkit.search.unverified_connections', 'sqlite');

        self::assertFalse(IndexProofWaiver::waives('sqlite'));
    }
}
