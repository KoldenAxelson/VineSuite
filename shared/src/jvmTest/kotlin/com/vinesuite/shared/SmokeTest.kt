package com.vinesuite.shared

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.datetime.Clock

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
    fun coroutinesWired() = runTest {
        // If this compiles and runs, kotlinx-coroutines-test is resolved
        val result = "coroutines work"
        assertEquals("coroutines work", result)
    }

    @Test
    fun serializationWired() {
        // If this compiles and runs, kotlinx-serialization-json is resolved
        val json = Json.parseToJsonElement("""{"key": "value"}""")
        val obj = json as JsonObject
        assertEquals("value", obj["key"]?.jsonPrimitive?.content)
    }

    @Test
    fun datetimeWired() {
        // If this compiles and runs, kotlinx-datetime is resolved
        val now = Clock.System.now()
        val iso = now.toString()
        assert(iso.isNotBlank()) { "Clock.System.now() should produce a non-blank ISO string" }
    }
}
