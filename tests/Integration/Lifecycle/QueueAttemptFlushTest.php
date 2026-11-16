<?php

declare(strict_types = 1);

namespace Tests\Integration\Lifecycle;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Cache\MetadataGeneration;
use SineMacula\ApiToolkit\Events\CacheFlushed;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
use Tests\Concerns\UsesDatabaseQueue;
use Tests\Fixtures\Jobs\GenerationRecordingJob;
use Tests\Fixtures\Jobs\ParsingJob;
use Tests\TestCase;

/**
 * Integration tests that a real queue worker resets the toolkit after every job
 * attempt.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueueFlushSubscriber::class)]
final class QueueAttemptFlushTest extends TestCase
{
    use UsesDatabaseQueue;

    /** @var int The number of flushes dispatched so far */
    private int $flushes = 0;

    /**
     * Set up a private database queue and count the flushes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        GenerationRecordingJob::reset();
        ParsingJob::$parser = null;

        $this->useDatabaseQueue();

        Event::listen(CacheFlushed::class, function (): void {
            $this->flushes++;
        });
    }

    /**
     * Test that a job released for a retry re-reads the generation on its next
     * attempt.
     *
     * @return void
     */
    public function testReleasedJobRereadsTheGenerationOnItsNextAttempt(): void
    {
        Queue::push(new GenerationRecordingJob);

        $this->workOnce();

        $elsewhere = (new MetadataGeneration)->advance();

        $this->workOnce();

        self::assertCount(2, GenerationRecordingJob::$generations);
        self::assertNotSame($elsewhere, GenerationRecordingJob::$generations[0]);
        self::assertSame($elsewhere, GenerationRecordingJob::$generations[1]);
        self::assertSame(2, $this->flushes);
        self::assertSame(0, DB::connection('queue_probe')->table('jobs')->count());
    }

    /**
     * Provide the attempt endings that finish a job for good.
     *
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function finalAttemptProvider(): iterable
    {
        yield 'deleted job' => [ParsingJob::DELETE, false];
        yield 'job throwing on its last attempt' => [ParsingJob::THROW, true];
        yield 'job failing itself' => [ParsingJob::FAIL, true];
    }

    /**
     * Test that a final attempt flushes once, after the failure listeners saw
     * the job's parser state.
     *
     * @param  string  $ending
     * @param  bool  $fails
     * @return void
     */
    #[DataProvider('finalAttemptProvider')]
    public function testFinalAttemptFlushesOnceAfterTheFailureListeners(string $ending, bool $fails): void
    {
        $seen = [];

        Queue::failing(function (JobFailed $event) use (&$seen): void {
            $seen[] = [ParsingJob::$parser?->getFields(), $this->flushes];
        });

        Queue::push(new ParsingJob($ending));

        $this->workOnce(1);

        self::assertSame($fails ? [[['name', 'email'], 0]] : [], $seen);
        self::assertSame(1, $this->flushes);
        self::assertNotNull(ParsingJob::$parser);
        self::assertNull(ParsingJob::$parser->getFields());
        self::assertSame($fails ? 1 : 0, DB::connection('queue_probe')->table('failed_jobs')->count());
    }

    /**
     * Test that a job already over its max attempts when reserved, as after a
     * worker crash, flushes once after the failure listeners ran.
     *
     * @return void
     */
    public function testJobAlreadyOverItsMaxAttemptsFlushesOnceAfterTheFailureListeners(): void
    {
        $seen = [];

        Queue::failing(function () use (&$seen): void {
            $seen[] = $this->flushes;
        });

        Queue::push(new ParsingJob(ParsingJob::THROW));

        DB::connection('queue_probe')->table('jobs')->update(['attempts' => 1]);

        $this->workOnce(1);

        self::assertSame([0], $seen);
        self::assertSame(1, $this->flushes);
        self::assertNull(ParsingJob::$parser);
        self::assertSame(1, DB::connection('queue_probe')->table('failed_jobs')->count());
    }
}
