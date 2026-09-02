# Advanced Querying

The API offers a powerful, flexible query layer that lets consumers tailor each
request to retrieve precisely the data they need. Clients can choose which
fields are returned, filter and search records, order results, and page through
large datasets, all through query parameters. This keeps responses lean and
puts the shape of the data in the hands of the consumer.

The parameters combine freely on any endpoint that returns resources. A typical
request looks like this:

```text
?fields[user]=first_name,last_name&filters={"last_name":{"$eq":"Smith"}}&order=created_at:desc&limit=25&page=1
```

| Parameter  | Example                         | Description                                             |
|------------|---------------------------------|---------------------------------------------------------|
| `fields`   | `?fields[user]=first_name`      | Choose the fields returned per resource type.           |
| `counts`   | `?counts[user]=posts`           | Ask for a count of a declared relationship.             |
| `sums`     | `?sums[user][orders]=total`     | Ask for a sum over a declared relationship.             |
| `averages` | `?averages[user][orders]=total` | Ask for an average over a declared relationship.        |
| `filters`  | `?filters={"age":{"$gt":18}}`   | Filter records with a structured JSON query.            |
| `search`   | `?search=John Smith`            | Free-text search across a resource's searchable fields. |
| `order`    | `?order=created_at:desc`        | Order results by a column and direction.                |
| `limit`    | `?limit=25`                     | Set the page size of a paginated list.                  |
| `page`     | `?page=2`                       | Request a specific page of a paginated list.            |
| `trashed`  | `?trashed=with`                 | Widen a read to include soft-deleted records.           |

The first four shape the representation of a resource that is returned, so they
are honoured wherever one is serialised, whatever the verb that produced it.
The next five select, order, and page a collection, so only an endpoint
returning a list answers them. `trashed` widens the scope a read is served from
and so joins both read endpoints, but only where the model behind them soft
deletes at all; a resource that has not opted in to exposing its soft-deleted
records ignores it. Cursor paging adds two more, `pagination` and `cursor`,
described in the Pagination section.

Which of these an endpoint answers is not left to prose: every operation in the
OpenAPI document lists the parameters it accepts, by reference to the shared
definitions in `components.parameters`.

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

A resource declares which of its fields may be filtered and which of its
relationships may be filtered through. A key it has not declared is rejected
rather than ignored: the response is a `422` naming the key. A filter that was
quietly dropped would widen the result set instead of narrowing it, and nothing
in the response would say so.

### Comparison operators

| Operator    | Description                                            |
|-------------|--------------------------------------------------------|
| `$eq`       | Equal to the given value.                              |
| `$neq`      | Not equal to the given value.                          |
| `$gt`       | Greater than the given value.                          |
| `$lt`       | Less than the given value.                             |
| `$ge`       | Greater than or equal to the given value.              |
| `$le`       | Less than or equal to the given value.                 |
| `$in`       | Matches any value in the given array.                  |
| `$between`  | Falls within the given `[min, max]` range.             |
| `$contains` | JSON containment: the column contains the given value. |
| `$null`     | The field is null.                                     |
| `$notNull`  | The field is not null.                                 |

Not every operator is available on every field. Each filterable field is
declared with the access path it has, and answers only the operators that path
serves from an index:

| The field is                                         | It answers                                                                |
|------------------------------------------------------|---------------------------------------------------------------------------|
| A key read by equality: an id, a reference, an email | `$eq`, `$in`, `$null`, `$notNull`                                         |
| A small closed set: a status, a type                 | `$eq`, `$in`, `$neq`, `$null`, `$notNull`                                 |
| An ordered value: a number, a date, a timestamp      | `$eq`, `$in`, `$gt`, `$ge`, `$lt`, `$le`, `$between`, `$null`, `$notNull` |
| A JSON document                                      | `$contains`                                                               |
| A value described no further than queryable          | `$eq`                                                                     |

Pairing an operator with a field that does not answer it is rejected with a
`422` naming the operator, the field, and the operators that field does accept,
so the correction is carried in the error itself. The refusal is decided before
the query is built, so a rejected request reads nothing.

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
      "$eq": "Announcement"
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
      "$eq": "Smith"
    }
  }
}
```

## Searching

Use `search` for free-text matching across a resource's searchable fields:

```text
?search=John Smith
```

### What the term is matched against

The term is matched against the columns of the requested resource only. It does
not follow a relationship, so searching a list of users never reaches the titles
of their posts; nest a filter under the relationship name for that. The limit is
deliberate: a text predicate inside a relationship subquery is paid once per
candidate row, which is the cost this parameter exists to avoid.

A record matches when any one of its searchable fields matches, and the search
always narrows a request further, whatever the filters alongside it ask for. A
resource that declares no searchable field rejects the parameter rather than
answering it with an unnarrowed list.

### Match strategies

Each searchable field is matched in one of three shapes, chosen per field by the
resource:

| Strategy    | Matches                                     | Term matching `Highsmith` |
|-------------|---------------------------------------------|---------------------------|
| `exact`     | The whole value equals the term.            | `Highsmith`               |
| `prefix`    | The value begins with the term.             | `High`                    |
| `substring` | The value carries the term at any position. | `smith`                   |

Whether a match ignores case follows the engine behind the API. On PostgreSQL
every prefix and substring match is case-insensitive, because the comparison
is written that way. On MySQL all three shapes follow the column's own
collation, which folds case under the shipped defaults and does not under a
binary or case-sensitive one.

### Term bounds

A term outside these bounds is rejected with a `422` naming the bound it missed,
never trimmed to fit:

| Bound                           | Value          |
|---------------------------------|----------------|
| Shortest word accepted          | 3 characters   |
| Longest term accepted           | 128 characters |
| Most whitespace-separated words | 10             |

The minimum of three characters is measured rather than chosen, and it is the
one bound the API may raise but never lower. Every match is served from an
index, and three characters is the shortest word both supported engines answer
correctly and from an index. Below it each fails silently and differently: on
MySQL a word shorter than the index token size matches no rows at all, which is
a wrong answer rather than a slow one, and on PostgreSQL a two-character term is
answered correctly but by reading the whole table, which is the full scan this
layer exists to remove. Neither failure is visible in the response, so a term
that would hit one is refused instead.

The minimum is measured against every word rather than against the whole term,
so `John Smith` is accepted and `J Smith` is not. A word beneath it is dropped
from a full-text phrase, which widens the match, while a pattern comparison
keeps it and narrows on it, so a term carrying one would be answered with
different rows depending on the engine behind the API.

### Indexes behind a declaration

This subsection is for the application serving the API rather than its clients.

Every declared match shape is served from an index the application's own
migrations create. `php artisan api-toolkit:validate-schemas` reports a
declaration with no index behind it, and the build is the cheapest place to
find one. Because schema validation is disabled in production by default, the
same proof is taken again on the first search each worker process serves and
memoised from there, so a missing index refuses the request rather than being
answered out of a scan.

| Strategy    | MySQL                                                                        | PostgreSQL                                 |
|-------------|------------------------------------------------------------------------------|--------------------------------------------|
| `exact`     | An ordinary index leading with the column.                                   | An ordinary index leading with the column. |
| `prefix`    | An ordinary index leading with the column.                                   | A trigram index over the column.           |
| `substring` | One `FULLTEXT` index over exactly the declared columns, `WITH PARSER ngram`. | A trigram index over the column.           |

```sql
-- MySQL: a substring match, over exactly the columns declared for it
ALTER TABLE users ADD FULLTEXT INDEX users_search_ngram (name, email) WITH PARSER ngram;

-- PostgreSQL: a prefix or a substring match, per declared column
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX users_name_trgm ON users USING gin (name gin_trgm_ops);
CREATE INDEX users_email_trgm ON users USING gin (email gin_trgm_ops);
```

MySQL matches the columns declared for a substring together, through a single
`MATCH`, and resolves that match only against a full-text index whose column
list is exactly the matched one - which is why one index covers the declared
set rather than one index per column. For the same reason a substring match may
not be declared beside another strategy on MySQL: the two would be combined by
`OR`, which loses the full-text access path and reads the whole table, so the
declaration is refused instead. PostgreSQL carries no such restriction.

A prefix match on PostgreSQL rides the same trigram index as a substring match
because the comparison is case-insensitive, which an ordinary index cannot
serve. SQLite carries neither index kind and is treated as a development
connection: it serves every shape and proves none of them.

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

Ordering is limited to the fields the resource declares sortable, and any other
column is rejected with a `422` naming it. Sorting the whole table at random to
return a single page is expensive enough that `random` is available only where
the API has enabled it; where it has not, it is rejected like any undeclared
column.

## Limiting and paging

On paginated endpoints, `limit` sets the page size and `page` selects the page.
Both apply only when the response is a paginated list:

```text
?limit=25&page=2
```

The `limit` is bounded by a configured ceiling, which the document publishes as
the parameter's schema maximum. A request above it is rejected with a `422`
naming the ceiling and the size asked for, rather than answered with a smaller
page: a page quietly reduced cannot be told apart from the end of the result
set. See the Pagination section for the shape of a paginated response.

## Discovering what a resource accepts

The sections above describe the grammar; which fields a given resource answers
it with is declared by the resource itself, and the OpenAPI document names them.
Every property of a resource schema that answers a query carries an
`x-query-surface` extension:

```json
"email": {
  "type": "string",
  "x-query-surface": {
    "filter": {
      "key": "email",
      "capability": "exact",
      "operators": ["$eq", "$in", "$null", "$notNull"]
    },
    "sort": {"key": "email", "indexed": true},
    "search": {"key": "email", "strategy": "prefix"}
  }
}
```

Each part appears only where the resource declared it, so a property carrying no
`x-query-surface` answers no filter, no order, and no search. The `key` is the
name to send in `filters` and `order`, which differs from the property name
wherever a field is presented under an alias. A `sort` reporting `"indexed":
false` also carries the reason the resource recorded for ordering by a column no
index holds.

The relations a filter may descend through belong to the resource rather than to
any one property, so the schema names them itself:

```json
"x-traversable-relations": ["organization", "posts"]
```

The same declarations are written out for a reader rather than a code generator
in the Query Surface Reference section, which tables the filterable, sortable,
and searchable columns of every resource this document describes, beside the
bounds a single request is held to and the shape an over-budget request is
rejected with. That section is generated from the compiled schema on every
documentation run, so it cannot drift from what the API accepts, and it is
generated once per audience, so it names only the resources the document you
are reading also carries a schema for.

The operators a property lists are narrowed to what the API can still dispatch:
a token removed from the operator registry leaves the surface along with the
grammar, and a column left with no operator at all carries no `filter` member.
