<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use SineMacula\ApiToolkit\Cache\MetadataGeneration;
use Tests\Fixtures\Exceptions\JobAttemptException;

/**
 * Fixture job that records the metadata generation on every attempt and fails
 * its first.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class GenerationRecordingJob implements ShouldQueue
{
    /** @var array<int, string> The generation each attempt read */
    public static array $generations = [];

    /**
     * Reset the recorded generations between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$generations = [];
    }

    /**
     * Record the generation, failing the first attempt.
     *
     * @param  \SineMacula\ApiToolkit\Cache\MetadataGeneration  $generation
     * @return void
     *
     * @throws \Tests\Fixtures\Exceptions\JobAttemptException
     */
    public function handle(MetadataGeneration $generation): void
    {
        self::$generations[] = $generation->current();

        if (count(self::$generations) === 1) {
            throw new JobAttemptException('The first attempt failed.');
        }
    }
}
