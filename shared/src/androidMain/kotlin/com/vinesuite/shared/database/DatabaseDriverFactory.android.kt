package com.vinesuite.shared.database

import android.content.Context
import app.cash.sqldelight.db.SqlDriver
import app.cash.sqldelight.driver.android.AndroidSqliteDriver

/**
 * Android driver — file-backed SQLite via Android framework.
 */
actual class DatabaseDriverFactory(private val context: Context) {
    actual fun createDriver(): SqlDriver {
        return AndroidSqliteDriver(VineSuiteDatabase.Schema, context, "vinesuite.db")
    }
}
