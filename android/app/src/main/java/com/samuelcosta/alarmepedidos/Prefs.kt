package com.samuelcosta.alarmepedidos

import android.content.Context
import android.content.SharedPreferences

/** Configuração e estado persistidos do app. */
object Prefs {
    private const val FILE = "alarme_pedidos"

    private fun p(ctx: Context): SharedPreferences =
        ctx.applicationContext.getSharedPreferences(FILE, Context.MODE_PRIVATE)

    /** Endereço do backend: o do build tem prioridade; senão, o que veio no QR code. */
    fun backendUrl(ctx: Context): String? {
        val fromBuild = BuildConfig.BACKEND_URL.trim().trimEnd('/')
        if (fromBuild.isNotEmpty()) return fromBuild
        return p(ctx).getString("backend_url", null)?.trim()?.trimEnd('/')?.takeIf { it.isNotEmpty() }
    }

    fun setBackendUrl(ctx: Context, url: String) = p(ctx).edit().putString("backend_url", url.trim()).apply()

    fun deviceToken(ctx: Context): String? = p(ctx).getString("device_token", null)
    fun companyName(ctx: Context): String? = p(ctx).getString("company_name", null)
    fun isPaired(ctx: Context): Boolean = deviceToken(ctx) != null

    fun savePairing(ctx: Context, deviceToken: String, companyName: String?) {
        p(ctx).edit().putString("device_token", deviceToken).putString("company_name", companyName).apply()
    }

    fun clearPairing(ctx: Context) {
        p(ctx).edit().remove("device_token").remove("company_name").apply()
    }

    fun fcmToken(ctx: Context): String? = p(ctx).getString("fcm_token", null)
    fun setFcmToken(ctx: Context, v: String) = p(ctx).edit().putString("fcm_token", v).apply()

    fun alarmTitle(ctx: Context): String = p(ctx).getString("alarm_title", "Pedido novo!") ?: ""
    fun alarmText(ctx: Context): String = p(ctx).getString("alarm_text", "") ?: ""
    fun setAlarm(ctx: Context, title: String, text: String) =
        p(ctx).edit().putString("alarm_title", title).putString("alarm_text", text).apply()

    fun lastAlarmAt(ctx: Context): Long = p(ctx).getLong("last_alarm_at", 0L)
    fun setLastAlarmAt(ctx: Context, v: Long) = p(ctx).edit().putLong("last_alarm_at", v).apply()
}
