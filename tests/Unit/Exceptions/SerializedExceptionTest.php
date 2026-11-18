<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\SerializedException;

/**
 * Tests for the stand-in of an exception that crosses serialisation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SerializedException::class)]
final class SerializedExceptionTest extends TestCase
{
    /**
     * Test that the stand-in keeps the class, message, code, file, line and
     * previous chain of the exception.
     *
     * @return void
     */
    public function testKeepsTheDetailsOfTheException(): void
    {
        $previous  = new \LogicException('inner');
        $exception = new \RuntimeException('outer', 7, $previous);

        $this->assertStandsFor($exception, SerializedException::from($exception));
    }

    /**
     * Test that the stand-in keeps the string SQLSTATE code a PDO exception
     * carries, before and after serialisation.
     *
     * @return void
     */
    public function testKeepsTheStringCodeOfAPdoException(): void
    {
        $pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        try {
            $pdo->query('select * from missing');
        } catch (\PDOException $exception) {
            $standIn  = SerializedException::from($exception);
            $restored = unserialize(serialize($standIn));

            $this->assertStandsFor($exception, $standIn);
            self::assertInstanceOf(SerializedException::class, $restored);
            $this->assertStandsFor($exception, $restored);

            return;
        }

        self::fail('The query against a missing table did not throw.');
    }

    /**
     * Test that a stand-in round-trips through serialisation when the exception
     * it stands for cannot be serialised.
     *
     * @return void
     */
    public function testRoundTripsWhereTheExceptionCannotBeSerialised(): void
    {
        $exception = new class ('unserialisable', 3, new \LogicException('inner')) extends \RuntimeException {};

        $restored = unserialize(serialize(SerializedException::from($exception)));

        self::assertInstanceOf(SerializedException::class, $restored);
        $this->assertStandsFor($exception, $restored);
    }

    /**
     * Test that a stand-in is returned as it is rather than wrapped again.
     *
     * @return void
     */
    public function testFromReturnsAStandInUnchanged(): void
    {
        $standIn = SerializedException::from(new \RuntimeException('boom'));

        self::assertSame($standIn, SerializedException::from($standIn));
    }

    /**
     * Assert that the stand-in carries the details of the exception and of each
     * exception before it.
     *
     * @param  \Throwable  $exception
     * @param  \SineMacula\ApiToolkit\Exceptions\SerializedException  $standIn
     * @return void
     */
    private function assertStandsFor(\Throwable $exception, SerializedException $standIn): void
    {
        self::assertSame($exception::class, $standIn->originalClass());
        self::assertSame($exception->getMessage(), $standIn->getMessage());
        self::assertSame($exception->getCode(), $standIn->getCode());
        self::assertSame($exception->getFile(), $standIn->getFile());
        self::assertSame($exception->getLine(), $standIn->getLine());

        $previous = $exception->getPrevious();

        if ($previous === null) {
            self::assertNull($standIn->getPrevious());

            return;
        }

        $standInPrevious = $standIn->getPrevious();

        self::assertInstanceOf(SerializedException::class, $standInPrevious);
        $this->assertStandsFor($previous, $standInPrevious);
    }
}
