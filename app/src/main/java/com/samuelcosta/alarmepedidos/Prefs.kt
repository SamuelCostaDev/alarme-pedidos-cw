package com.samuelcosta.alarmepedidos

import android.content.Context
import android.content.SharedPreferences

/** Configuração e estado persistidos do app. */
object Prefs {
    private const val FILE = "alarme_pedidos"
    private const val MAX_SEEN = 500

    private fun p(ctx: Context): SharedPreferences =
        ctx.applicationContext.getSharedPreferences(FILE, Context.MODE_PRIVATE)

    fun apiKey(ctx: Context): String? = p(ctx).getString("api_key", null)?.takeIf { it.isNotBlank() }
    fun setApiKey(ctx: Context, key: String) {
        // Token novo = começa do zero (outra loja, outros pedidos)
        p(ctx).edit()
            .putString("api_key", key.trim())
            .remove("seen_ids")
            .remove("last_success")
            .putBoolean("first_run", true)
            .apply()
    }

    fun sandbox(ctx: Context): Boolean = p(ctx).getBoolean("sandbox", false)
    fun setSandbox(ctx: Context, v: Boolean) = p(ctx).edit().putBoolean("sandbox", v).apply()

    fun baseUrl(ctx: Context): String =
        if (sandbox(ctx)) "https://integracao.sandbox.cardapioweb.com" else "https://integracao.cardapioweb.com"

    fun isRunning(ctx: Context): Boolean = p(ctx).getBoolean("running", false)
    fun setRunning(ctx: Context, v: Boolean) = p(ctx).edit().putBoolean("running", v).apply()

    fun firstRun(ctx: Context): Boolean = p(ctx).getBoolean("first_run", true)
    fun setFirstRun(ctx: Context, v: Boolean) = p(ctx).edit().putBoolean("first_run", v).apply()

    fun lastSuccess(ctx: Context): Long = p(ctx).getLong("last_success", 0L)
    fun setLastSuccess(ctx: Context, v: Long) = p(ctx).edit().putLong("last_success", v).apply()

    fun offlineWarned(ctx: Context): Boolean = p(ctx).getBoolean("offline_warned", false)
    fun setOfflineWarned(ctx: Context, v: Boolean) = p(ctx).edit().putBoolean("offline_warned", v).apply()

    fun status(ctx: Context): String = p(ctx).getString("status", "Monitoramento desligado") ?: ""
    fun setStatus(ctx: Context, v: String) = p(ctx).edit().putString("status", v).apply()

    fun seenIds(ctx: Context): LinkedHashSet<Long> {
        val raw = p(ctx).getString("seen_ids", "") ?: ""
        return raw.split(',').mapNotNullTo(LinkedHashSet()) { it.toLongOrNull() }
    }

    fun setSeenIds(ctx: Context, ids: LinkedHashSet<Long>) {
        val trimmed = if (ids.size > MAX_SEEN) ids.toList().takeLast(MAX_SEEN) else ids.toList()
        p(ctx).edit().putString("seen_ids", trimmed.joinToString(",")).apply()
    }

    fun alarmText(ctx: Context): String = p(ctx).getString("alarm_text", "Chegou pedido novo!") ?: ""
    fun setAlarmText(ctx: Context, v: String) = p(ctx).edit().putString("alarm_text", v).apply()
}
