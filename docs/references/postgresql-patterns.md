# PostgreSQL Patterns & Gotchas

Hard-won lessons from VineSuite on PostgreSQL 16 + Laravel. Every entry cost at least one broken test.

---

## Self-Referencing Foreign Keys with UUID Primary Keys

**Problem:** Defining a self-referencing FK inside `Schema::create` fails because the unique constraint backing the PK doesn't exist yet.

```
SQLSTATE[42830]: Invalid foreign key: 7 ERROR:
there is no unique constraint matching given keys for referenced table "lots"
```

**Fix:** Split into two calls — `Schema::create` for the table, then `Schema::table` for the self-referencing FK:

```php
public function up(): void
{
    Schema::create('lots', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->uuid('parent_lot_id')->nullable();
        $table->timestamps();
    });

    Schema::table('lots', function (Blueprint $table) {
        $table->foreign('parent_lot_id')
              ->references('id')
              ->on('lots')
              ->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('lots', function (Blueprint $table) {
        $table->dropForeign(['parent_lot_id']);
    });
    Schema::dropIfExists('lots');
}
```

**When it applies:** Any self-referencing relationship (parent/child lots, category trees, hierarchies). Not for cross-table FKs (referenced table's PK already exists) or integer PKs (PostgreSQL handles inline).

---

## JSONB Columns

Use `$table->jsonb()` not `$table->json()`. PostgreSQL stores JSON as text but JSONB as decomposed binary, enabling indexing and faster queries.

---

## BRIN Indexes for Append-Only Tables

For append-only tables (events, activity logs, audit trails), prefer BRIN indexes on timestamp columns over B-tree. BRIN indexes are dramatically smaller and nearly as fast for range queries on naturally ordered data.

```php
DB::statement('CREATE INDEX events_created_at_brin ON events USING brin (created_at)');
```

---

## Immutability Triggers

For tables that should never be updated or deleted (event log, audit trail), add a PostgreSQL trigger that raises an exception on UPDATE or DELETE:

See `database/migrations/tenant/2026_03_10_000005_create_events_table.php` for reference implementation.

---

*Last updated: 2026-03-10 — Phase 2, Sub-Task 1 (Lot model)*
