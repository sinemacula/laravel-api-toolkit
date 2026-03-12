# Upgrade Guide

## From 1.x to 2.x

### Removed: Mutable service configuration methods

The following methods have been removed from the `Service` base class:

- `useTransaction()`
- `dontUseTransaction()`
- `useLock()`
- `dontUseLock()`

**Before (1.x):**

Callers configured service behavior at runtime using fluent methods:

    $service = new MyService($payload);
    $service->dontUseTransaction();
    $service->useLock();
    $service->run();

**After (2.x):**

Configuration is declared at the class level via method overrides:

    class MyService extends Service
    {
        protected function shouldUseTransaction(): bool
        {
            return false;
        }

        protected function shouldUseLock(): bool
        {
            return true;
        }

        protected function handle(): bool
        {
            // ...
        }

        protected function getLockId(): string
        {
            return 'my-service-lock-id';
        }
    }

The caller no longer needs to configure the service externally:

    $service = new MyService($payload);
    $service->run();

### Immutable configuration properties

The `$useTransaction` and `$useLock` properties on the `Service`
base class are now `protected readonly bool`. They are initialized
during construction via the `shouldUseTransaction()` and
`shouldUseLock()` methods. This prevents any post-construction
mutation of service configuration.

Subclasses that previously redeclared these properties with
different default values must now override the corresponding
method instead. Code that attempts to reassign these properties
after construction will produce a PHP error.

**Before (property override -- this no longer works):**

    class MyService extends Service
    {
        protected bool $useTransaction = false;

        protected function handle(): bool
        {
            // ...
        }
    }

**After (method override):**

    class MyService extends Service
    {
        protected function shouldUseTransaction(): bool
        {
            return false;
        }

        protected function handle(): bool
        {
            // ...
        }
    }

### Lock key generation via LockKeyProvider contract

The `Lockable` trait no longer declares
`abstract generateLockKey()`. The `Service` class now implements
`LockKeyProvider` with a `getLockKey()` method that contains the
same logic.

**Impact on Service subclasses:** Subclasses that previously
overrode `generateLockKey()` must now override `getLockKey()`
instead. The method visibility changes from `protected` to
`public`.

**Before:**

    class MyService extends Service
    {
        protected function generateLockKey(): string
        {
            return sha1('custom-key');
        }
    }

**After:**

    class MyService extends Service
    {
        public function getLockKey(): string
        {
            return sha1('custom-key');
        }
    }

**Impact on standalone Lockable consumers:** Classes using
`Lockable` without extending `Service` should implement
`LockKeyProvider` and provide a `getLockKey()` method instead of
overriding `generateLockKey()`.

**Before:**

    class MyJob
    {
        use Lockable;

        protected function generateLockKey(): string
        {
            return sha1('job-lock');
        }
    }

**After:**

    use SineMacula\ApiToolkit\Contracts\LockKeyProvider;

    class MyJob implements LockKeyProvider
    {
        use Lockable;

        public function getLockKey(): string
        {
            return sha1('job-lock');
        }
    }

### Removed: ServiceLockException

The `ServiceLockException` class has been removed. It was never
thrown by the framework -- lock acquisition failures use
`TooManyRequestsException`.

Any code that catches `ServiceLockException` should be updated
to catch `TooManyRequestsException` instead (or removed if the
catch block was unreachable).

### Default behavior

A service subclass with no method overrides behaves identically to the previous default:

- **Transactions:** enabled (`shouldUseTransaction()` returns `true`)
- **Locking:** disabled (`shouldUseLock()` returns `false`)

### Removed: Generic payload constructor parameter

The `$payload` constructor parameter (typed as
`array|Collection|\stdClass`) and its Collection normalisation logic
have been removed from the `Service` base class. The constructor now
accepts no arguments. Subclasses define their own typed inputs using
standard PHP 8.3+ features.

**Before:**

Services inherited a generic `$payload` parameter and accessed
inputs without type safety:

    class CreateUserService extends Service
    {
        protected function handle(): bool
        {
            $name  = $this->payload->get('name');
            $email = $this->payload->get('email');

            // $name and $email are both `mixed` — invisible to PHPStan
            return true;
        }
    }

    $service = new CreateUserService(['name' => 'Jane', 'email' => 'jane@example.com']);
    $service->run();

**After:**

Subclasses define their own typed constructors:

    class CreateUserService extends Service
    {
        public function __construct(
            private readonly string $name,
            private readonly string $email,
        ) {
            parent::__construct();
        }

        protected function handle(): bool
        {
            // $this->name and $this->email are typed — PHPStan verifies usage
            return true;
        }
    }

    $service = new CreateUserService(name: 'Jane', email: 'jane@example.com');
    $service->run();

Constructor promotion is not the only approach. Subclasses can also
accept a DTO, a value object, or any other typed structure as their
input. The toolkit enables typed inputs by removing the obstacle --
it does not prescribe a replacement pattern.

**Incremental migration:**

Services that have no explicit constructor and relied on the default
`$payload = []` continue to work unchanged -- PHP calls the
parameterless parent constructor automatically. Services that
explicitly called `parent::__construct($payload)` must update that
call to `parent::__construct()` and define their own input
properties. Services can be migrated one at a time because each
subclass owns its own constructor, so new-style and old-style
services coexist without conflict.

**PHPStan verification:**

Run `composer check` (or the project's equivalent PHPStan command)
after migrating each service. PHPStan level 8 will report errors
for any remaining `parent::__construct($payload)` calls (argument
count mismatch), any `$this->payload` access in subclasses
(undefined property), and any type mismatches in the new typed
constructor parameters.
