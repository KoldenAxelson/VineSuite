plugins {
    alias(libs.plugins.kotlinMultiplatform)
    alias(libs.plugins.kotlinSerialization)
    alias(libs.plugins.sqldelight)
    alias(libs.plugins.androidLibrary)
    alias(libs.plugins.kover)
}

kotlin {
    // Default hierarchy: commonMain → appleMain → iosMain, etc.
    applyDefaultHierarchyTemplate()

    // Suppress beta warning for expect/actual classes (KT-61573)
    @OptIn(org.jetbrains.kotlin.gradle.ExperimentalKotlinGradlePluginApi::class)
    compilerOptions {
        freeCompilerArgs.add("-Xexpect-actual-classes")
    }

    // ── Targets ──────────────────────────────────────────────────
    jvm()

    androidTarget {
        compilerOptions {
            jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17)
        }
    }

    iosArm64()
    iosSimulatorArm64()

    // iOS framework for native app consumption
    listOf(iosArm64(), iosSimulatorArm64()).forEach { target ->
        target.binaries.framework {
            baseName = "VineSuiteShared"
            isStatic = true
        }
    }

    // ── Source Sets ──────────────────────────────────────────────
    sourceSets {
        commonMain.dependencies {
            implementation(libs.kotlinx.coroutines.core)
            implementation(libs.kotlinx.serialization.json)
            implementation(libs.kotlinx.datetime)
            implementation(libs.ktor.client.core)
            implementation(libs.ktor.client.content.negotiation)
            implementation(libs.ktor.serialization.kotlinx.json)
            implementation(libs.sqldelight.coroutines)
        }

        commonTest.dependencies {
            implementation(libs.kotlin.test)
            implementation(libs.kotlinx.coroutines.test)
        }

        jvmMain.dependencies {
            implementation(libs.ktor.client.okhttp)
            implementation(libs.sqldelight.jvm.driver)
        }

        jvmTest.dependencies {
            implementation(libs.ktor.client.mock)
        }

        androidMain.dependencies {
            implementation(libs.ktor.client.okhttp)
            implementation(libs.sqldelight.android.driver)
            implementation(libs.androidx.security.crypto)
        }

        iosMain.dependencies {
            implementation(libs.ktor.client.darwin)
            implementation(libs.sqldelight.native.driver)
        }
    }
}

// ── Android ──────────────────────────────────────────────────────
android {
    namespace = "com.vinesuite.shared"
    compileSdk = 34

    defaultConfig {
        minSdk = 26
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

// ── Kover (coverage) ─────────────────────────────────────────────
// Scope coverage to JVM only — Android target requires SDK to instrument.
// Exclude SQLDelight-generated classes and kotlinx-serialization companions
// so coverage % reflects hand-written code.
kover {
    currentProject {
        createVariant("jvmOnly") {
            add("jvm")
        }
    }
    reports {
        filters {
            excludes {
                // SQLDelight generated query wrappers, adapters, driver impls
                classes(
                    "*.database.*Queries",
                    "*.database.*Queries\$*",
                    "*.database.*Impl",
                    "*.database.*Impl\$*",
                    "*.database.*Adapter",
                    "*.database.*Adapter\$*",
                    "*.database.VineSuiteDatabase",
                    "*.database.VineSuiteDatabase\$*",
                )
                // kotlinx-serialization generated companions
                classes("*\$\$serializer", "*\$Companion")
            }
        }
    }
}

// ── SQLDelight ───────────────────────────────────────────────────
sqldelight {
    databases {
        create("VineSuiteDatabase") {
            packageName.set("com.vinesuite.shared.database")
            // Current schema is version 1. When schema changes are needed:
            // 1. Create shared/src/commonMain/sqldelight/migrations/{N}.sqm
            // 2. Bump this version number
            // SQLDelight will auto-generate migration code.
            version = 1
            verifyMigrations.set(true)
        }
    }
}
