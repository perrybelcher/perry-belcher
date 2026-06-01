# Phase 1 performance gate

`facet-benchmark.php` exercises the **real** `Lodestar\Data\QueryBuilder` output
(a 3-facet + radius, sorted, paginated query plus its COUNT companion) against a
seeded dataset, and times it. This is the harness behind the Phase 1 Definition
of Done: *50,000 listings, sub-150 ms faceted + radius query.*

## SQLite proxy (runs anywhere)

```bash
php tests/benchmark/facet-benchmark.php          # 50,000 listings, 30 iterations
php tests/benchmark/facet-benchmark.php 100000 50 # custom size / iterations
```

The SQLite driver is self-contained (in-memory, indexes mirroring the production
schema). It is **not** a substitute for the MySQL number — different engine — but
it proves the access pattern is *index-driven*: the query plan shows the lat/lng
bounding-box index driving the scan and indexed `field_index` lookups per facet,
never an `wp_postmeta`-style self-join. That access pattern is the whole bet.

## MySQL — the authoritative gate

Point the harness at a real MySQL/MariaDB (e.g. your WP test database). It seeds
the dataset on first run if the table is short.

```bash
LODESTAR_BENCH_DRIVER=mysql \
LODESTAR_BENCH_MYSQL_DSN="mysql:host=127.0.0.1;dbname=wordpress;charset=utf8mb4" \
LODESTAR_BENCH_MYSQL_USER=root \
LODESTAR_BENCH_MYSQL_PASS=secret \
php tests/benchmark/facet-benchmark.php 50000 40
```

> The custom tables must already exist (run plugin activation, or the migration
> runner, against that database first). The benchmark seeds `listing_data` and
> `field_index` rows only; it does not create the schema.

The script exits non-zero if the combined median is ≥ 150 ms, so it can gate CI.
