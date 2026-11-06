plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

fun envStr(name: String): String = "\"" + (System.getenv(name) ?: "").replace("\"", "") + "\""

android {
    namespace = "com.samuelcosta.alarmepedidos"
    compileSdk = 35

    defaultConfig {
        applicationId = "com.samuelcosta.alarmepedidos"
        minSdk = 26
        targetSdk = 35
        versionCode = (System.getenv("GITHUB_RUN_NUMBER") ?: "1").toInt()
        versionName = "2.0." + (System.getenv("GITHUB_RUN_NUMBER") ?: "0")

        // Configuração injetada pelo GitHub Actions (secrets). Vazio = configurar depois.
        buildConfigField("String", "BACKEND_URL", envStr("BACKEND_URL"))
        buildConfigField("String", "FIREBASE_PROJECT_ID", envStr("FIREBASE_PROJECT_ID"))
        buildConfigField("String", "FIREBASE_APP_ID", envStr("FIREBASE_APP_ID"))
        buildConfigField("String", "FIREBASE_API_KEY", envStr("FIREBASE_API_KEY"))
        buildConfigField("String", "FIREBASE_SENDER_ID", envStr("FIREBASE_SENDER_ID"))
    }

    buildFeatures {
        buildConfig = true
    }

    // Chave fixa vinda do secret do GitHub (KEYSTORE_B64 / KEYSTORE_PASSWORD).
    // Com ela, cada APK novo instala por cima do anterior. Sem ela, usa a chave de debug.
    val keystoreFile = file("signing.keystore")
    val keystorePassword = System.getenv("KEYSTORE_PASSWORD")
    val hasFixedKey = keystoreFile.exists() && !keystorePassword.isNullOrBlank()

    signingConfigs {
        if (hasFixedKey) {
            create("fixa") {
                storeFile = keystoreFile
                storePassword = keystorePassword
                keyAlias = "alarme"
                keyPassword = keystorePassword
            }
        }
    }

    buildTypes {
        getByName("release") {
            isMinifyEnabled = false
            signingConfig = if (hasFixedKey) signingConfigs.getByName("fixa") else signingConfigs.getByName("debug")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
}

dependencies {
    implementation(platform("com.google.firebase:firebase-bom:33.7.0"))
    implementation("com.google.firebase:firebase-messaging")
}
