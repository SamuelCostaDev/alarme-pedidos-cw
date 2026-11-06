package com.samuelcosta.alarmepedidos

import android.app.Application
import android.util.Log
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions

class App : Application() {

    override fun onCreate() {
        super.onCreate()
        AlarmService.createChannels(this)
        initFirebase()
    }

    private fun initFirebase() {
        if (!firebaseConfigured) return
        if (FirebaseApp.getApps(this).isNotEmpty()) return
        try {
            val options = FirebaseOptions.Builder()
                .setProjectId(BuildConfig.FIREBASE_PROJECT_ID)
                .setApplicationId(BuildConfig.FIREBASE_APP_ID)
                .setApiKey(BuildConfig.FIREBASE_API_KEY)
                .setGcmSenderId(BuildConfig.FIREBASE_SENDER_ID)
                .build()
            FirebaseApp.initializeApp(this, options)
        } catch (e: Exception) {
            Log.e("App", "Falha ao iniciar o Firebase", e)
        }
    }

    companion object {
        /** O APK foi gerado com a configuração do Firebase? */
        val firebaseConfigured: Boolean
            get() = BuildConfig.FIREBASE_PROJECT_ID.isNotBlank() &&
                BuildConfig.FIREBASE_APP_ID.isNotBlank() &&
                BuildConfig.FIREBASE_API_KEY.isNotBlank() &&
                BuildConfig.FIREBASE_SENDER_ID.isNotBlank()
    }
}
