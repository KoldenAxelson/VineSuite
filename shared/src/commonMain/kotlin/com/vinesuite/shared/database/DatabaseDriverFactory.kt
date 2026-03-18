package com.vinesuite.shared.database

import app.cash.sqldelight.db.SqlDriver

/**
 * Platform-specific SQLite driver factory.
 *
 * Each platform provides its own actual implementation:
 * - JVM: JdbcSqliteDriver (in-memory for tests, file-backed for dev)
 * - Android: AndroidSqliteDriver
 * - iOS: NativeSqliteDriver
 */
expect class DatabaseDriverFactory {
    fun createDriver(): SqlDriver
}
