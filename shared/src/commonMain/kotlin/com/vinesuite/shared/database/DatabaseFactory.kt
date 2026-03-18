package com.vinesuite.shared.database

/**
 * Shared database creation helper.
 * Platform-specific driver is injected via DatabaseDriverFactory.
 */
object DatabaseFactory {
    fun create(driverFactory: DatabaseDriverFactory): VineSuiteDatabase {
        val driver = driverFactory.createDriver()
        return VineSuiteDatabase(driver)
    }
}
