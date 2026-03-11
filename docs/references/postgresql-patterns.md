# PostgreSQL Patterns & Gotchas

Hard-won lessons from building VineSuite on PostgreSQL 16 with Laravel. Every entry here cost at least one broken test run. Read this before writing a new migration.

---

## Self-Referencing Foreign Keys with UUID Primary Keys

**TL;DR:** Never put a self-referencing foreign key inside `Schema::create`. Use a separate `Schema::table` call after the table exists.

### The Problem

When a table uses `$table->uuid('id')->primary()`, Laravel/Doctrine DBAL translates the `->primary()` call into an `ALTER TABLE ... ADD PRIMARY KEY` statement that runs *after* the `CREATE TABLE` statement. If you define a foreign key referencing that same table's `id` column inside `Schema::create`, PostgreSQL rejects it because the unique constraint backing the PK doesn't exist yet.

The error looks like this:

```
SQLSTATE[42830]: Invalid foreign key: 7 ERROR:
there is no unique constraint matching given keys for referenced table "lots"
```

This only affects **self-referencing** FKs (where the table references itself). Cross-table FKs are fine because the referenced table was created in an earlier migration and its PK already exists.

### The Fix

Split the migration into two calls — `Schema::create` for the table, then `Schema::table` for the self-referencing FK:

```php
public function up(): void
{
    Schema::create('lots', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->uuid('parent_lot_id')->nullable();
        $table->timestamps();
    });

    // Self-referencing FK must be added after the table (and its PK) exist.
    Schema::table('lots', function (Blueprint $table) {
        $table->foreign('parent_lot_id')
              ->references('id')
              ->on('lots')
              ->nullOnDelete();
    });
}

public function down(): void
{
    // Drop FK before dropping the table to avoid dependency errors.
    Schema::table('lots', function (Blueprint $table) {
        $table->dropForeign(['parent_lot_id']);
    });
    Schema::dropIfExists('lots');
}
```

### Where This Applies

Any table with a self-referencing relationship — parent/child lots, category trees, org hierarchies, threaded comments, etc. If the column points back to the same table's PK, use the two-step pattern.

### When It Doesn't Apply

- Cross-table FKs (the referenced table's PK is already committed)
- Auto-incrementing integer PKs (PostgreSQL handles the PK inline for `SERIAL`/`BIGSERIAL`)
- Tables without self-referencing relationships

---

## JSONB Columns

Use `$table->jsonb()` instead of `$table->json()`. PostgreSQL stores JSON as text but JSONB as decomposed binary, which enables indexing and faster queries. All VineSuite JSON columns should be JSONB.

---

## BRIN Indexes for Append-Only Tables

For tables that are append-only or nearly so (events, activity logs, audit trails), prefer BRIN indexes on timestamp columns over standard B-tree. BRIN indexes are dramatically smaller and nearly as fast for range queries on naturally ordered data.

```php
// In a raw statement inside the migration:
DB::statement('CREATE INDEX events_created_at_brin ON events USING brin (created_at)');
```

---

## Immutability Triggers

For tables that should never be updated or deleted (event log, audit trail), add a PostgreSQL trigger that raises an exception on UPDATE or DELETE. This provides a database-level guarantee that no application bug can corrupt the historical record.

See `database/migrations/tenant/2026_03_10_000005_create_events_table.php` for the reference implementation.

---

*Last updated: 2026-03-10 — Phase 2, Sub-Task 1 (Lot model)*
