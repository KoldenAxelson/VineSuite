package com.vinesuite.shared.api

/**
 * API environment configuration.
 * App layer picks the environment at startup and passes it to ApiClient.
 */
sealed class ApiEnvironment(val baseUrl: String) {
    data object Production : ApiEnvironment("https://api.vinesuite.com/api/v1")
    data object Staging : ApiEnvironment("https://staging-api.vinesuite.com/api/v1")
    data class Custom(val url: String) : ApiEnvironment(url)
}
