# Pagination

Index endpoints return their records as a paginated list. Every paginated
response carries a `data` array alongside a `links` object, for navigating
between pages, and a `meta` object, for information about the set. The API
supports two pagination strategies: length-aware and cursor.

## Length-aware pagination

Length-aware pagination is the default. It counts the full result set, so the
response reports the total number of records and offers links to the first,
previous, next, and last pages. Select a page with the `page` parameter and its
size with `limit`:

```text
?page=2&limit=25
```

A length-aware response looks like this, its `data` cut to the first record:

```json
{
  "data": [
    {
      "_type": "user",
      "id": 26,
      "first_name": "John",
      "last_name": "Smith",
      "created_at": "2024-06-19T23:11:52+00:00"
    }
  ],
  "links": {
    "self": "https://api.example.com/users?limit=25&page=2",
    "first": "https://api.example.com/users?limit=25&page=1",
    "prev": "https://api.example.com/users?limit=25&page=1",
    "next": "https://api.example.com/users?limit=25&page=3",
    "last": "https://api.example.com/users?limit=25&page=11"
  },
  "meta": {
    "total": 275,
    "total_capped": false,
    "count": 25,
    "continue": true
  }
}
```

The `meta` fields report the state of the set:

| Field          | Type      | Description                                      |
|----------------|-----------|--------------------------------------------------|
| `total`        | `integer` | The total number of records across all pages.    |
| `total_capped` | `boolean` | Whether more records match than `total` reports. |
| `count`        | `integer` | The number of records on the current page.       |
| `continue`     | `boolean` | Whether a further page follows the current one.  |

The `links` object provides `self`, `first`, `prev`, `next`, and `last`. The
`prev` and `next` links are `null` at the first and last pages respectively.

Length-aware collection responses also carry a `Total-Count` header echoing the
total record count.

When more records match than length-aware pagination can reach, `total` counts
only the records up to its deepest page, `total_capped` is `true`, and the
`Total-Count` header is absent. Use cursor pagination to read further.

## Cursor pagination

Cursor pagination trades the total count for stable, efficient paging over large
or frequently changing datasets. Rather than a page number, each response
returns an opaque cursor pointing at the next slice. Request it either with the
`pagination` parameter or by supplying a `cursor` directly:

```text
?pagination=cursor&limit=25
```

```text
?cursor=eyJ1c2Vycy5pZCI6MjUsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0&limit=25
```

A cursor response omits the total and the first/last links, since neither can be
known without counting the set. Again only the first record is shown:

```json
{
  "data": [
    {
      "_type": "user",
      "id": 26,
      "first_name": "John",
      "last_name": "Smith",
      "created_at": "2024-06-19T23:11:52+00:00"
    }
  ],
  "links": {
    "self": "https://api.example.com/users?cursor=eyJ1c2Vycy5pZCI6MjUsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0&limit=25",
    "prev": "https://api.example.com/users?limit=25&cursor=eyJ1c2Vycy5pZCI6MjYsIl9wb2ludHNUb05leHRJdGVtcyI6ZmFsc2V9",
    "next": "https://api.example.com/users?limit=25&cursor=eyJ1c2Vycy5pZCI6NTAsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0"
  },
  "meta": {
    "continue": true
  }
}
```

The cursor `meta` and `links` are a smaller set than the length-aware shape:

| Field      | Type      | Description                                          |
|------------|-----------|------------------------------------------------------|
| `continue` | `boolean` | Whether a further page follows the current one.      |

The `links` object provides `self`, `prev`, and `next`. Follow the `next` link
to advance; it is `null` once the final slice is reached. The `prev` link is
`null` at the start of the set.
