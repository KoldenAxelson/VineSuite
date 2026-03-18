package com.vinesuite.shared.api.models

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * POST /api/v1/auth/login request body.
 */
@Serializable
data class LoginRequest(
    val email: String,
    val password: String,
    @SerialName("client_type")
    val clientType: String = "cellar_app",
    @SerialName("device_name")
    val deviceName: String,
)

/**
 * Login success response data.
 */
@Serializable
data class LoginResponse(
    val token: String,
    val user: UserInfo,
)

@Serializable
data class UserInfo(
    val id: String,
    val name: String,
    val email: String,
    val role: String,
)
