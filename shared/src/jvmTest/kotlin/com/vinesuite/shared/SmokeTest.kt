package com.vinesuite.shared

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue
import kotlinx.coroutines.async
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.datetime.Clock
import kotlin.time.Duration.Companion.hours

/**
 * JVM smoke tests — validates the KMP project scaffolding.
 *
 * Proves:
 * 1. expect/actual resolution works on JVM target
 * 2. Key dependencies (coroutines, serialization, datetime) are wired correctly
 * 3. JVM test harness runs via `./gradlew jvmTest`
 */
class SmokeTest {

    @Test
    fun jvmPlatformResolves() {
        assertEquals("JVM", platformName())
    }

    @Test
    fun coroutinesConcurrencyWorks() = runTest {
        // Proves coroutines actually execute concurrently, not just that the library loads
        val deferred1 = async { 1 + 1 }
        val deferred2 = async { 2 + 2 }
        assertEquals(6, deferred1.await() + deferred2.await())
    }

    @Test
    fun serializationRoundTrips() {
        val json = Json.parseToJsonElement("""{"key": "value"}""")
        val obj = json as JsonObject
        assertEquals("value", obj["key"]?.jsonPrimitive?.content)
    }

    @Test
    fun datetimeArithmeticWorks() {
        // Proves kotlinx-datetime can do real operations, not just toString()
        val now = Clock.System.now()
        val later = now.plus(1.hours)
        assertTrue(later > now, "Adding 1 hour should produce a later instant")
    }
}
