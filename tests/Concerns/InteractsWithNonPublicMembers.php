<?php

namespace Tests\Concerns;

/**
 * Provides reflection helpers for accessing non-public class members in tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait InteractsWithNonPublicMembers
{
    /**
     * Invoke a non-public method on the given object.
     *
     * @param  object  $object
     * @param  string  $method
     * @param  mixed  ...$args
     * @return mixed
     */
    protected function invokeMethod(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);

        return $reflection->invoke($object, ...$args);
    }

    /**
     * Invoke a non-public static method on the given class.
     *
     * @param  string  $class
     * @param  string  $method
     * @param  mixed  ...$args
     * @return mixed
     */
    protected function invokeStaticMethod(string $class, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($class, $method);

        return $reflection->invoke(null, ...$args);
    }

    /**
     * Get a non-public property value from the given object.
     *
     * @param  object  $object
     * @param  string  $property
     * @return mixed
     */
    protected function getProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);

        return $reflection->getValue($object);
    }

    /**
     * Set a non-public property value on the given object.
     *
     * @param  object  $object
     * @param  string  $property
     * @param  mixed  $value
     * @return void
     */
    protected function setProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);

        $reflection->setValue($object, $value);
    }

    /**
     * Set a non-public static property value on the given class.
     *
     * @param  string  $class
     * @param  string  $property
     * @param  mixed  $value
     * @return void
     */
    protected function setStaticProperty(string $class, string $property, mixed $value): void
    {
        $reflection = new \ReflectionClass($class);
        $prop       = $reflection->getProperty($property);

        $prop->setValue(null, $value);
    }

    /**
     * Get a non-public static property value from the given class.
     *
     * @param  string  $class
     * @param  string  $property
     * @return mixed
     */
    protected function getStaticProperty(string $class, string $property): mixed
    {
        $reflection = new \ReflectionClass($class);
        $prop       = $reflection->getProperty($property);

        return $prop->getValue(null);
    }
}
