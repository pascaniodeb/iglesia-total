# Database

## Identifiers

- Primary keys are **UUIDv7** (`uuid` column), never auto-increment ids or UUIDv4. Reasons: time-ordered (index-friendly, stable keyset pagination), safe to expose, no enumeration.
- Migrations declare `$table->uuid('id')->primary()->default(DB::raw('uuidv7()'))` (native in PostgreSQL 18). Models use `HasUuids` (Laravel generates v7). Foreign keys use `foreignUuid`.
- Self-referencing foreign keys are added in a second `Schema::table` call, after the primary key exists.

## Columns

- Timestamps are `timestampsTz()`; records that must not be lost use `softDeletesTz()`.
- Every PostgreSQL connection sets its session time zone to the application's (`timezone` in `config/database.php`, UTC). Eloquent writes dates without an offset: without this the server's own zone is applied and instants shift by hours.
- Closed value sets: a PHP backed enum in `app/Enums` plus an `enum()` column (varchar + CHECK). Migrations list the literal values and never reference app enum classes, so history stays reproducible.
- Case/accent-insensitive text search uses a generated column: `search_name = lower(immutable_unaccent(full_name))` with a GIN `gin_trgm_ops` index. Do not run `ILIKE` on the raw column.

## Lists and filters

- Filtering and pagination happen on the server. Never load a whole table into the client.
- Registry-size lists (members) use offset pagination with a total through `App\Http\Tables\TableQuery`: the count runs over a filter-only query (no joins) and presentation data is added only to the page of rows. Unbounded or very large lists use keyset (cursor) pagination. Either way the order always ends with `id` as the tiebreaker, e.g. `ORDER BY search_name, id`.
- Search, sort, status and facets come from whitelists (`TableQuery::fromRequest`); an unknown URL value falls back to the default, it never reaches the SQL.
- Indexes for the default list are partial (`WHERE deleted_at IS NULL`). Every foreign key column is indexed.
- Add an index only for a real query, and check it with `EXPLAIN (ANALYZE)` at volume. No speculative indexes.

## Legacy import

- The old system (TanStack/Drizzle, integer ids) is a read-only source through the `legacy` connection (`LEGACY_DB_*` in `.env`).
- Imported tables keep a unique `legacy_id`. New rows get a UUIDv7 built from the legacy `created_at`, so id order follows registration order.
- Importers are idempotent (matched by `legacy_id`), run inside one transaction, and never delete rows in the new database.
- Members: `php artisan members:import-legacy` (`--dry-run` to preview). Then users: `php artisan users:import-legacy` (see [auth.md](auth.md)).

## Tests

- The suite runs against real PostgreSQL (`iglesiatotal_test`), not SQLite, because the schema uses PostgreSQL features. Never mock the database.
