package com.samuelcosta.alarmepedidos

import android.content.Context
import android.os.Build
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

/** Cliente da API do backend (Laravel). Chamar sempre fora da thread principal. */
object BackendApi {

    class ApiException(val httpCode: Int, message: String) : Exception(message)

    data class PairResult(val deviceToken: String, val companyName: String?)

    fun pair(ctx: Context, code: String, fcmToken: String): PairResult {
        val body = JSONObject()
            .put("code", code)
            .put("fcm_token", fcmToken)
            .put("device_name", "${Build.MANUFACTURER} ${Build.MODEL}".trim())
            .put("platform", "android")
        val json = request(ctx, "POST", "/api/devices/pair", body, auth = false)
        return PairResult(
            deviceToken = json.getString("device_token"),
            companyName = json.optString("company_name").ifBlank { null },
        )
    }

    fun me(ctx: Context): JSONObject = request(ctx, "GET", "/api/devices/me", null)

    fun updateFcmToken(ctx: Context, fcmToken: String) {
        request(ctx, "PUT", "/api/devices/me/fcm-token", JSONObject().put("fcm_token", fcmToken))
    }

    fun requestTestAlarm(ctx: Context) {
        request(ctx, "POST", "/api/devices/me/test", JSONObject())
    }

    fun unpair(ctx: Context) {
        request(ctx, "DELETE", "/api/devices/me", null)
    }

    private fun request(ctx: Context, method: String, path: String, body: JSONObject?, auth: Boolean = true): JSONObject {
        val base = Prefs.backendUrl(ctx) ?: throw ApiException(0, "Endereço do servidor não configurado.")
        val conn = URL(base + path).openConnection() as HttpURLConnection
        try {
            conn.requestMethod = method
            conn.connectTimeout = 15_000
            conn.readTimeout = 20_000
            conn.setRequestProperty("Accept", "application/json")
            if (auth) {
                val token = Prefs.deviceToken(ctx) ?: throw ApiException(401, "Celular não pareado.")
                conn.setRequestProperty("Authorization", "Bearer $token")
            }
            if (body != null) {
                conn.doOutput = true
                conn.setRequestProperty("Content-Type", "application/json")
                conn.outputStream.use { it.write(body.toString().toByteArray()) }
            }
            val code = conn.responseCode
            val stream = if (code in 200..299) conn.inputStream else conn.errorStream
            val text = stream?.bufferedReader()?.use { it.readText() } ?: ""
            val json = try {
                if (text.isBlank()) JSONObject() else JSONObject(text)
            } catch (_: Exception) {
                JSONObject()
            }
            if (code !in 200..299) {
                val msg = json.optString("message").ifBlank { json.optString("error").ifBlank { "Erro $code no servidor." } }
                throw ApiException(code, msg)
            }
            return json
        } finally {
            conn.disconnect()
        }
    }
}
