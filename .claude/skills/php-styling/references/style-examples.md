# Style Examples

Use these examples to keep style changes aligned with repository conventions.

## Signature Wrapping

Single-line when <= 180 chars (measured using short/imported names, not FQCN):

```php
private function makeRequest(array $overrides = []): HttpRequest
```

Multi-line when > 180 chars:

```php
public function send(
    HttpRequest $request,
    array $options = [],
    ?\Closure $observer = null,
    ?RetryPolicyInterface $retry_policy = null,
    ?LoggerInterface $logger = null,
    float $timeout = 30.0,
): HttpResponse
```

## Conditional Padding

Simple block:

```php
if ($is_valid) {
    return true;
}
```

Non-trivial block:

```php
if ($is_valid) {

    $normalized = $this->normalize($input);

    foreach ($normalized as $value) {
        if ($value === 'target') {
            return true;
        }
    }
}
```

## Statement Grouping

```php
$line_1 = $dto->line1();
$line_2 = $dto->line2();

return [$line_1, $line_2];
```
