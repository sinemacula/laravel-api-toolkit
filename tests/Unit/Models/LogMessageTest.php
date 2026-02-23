<?php

namespace Tests\Unit\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
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
        $this->app['config']->set('logging.channels.database.days', 30);

        $model = new LogMessage;
        $query = $model->prunable();

        $sql = $query->toRawSql();

        static::assertStringContainsString('created_at', $sql);
        static::assertStringContainsString('<=', $sql);
    }

    /**
     * Test that a model can be created with valid attributes.
     *
     * @return void
     */
    public function testModelCanBeCreatedWithValidAttributes(): void
    {
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
