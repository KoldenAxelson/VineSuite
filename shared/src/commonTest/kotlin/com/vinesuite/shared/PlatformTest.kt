package com.vinesuite.shared

import kotlin.test.Test
import kotlin.test.assertTrue

class PlatformTest {

    @Test
    fun platformNameIsNotBlank() {
        val name = platformName()
        assertTrue(name.isNotBlank(), "platformName() should return a non-blank string")
    }
}
