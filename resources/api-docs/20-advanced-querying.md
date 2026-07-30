# Advanced Querying

The API offers a powerful, flexible query layer that lets consumers tailor each
request to retrieve precisely the data they need. Clients can choose which
fields are returned, filter and search records, order results, and page through
large datasets, all through query parameters. This keeps responses lean and
puts the shape of the data in the hands of the consumer.

The parameters combine freely on any endpoint that returns resources. A typical
request looks like this:

```text
?fields[user]=first_name,last_name&filters={"last_name":{"$like":"Smith"}}&order=created_at:desc&limit=25&page=1
```

| Parameter | Example                          | Description                                                                 |
|-----------|----------------------------------|-----------------------------------------------------------------------------|
| `fields`  | `?fields[user]=first_name`       | Choose the fields returned per resource type. Available on all endpoints.   |
| `filters` | `?filters={"age":{"$gt":18}}`    | Filter records with a structured JSON query. Available on index endpoints.  |
| `search`  | `?search=John Smith`             | Free-text search across a resource's searchable fields. Index endpoints.    |
| `order`   | `?order=created_at:desc`         | Order results by a column and direction. Available on index endpoints.      |
| `limit`   | `?limit=25`                      | Set the page size of a paginated list. Available on index endpoints.        |
| `page`    | `?page=2`                        | Request a specific page of a paginated list. Available on index endpoints.  |

## Fields

Use `fields` to declare exactly which attributes should appear in the response,
keyed by the resource type. Requesting only what you need is strongly
recommended, as it minimises the size of the response body.

There are three ways to define the fields returned:

| Selection          | Example                              |
|--------------------|--------------------------------------|
| All allowed fields | `?fields[user]=:all`                 |
| Default fields     | `?fields[user]=:default`             |
| Explicit list      | `?fields[user]=first_name,last_name` |

Fields can be selected on related resources in the same request by keying each
type separately:

```text
?fields[user]=first_name&fields[organization]=name
```

## Filtering

Filters are expressed as a JSON object, then URL-encoded and passed as the
`filters` parameter. Each key is a field name, a relationship, a logical
operator, or a comparison operator, and its value depends on which it is.
Unrecognised fields are ignored rather than rejected.

### Comparison operators

| Operator    | Description                                            |
|-------------|--------------------------------------------------------|
| `$eq`       | Equal to the given value.                              |
| `$neq`      | Not equal to the given value.                          |
| `$gt`       | Greater than the given value.                          |
| `$lt`       | Less than the given value.                             |
| `$ge`       | Greater than or equal to the given value.              |
| `$le`       | Less than or equal to the given value.                 |
| `$like`     | Partial match containing the given value.              |
| `$in`       | Matches any value in the given array.                  |
| `$between`  | Falls within the given `[min, max]` range.             |
| `$contains` | JSON containment: the column contains the given value. |
| `$null`     | The field is null.                                     |
| `$notNull`  | The field is not null.                                 |

### Logical operators

Two logical operators combine conditions: `$and` and `$or`. When multiple
fields are supplied without a wrapper, they are combined with `$and` by default.

### Relationship filters

Filter by a related resource by nesting a filter under the relationship name.
The record matches when the relationship has at least one entry satisfying the
nested filter:

```json
{
  "posts": {
    "title": {
      "$like": "Announcement"
    }
  }
}
```

Two operators assert only the presence or absence of a relationship, without
constraining its contents:

| Operator  | Description                                        |
|-----------|----------------------------------------------------|
| `$has`    | The record has at least one of the relationship.   |
| `$hasnt`  | The record has none of the relationship.           |

Each takes a list of relationship names, or a map of relationship names to a
nested filter the related records must satisfy:

```json
{
  "$has": ["posts"],
  "$hasnt": {
    "comments": {
      "flagged": true
    }
  }
}
```

### Building a filter

The simplest filter matches a field to a value directly. The following matches
records where `email` equals the given value:

```json
{
  "email": "john@example.com"
}
```

This is shorthand for the explicit `$eq` form:

```json
{
  "email": {
    "$eq": "john@example.com"
  }
}
```

Comparison operators take the value shape their meaning implies. `$between`
takes a two-element range, and `$in` takes an array:

```json
{
  "last_name": {
    "$like": "Smith"
  },
  "created_at": {
    "$between": ["2024-01-01 00:00:00", "2024-12-31 23:59:59"]
  },
  "first_name": {
    "$in": ["Ben", "John"]
  }
}
```

Multiple fields are combined with `$and` by default. To combine with `$or`
instead, wrap the conditions:

```json
{
  "$or": {
    "first_name": {
      "$in": ["Ben", "John"]
    },
    "last_name": {
      "$like": "Smith"
    }
  }
}
```

## Searching

Use `search` for free-text matching across a resource's searchable fields:

```text
?search=John Smith
```

## Ordering

Order results by specifying a column and an optional direction, separated by a
colon:

```text
?order=created_at:desc
```

There are three directions: `asc` (the default), `desc`, and `random`. A random
order takes no column:

```text
?order=random
```

## Limiting and paging

On paginated endpoints, `limit` sets the page size and `page` selects the page.
Both apply only when the response is a paginated list:

```text
?limit=25&page=2
```

The `limit` is clamped to a configured maximum, so an oversized request is
capped rather than rejected. See the Pagination section for the shape of a
paginated response.
