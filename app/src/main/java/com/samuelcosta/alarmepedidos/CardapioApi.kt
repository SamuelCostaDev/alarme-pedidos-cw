package com.samuelcosta.alarmepedidos

import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder
import java.time.Instant
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.temporal.ChronoUnit

/**
 * Cliente mínimo da API do Cardápio Web.
 * GET /api/partner/v1/orders (polling) — https://docs.cardapioweb.com/api-reference/pedidos/polling-de-pedidos
 */
object CardapioApi {

    class ApiException(val httpCode: Int, message: String) : Exception(message)

    data class Order(
        val id: Long,
        val status: String,
        val orderType: String?,
        val salesChannel: String?,
        val createdAt: String?,
    )

    fun fetchOrders(baseUrl: String, apiKey: String, updatedSinceMs: Long?): List<Order> {
        val sb = StringBuilder("$baseUrl/api/partner/v1/orders")
        if (updatedSinceMs != null) {
            val iso = OffsetDateTime.ofInstant(Instant.ofEpochMilli(updatedSinceMs), ZoneId.systemDefault())
                .truncatedTo(ChronoUnit.SECONDS)
                .format(DateTimeFormatter.ISO_OFFSET_DATE_TIME)
            sb.append("?updated_since=").append(URLEncoder.encode(iso, "UTF-8"))
        }

        val conn = URL(sb.toString()).openConnection() as HttpURLConnection
        try {
            conn.requestMethod = "GET"
            conn.connectTimeout = 15_000
            conn.readTimeout = 20_000
            conn.setRequestProperty("X-API-KEY", apiKey)
            conn.setRequestProperty("Accept", "application/json")
            conn.setRequestProperty("User-Agent", "AlarmePedidosCW/1.0 (Android)")

            val code = conn.responseCode
            val stream = if (code in 200..299) conn.inputStream else conn.errorStream
            val body = stream?.bufferedReader()?.use { it.readText() } ?: ""
            if (code !in 200..299) {
                throw ApiException(code, body.take(300))
            }
            return parse(body)
        } finally {
            conn.disconnect()
        }
    }

    private fun parse(body: String): List<Order> {
        val trimmed = body.trim()
        val arr: JSONArray = when {
            trimmed.startsWith("[") -> JSONArray(trimmed)
            trimmed.startsWith("{") -> {
                val obj = JSONObject(trimmed)
                obj.optJSONArray("orders") ?: obj.optJSONArray("data") ?: JSONArray()
            }
            else -> JSONArray()
        }
        val out = ArrayList<Order>(arr.length())
        for (i in 0 until arr.length()) {
            val o = arr.optJSONObject(i) ?: continue
            val id = o.optLong("id", -1L)
            if (id < 0) continue
            out += Order(
                id = id,
                status = o.optString("status", ""),
                orderType = o.optString("order_type").ifBlank { null },
                salesChannel = o.optString("sales_channel").ifBlank { null },
                createdAt = o.optString("created_at").ifBlank { null },
            )
        }
        return out
    }

    private val TYPE_PT = mapOf(
        "delivery" to "Delivery", "takeout" to "Retirada", "onsite" to "No local", "closed_table" to "Mesa",
    )
    private val CHANNEL_PT = mapOf(
        "catalog" to "Cardápio digital", "store_front_catalog" to "Cardápio", "table_catalog" to "Mesa",
        "portal" to "Portal", "integration" to "Integração", "ifood" to "iFood",
        "whatsapp_extension" to "WhatsApp", "food99" to "99Food", "aiqfome" to "aiqfome",
        "keeta" to "Keeta", "totem" to "Totem",
    )

    fun describe(o: Order): String {
        val parts = listOfNotNull(
            "Pedido #${o.id}",
            o.orderType?.let { TYPE_PT[it] ?: it },
            o.salesChannel?.let { CHANNEL_PT[it] ?: it },
        )
        return parts.joinToString(" · ")
    }
}
