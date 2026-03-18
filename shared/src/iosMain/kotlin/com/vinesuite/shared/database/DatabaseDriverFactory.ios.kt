package com.vinesuite.shared.database

import app.cash.sqldelight.db.SqlDriver
import app.cash.sqldelight.driver.native.NativeSqliteDriver

/**
 * iOS driver — file-backed SQLite via Kotlin/Native.
 */
actual class DatabaseDriverFactory {
    actual fun createDriver(): SqlDriver {
        return NativeSqliteDriver(VineSuiteDatabase.Schema, "vinesuite.db")
    }
}
