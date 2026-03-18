package com.vinesuite.shared.api.models

import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject

/**
 * Standard API response envelope.
 * Every VineSuite API response follows: { data, meta, errors }
 *
 * `data` is a raw JsonElement — the caller deserializes it to the
 * concrete type after checking for errors. This avoids generic type
 * erasure issues with kotlinx-serialization.
 */
@Serializable
data class ApiEnvelope(
    val data: JsonElement? = null,
    val meta: JsonObject = JsonObject(emptyMap()),
    val errors: List<ApiError> = emptyList(),
)

@Serializable
data class ApiError(
    val message: String,
    val field: String? = null,
)
