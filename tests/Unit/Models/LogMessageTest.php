<?php

namespace Tests\Unit\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Models\LogMessage;
use Tests\TestCase;

/**
 * Tests for the LogMessage model.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LogMessage::class)]
class LogMessageTest extends TestCase
{
    /**
     * Test that the model uses the 'logs' table.
     *
     * @return void
     */
    public function testModelUsesLogsTable(): void
    {
        $model = new LogMessage;

        static::assertSame('logs', $model->getTable());
    }

    /**
     * Test that the model uses HasUuids trait.
     *
     * @return void
     */
    public function testModelUsesHasUuidsTrait(): void
    {
        $traits = class_uses_recursive(LogMessage::class);

        static::assertArrayHasKey(HasUuids::class, $traits);
    }

    /**
     * Test that the model uses MassPrunable trait.
     *
     * @return void
     */
    public function testModelUsesMassPrunableTrait(): void
    {
        $traits = class_uses_recursive(LogMessage::class);

        static::assertArrayHasKey(MassPrunable::class, $traits);
    }

    /**
     * Test that timestamps are disabled.
     *
     * @return void
     */
    public function testTimestampsAreDisabled(): void
    {
        $model = new LogMessage;

        static::assertFalse($model->usesTimestamps());
    }

    /**
     * Test that fillable fields include level, message, context, and created_at.
     *
     * @return void
     */
    public function testFillableFieldsAreCorrect(): void
    {
        $model = new LogMessage;

        static::assertSame(['level', 'message', 'context', 'created_at'], $model->getFillable());
    }

    /**
     * Test that casts are correctly defined.
     *
     * @return void
     */
    public function testCastsAreCorrectlyDefined(): void
    {
        $model = new LogMessage;

        $casts = $model->getCasts();

        static::assertSame('string', $casts['level']);
        static::assertSame('string', $casts['message']);
        static::assertSame(AsArrayObject::class, $casts['context']);
        static::assertSame('immutable_datetime', $casts['created_at']);
    }

    /**
     * Test that prunable returns a query scoped to configured days.
     *
     * @return void
     */
    public function testPrunableReturnsScopedQuery(): void
    {
        Config::set('logging.channels.database.days', 30);

        $model = new LogMessage;
        $query = $model->prunable();

        /** @phpstan-ignore staticMethod.dynamicCall */
        $sql = $query->toRawSql();

        static::assertStringContainsString('created_at', $sql);
        static::assertStringContainsString('<=', $sql);
    }

    /**
     * Test that prunable cuts off at exactly the configured number of days.
     *
     * @return void
     */
    public function testPrunableCutsOffAtConfiguredDays(): void
    {
        Carbon::setTestNow('2026-01-15 12:00:00');

        try {

            Config::set('logging.channels.database.days', 30);

            /** @phpstan-ignore staticMethod.dynamicCall */
            $binding = (new LogMessage)->prunable()->getBindings()[0];

            static::assertInstanceOf(\DateTimeInterface::class, $binding);
            static::assertSame(
                Carbon::now()->subDays(30)->format('Y-m-d H:i:s.u'),
                $binding->format('Y-m-d H:i:s.u'),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Test that prunable truncates a fractional days value to whole days.
     *
     * @return void
     */
    public function testPrunableTruncatesFractionalDays(): void
    {
        Carbon::setTestNow('2026-01-15 12:00:00');

        try {

            Config::set('logging.channels.database.days', 30.75);

            /** @phpstan-ignore staticMethod.dynamicCall */
            $binding = (new LogMessage)->prunable()->getBindings()[0];

            static::assertInstanceOf(\DateTimeInterface::class, $binding);
            static::assertSame(
                Carbon::now()->subDays(30)->format('Y-m-d H:i:s.u'),
                $binding->format('Y-m-d H:i:s.u'),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Test that prunable cuts off at the current time when the days value
     * is not numeric.
     *
     * @return void
     */
    public function testPrunableCutsOffAtNowWhenDaysIsNotNumeric(): void
    {
        Carbon::setTestNow('2026-01-15 12:00:00');

        try {

            Config::set('logging.channels.database.days', 'not-a-number');

            /** @phpstan-ignore staticMethod.dynamicCall */
            $binding = (new LogMessage)->prunable()->getBindings()[0];

            static::assertInstanceOf(\DateTimeInterface::class, $binding);
            static::assertSame(
                Carbon::now()->format('Y-m-d H:i:s.u'),
                $binding->format('Y-m-d H:i:s.u'),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Test that a model can be created with valid attributes.
     *
     * @return void
     */
    public function testModelCanBeCreatedWithValidAttributes(): void
    {
        /** @phpstan-ignore staticMethod.notFound */
        $log = LogMessage::create([
            'level'      => 'INFO',
            'message'    => 'Test message',
            'context'    => json_encode(['key' => 'value']),
            'created_at' => now(),
        ]);

        static::assertNotNull($log->id);
        $this->assertDatabaseHas('logs', [
            'level'   => 'INFO',
            'message' => 'Test message',
        ]);
    }
}
