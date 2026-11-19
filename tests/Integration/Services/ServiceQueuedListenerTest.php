<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\SerializedException;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\Events\ServiceCompleted;
use SineMacula\ApiToolkit\Services\Events\ServiceEvent;
use SineMacula\ApiToolkit\Services\Events\ServiceFailed;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\ApiToolkit\Services\ServiceResult;
use Tests\Concerns\UsesDatabaseQueue;
use Tests\Fixtures\Exceptions\ServiceExecutionException;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Services\CreatingUserService;
use Tests\Fixtures\Services\FailingService;
use Tests\Fixtures\Services\Listeners\QueuedServiceEventRecorder;
use Tests\Fixtures\Services\StubActor;
use Tests\Fixtures\Services\ValidatingUserService;
use Tests\TestCase;

/**
 * Integration tests for queued listeners of the service events, handled by a
 * real worker.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceResult::class)]
#[CoversClass(SerializedException::class)]
final class ServiceQueuedListenerTest extends TestCase
{
    use UsesDatabaseQueue;

    /** @var string The correlation id every run carries */
    private const string CORRELATION_ID = 'queued-listener';

    /**
     * Give the queue a private connection and forget recorded events.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->useDatabaseQueue();

        QueuedServiceEventRecorder::$events = [];
    }

    /**
     * Provide a refusal from validate() and an exception from handle().
     *
     * @return iterable<string, array{class-string, array<string, mixed>, class-string<\Throwable>, string}>
     */
    public static function failures(): iterable
    {
        yield 'validation failure' => [
            ValidatingUserService::class,
            ['age' => 30],
            ValidationException::class,
            'The city field is required.',
        ];

        yield 'handle exception' => [
            FailingService::class,
            ['reference' => 'abc'],
            ServiceExecutionException::class,
            'Service execution failed',
        ];
    }

    /**
     * Test that a queued ServiceFailed listener receives the failure on the
     * worker as a SerializedException, while run() returns the original.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Services\Service<covariant \SineMacula\ApiToolkit\Services\Contracts\ServiceInput, covariant mixed>>  $service
     * @param  array<string, mixed>  $input
     * @param  class-string<\Throwable>  $class
     * @param  string  $message
     * @return void
     */
    #[DataProvider('failures')]
    public function testQueuedFailedListenerReceivesTheFailureOnTheWorker(string $service, array $input, string $class, string $message): void
    {
        Event::listen(ServiceFailed::class, QueuedServiceEventRecorder::class);

        $result = $this->runInContext($service::make(new ArrayInput($input)));

        self::assertTrue($result->failed());
        self::assertInstanceOf($class, $result->exception);

        $event     = $this->handledOnTheWorker(ServiceFailed::class, $service, $input);
        $exception = $event->result->exception;

        self::assertTrue($event->result->failed());
        self::assertInstanceOf(SerializedException::class, $exception);
        self::assertSame($class, $exception->originalClass());
        self::assertSame($message, $exception->getMessage());
    }

    /**
     * Test that a queued ServiceCompleted listener receives the model the
     * service returned.
     *
     * @return void
     */
    public function testQueuedCompletedListenerReceivesTheModelOutput(): void
    {
        Event::listen(ServiceCompleted::class, QueuedServiceEventRecorder::class);

        $input  = ['name' => 'Queued', 'email' => 'queued@listener.test'];
        $result = $this->runInContext(CreatingUserService::make(new ArrayInput($input)));

        $created = $result->output();

        self::assertInstanceOf(User::class, $created);

        $output = $this->handledOnTheWorker(ServiceCompleted::class, CreatingUserService::class, $input)->result->output();

        self::assertInstanceOf(User::class, $output);
        self::assertSame($created->getKey(), $output->getKey());
        self::assertSame('queued@listener.test', $output->email);
    }

    /**
     * Test that runOrFail() throws the original ValidationException while a
     * queued ServiceFailed listener is registered.
     *
     * @return void
     */
    public function testRunOrFailThrowsTheOriginalFailureBesideAQueuedListener(): void
    {
        Event::listen(ServiceFailed::class, QueuedServiceEventRecorder::class);

        $this->assertThrows(
            static fn () => ValidatingUserService::make(new ArrayInput(['age' => 30]))->runOrFail(),
            ValidationException::class,
            'The city field is required.',
        );

        self::assertSame(1, DB::connection('queue_probe')->table('jobs')->count());
    }

    /**
     * Run the service with the shared correlation id.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service<covariant \SineMacula\ApiToolkit\Services\Contracts\ServiceInput, covariant mixed>  $service
     * @return \SineMacula\ApiToolkit\Services\ServiceResult<mixed>
     */
    private function runInContext(Service $service): ServiceResult
    {
        return $service->withContext(new ServiceContext(new StubActor, self::CORRELATION_ID, ServiceSource::INTERNAL))->run();
    }

    /**
     * Work the single queued listener job and return the event it handled,
     * asserting the details that crossed the queue.
     *
     * @template TEvent of \SineMacula\ApiToolkit\Services\Events\ServiceEvent
     *
     * @param  class-string<TEvent>  $event
     * @param  class-string  $service
     * @param  array<string, mixed>  $input
     * @return TEvent
     */
    private function handledOnTheWorker(string $event, string $service, array $input): ServiceEvent
    {
        self::assertSame(1, DB::connection('queue_probe')->table('jobs')->count());

        $this->workOnce();

        self::assertSame(0, DB::connection('queue_probe')->table('jobs')->count());
        self::assertSame(0, DB::connection('queue_probe')->table('failed_jobs')->count());
        self::assertCount(1, QueuedServiceEventRecorder::$events);

        $handled = QueuedServiceEventRecorder::$events[0];

        self::assertInstanceOf($event, $handled);
        self::assertSame('stub-id', $handled->actor->actorIdentifier());
        self::assertSame($service, $handled->service);
        self::assertSame(self::CORRELATION_ID, $handled->correlationId);
        self::assertSame($input, $handled->inputSummary);

        return $handled;
    }
}
