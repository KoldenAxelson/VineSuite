package com.vinesuite.shared.util

import kotlinx.coroutines.withTimeoutOrNull
import kotlinx.datetime.Clock
import kotlinx.datetime.Instant
import kotlin.math.abs
import kotlin.time.Duration
import kotlin.time.Duration.Companion.seconds

/**
 * Checks device clock against a trusted time source (NTP or server).
 *
 * On app launch, call [check]. If the device clock has drifted more than
 * [DRIFT_THRESHOLD] from the reference time, a [DriftResult.Drifted] is
 * returned with the drift amount. The UI should show a non-blocking warning.
 *
 * Clock drift matters because event ordering for conflict resolution uses
 * `performed_at` timestamps. If two devices have drifted clocks, event
 * ordering may be wrong.
 *
 * Design:
 * - [ReferenceTimeSource] is an interface so tests can inject a fake.
 * - Production uses [ServerTimeSource] which pings the VineSuite API.
 * - If offline or the check times out, returns [DriftResult.Unavailable].
 * - Never blocks the app — the check has a configurable timeout.
 */
class ClockDriftChecker(
    private val referenceTimeSource: ReferenceTimeSource,
    private val deviceClock: Clock = Clock.System,
    private val timeoutDuration: Duration = 5.seconds,
    private val logger: Logger = NoOpLogger,
) {
    companion object {
        /** Drift threshold in seconds. Warn if exceeded. */
        const val DRIFT_THRESHOLD_SECONDS = 30L
        private const val TAG = "VineSuite.ClockDrift"
    }

    /**
     * Check clock drift. Non-blocking, returns within [timeoutDuration].
     *
     * Call on app launch. If the result is [DriftResult.Drifted], show
     * a warning to the user (don't block them).
     */
    suspend fun check(): DriftResult {
        val referenceTime = withTimeoutOrNull(timeoutDuration) {
            try {
                referenceTimeSource.fetchCurrentTime()
            } catch (_: Exception) {
                null
            }
        } ?: return DriftResult.Unavailable("Could not reach time source")

        val deviceTime = deviceClock.now()
        val driftSeconds = abs((deviceTime - referenceTime).inWholeSeconds)

        return if (driftSeconds > DRIFT_THRESHOLD_SECONDS) {
            logger.warn(TAG, "Clock drift detected: ${driftSeconds}s (device=$deviceTime, reference=$referenceTime)")
            DriftResult.Drifted(
                driftSeconds = driftSeconds,
                deviceTime = deviceTime,
                referenceTime = referenceTime,
            )
        } else {
            logger.debug(TAG, "Clock drift OK: ${driftSeconds}s")
            DriftResult.Ok(driftSeconds = driftSeconds)
        }
    }
}

/**
 * Result of a clock drift check.
 */
sealed class DriftResult {
    /** Clock is within acceptable range. */
    data class Ok(val driftSeconds: Long) : DriftResult()

    /** Clock has drifted beyond threshold — warn the user. */
    data class Drifted(
        val driftSeconds: Long,
        val deviceTime: Instant,
        val referenceTime: Instant,
    ) : DriftResult()

    /** Could not check (offline, timeout, error). Not a problem — just skip. */
    data class Unavailable(val reason: String) : DriftResult()
}

/**
 * Fetches the current time from a trusted source.
 * Implementations: ServerTimeSource (production), FakeTimeSource (tests).
 */
interface ReferenceTimeSource {
    suspend fun fetchCurrentTime(): Instant
}

/**
 * Production time source — uses the VineSuite API server's response
 * Date header or a dedicated time endpoint.
 *
 * This avoids needing a raw NTP implementation in KMP (which would
 * require platform-specific UDP sockets). The API server's clock is
 * NTP-synced, so it's a reliable reference.
 */
class ServerTimeSource(
    private val serverTimeFetcher: suspend () -> Instant,
) : ReferenceTimeSource {
    override suspend fun fetchCurrentTime(): Instant = serverTimeFetcher()
}
