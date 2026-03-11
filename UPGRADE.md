# Upgrade Guide

## From 1.x to 2.x

### Immutable Service Configuration (Breaking)

Service configuration properties (`$useTransaction`, `$useLock`) are now `readonly` and set at construction time. The
four fluent toggle methods have been removed.

#### Removed Methods

- `$service->useTransaction()`
- `$service->dontUseTransaction()`
- `$service->useLock()`
- `$service->dontUseLock()`

Calling any of these methods will produce a fatal error.

#### Migration Steps

**1. Replace toggle calls with constructor parameters**

Before:

```php
$service = new MyService($payload);
$service->dontUseTransaction();
$service->useLock();
$result = $service->run();
```

After:

```php
$service = new MyService($payload, useTransaction: false, useLock: true);
$result = $service->run();
```

**2. Replace property overrides in subclasses with constructor defaults**

Before:

```php
class MyLockableService extends Service
{
    protected bool $useLock = true;

    public function handle(): bool
    {
        // ...
    }
}
```

After:

```php
class MyLockableService extends Service
{
    public function __construct(
        array|Collection|\stdClass $payload = [],
        bool $useTransaction = true,
        bool $useLock = true,
    ) {
        parent::__construct($payload, $useTransaction, $useLock);
    }

    public function handle(): bool
    {
        // ...
    }
}
```

**3. Update subclass constructors that override the parent**

If your subclass overrides the constructor, pass the new parameters through:

Before:

```php
class MyService extends Service
{
    public function __construct(array $payload, private readonly UserRepository $users)
    {
        parent::__construct($payload);
    }
}
```

After:

```php
class MyService extends Service
{
    public function __construct(
        array $payload,
        private readonly UserRepository $users,
        bool $useTransaction = true,
        bool $useLock = false,
    ) {
        parent::__construct($payload, $useTransaction, $useLock);
    }
}
```

#### Default Behaviour

The default values are unchanged:

- `$useTransaction = true` (transactions enabled by default)
- `$useLock = false` (locking disabled by default)

Services that do not override the constructor or set these properties will behave identically to before.

#### Lock Key Validation

Lock key validation now occurs at construction time. If `useLock: true` is passed and `getLockId()` returns an empty
string, a `\RuntimeException` is thrown immediately rather than at `run()` time.

### Lockable Trait Independence

The `Lockable` trait can now be used on any class, not only on classes extending `Service`. To use it independently:

```php
use SineMacula\ApiToolkit\Traits\Lockable;

class MyLockableJob
{
    use Lockable;

    protected function generateLockKey(): string
    {
        return sha1(static::class . '|' . $this->jobId);
    }
}
```

The trait requires only that consumers implement `generateLockKey(): string`. No other dependencies exist.
