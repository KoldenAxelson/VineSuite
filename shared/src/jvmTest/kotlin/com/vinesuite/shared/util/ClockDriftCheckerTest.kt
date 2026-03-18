package com.vinesuite.shared.util

import kotlinx.coroutines.delay
import kotlinx.coroutines.test.runTest
import kotlinx.datetime.Clock
import kotlinx.datetime.Instant
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue
import kotlin.time.Duration.Companion.seconds

class ClockDriftCheckerTest {

    // ── No drift ─────────────────────────────────────────────────

    @Test
    fun noDriftReturnsOk() = runTest {
        val now = Instant.parse("2024-10-15T14:00:00Z")
        val checker = ClockDriftChecker(
            referenceTimeSource = FakeTimeSource(now),
            deviceClock = fixedClock(now),
        )

        val result = checker.check()

        assertTrue(result is DriftResult.Ok)
        assertEquals(0, (result as DriftResult.Ok).driftSeconds)
    }

    @Test
    fun smallDriftWithinThresholdReturnsOk() = runTest {
        val referenceTime = Instant.parse("2024-10-15T14:00:00Z")
        val deviceTime = Instant.parse("2024-10-15T14:00:15Z") // 15 seconds ahead
        val checker = ClockDriftChecker(
            referenceTimeSource = FakeTimeSource(referenceTime),
            deviceClock = fixedClock(deviceTime),
        )

        val result = checker.check()

        assertTrue(result is DriftResult.Ok)
        assertEquals(15, (result as DriftResult.Ok).driftSeconds)
    }

    @Test
    fun exactThresholdReturnsOk() = runTest {
        val referenceTime = Instant.parse("2024-10-15T14:00:00Z")
        val deviceTime = Instant.parse("2024-10-15T14:00:30Z") // exactly 30 seconds
        val checker = ClockDriftChecker(
            referenceTimeSource = FakeTimeSource(referenceTime),
            deviceClock = fixedClock(deviceTime),
        )

        val result = checker.check()

        assertTrue(result is DriftResult.Ok)
        assertEquals(30, (result as DriftResult.Ok).driftSeconds)
    }

    // ── Drift exceeds threshold ──────────────────────────────────

    @Test
    fun driftAboveThresholdReturnsDrifted() = runTest {
        val referenceTime = Instant.parse("2024-10-15T14:00:00Z")
        val deviceTime = Instant.parse("2024-10-15T14:01:00Z") // 60 seconds ahead
        val checker = ClockDriftChecker(
            referenceTimeSource = FakeTimeSource(referenceTime),
            deviceClock = fixedClock(deviceTime),
        )

        val result = checker.check()

        assertTrue(result is DriftResult.Drifted)
        val drifted = result as DriftResult.Drifted
        assertEquals(60, drifted.driftSeconds)
        assertEquals(deviceTime, drifted.deviceTime)
        assertEquals(referenceTime, drifted.referenceTime)
    }

    @Test
    fun deviceClockBehindDetectsDrift() = runTest {
        val referenceTime = Instant.parse("2024-10-15T14:02:00Z")
        val deviceTime = Instant.parse("2024-10-15T14:00:00Z") // 2 minutes behind
        val checker = ClockDriftChecker(
            referenceTimeSource = FakeTimeSource(referenceTime),
            deviceClock = fixedClock(deviceTime),
        )

        val result = checker.check()

        assertTrue(result is DriftResult.Drifted)
        assertEquals(120, (result as DriftResult.Drifted).driftSeconds)
    }

    // ── Offline / errors ─────────────────────────────────────────

    @Test
    fun networkErrorReturnsUnavailable() = runTest {
        val checker = ClockDriftChecker(
            referenceTimeSource = FailingTimeSource(RuntimeException("No network")),
            deviceClock = fixedClock(Instant.parse("2024-10-15T14:00:00Z")),
        )

        val result = checker.check()

        assertTrue(result is DriftResult.Unavailable)
    }

    @Test
    fun timeoutReturnsUnavailable() = runTest {
        val checker = ClockDriftChecker(
            referenceTimeSource = SlowTimeSource(delayMs = 10_000), // 10 seconds
            deviceClock = fixedClock(Instant.parse("2024-10-15T14:00:00Z")),
            timeoutDuration = 1.seconds,
        )

        val result = checker.check()

        assertTrue(result is DriftResult.Unavailable)
    }

    // ── Helpers ──────────────────────────────────────────────────

    private fun fixedClock(instant: Instant): Clock = object : Clock {
        override fun now(): Instant = instant
    }

    private class FakeTimeSource(private val time: Instant) : ReferenceTimeSource {
        override suspend fun fetchCurrentTime(): Instant = time
    }

    private class FailingTimeSource(private val error: Exception) : ReferenceTimeSource {
        override suspend fun fetchCurrentTime(): Instant = throw error
    }

    private class SlowTimeSource(private val delayMs: Long) : ReferenceTimeSource {
        override suspend fun fetchCurrentTime(): Instant {
            delay(delayMs)
            return Instant.parse("2024-10-15T14:00:00Z")
        }
    }
}
