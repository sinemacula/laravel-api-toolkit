<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

/**
 * Stands in for a captured exception that crosses a serialisation boundary.
 *
 * Keeps the class, message, code, file, line and previous chain as data, since
 * an exception's trace arguments and state, such as a validator, may not
 * serialise.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SerializedException extends \RuntimeException
{
    /** @var class-string<\Throwable> The class of the exception it stands for */
    private string $originalClass;

    /**
     * Constructor.
     *
     * @param  \Throwable  $exception
     */
    private function __construct(\Throwable $exception)
    {
        $previous = $exception->getPrevious();

        parent::__construct($exception->getMessage(), previous: $previous === null ? null : self::from($previous));

        $this->originalClass = $exception::class;
        $this->code          = $exception->getCode();
        $this->file          = $exception->getFile();
        $this->line          = $exception->getLine();
    }

    /**
     * Serialise the exception's data, leaving out its trace.
     *
     * @return array{class: class-string<\Throwable>, message: string, code: int|string, file: string, line: int, previous: \Throwable|null}
     */
    public function __serialize(): array
    {
        return [
            'class'    => $this->originalClass,
            'message'  => $this->message,
            'code'     => $this->code,
            'file'     => $this->file,
            'line'     => $this->line,
            'previous' => $this->getPrevious(),
        ];
    }

    /**
     * Restore the exception's data.
     *
     * @param  array{class: class-string<\Throwable>, message: string, code: int|string, file: string, line: int, previous: \Throwable|null}  $data
     * @return void
     */
    public function __unserialize(array $data): void
    {
        parent::__construct($data['message'], previous: $data['previous']); // @phpstan-ignore constructor.call

        $this->originalClass = $data['class'];
        $this->code          = $data['code'];
        $this->file          = $data['file'];
        $this->line          = $data['line'];
    }

    /**
     * Create the stand-in for the given exception, or return it when it is one
     * already.
     *
     * @param  \Throwable  $exception
     * @return self
     */
    public static function from(\Throwable $exception): self
    {
        return $exception instanceof self ? $exception : new self($exception);
    }

    /**
     * Return the class of the exception it stands for.
     *
     * @return class-string<\Throwable>
     */
    public function originalClass(): string
    {
        return $this->originalClass;
    }
}
