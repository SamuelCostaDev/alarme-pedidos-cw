package com.samuelcosta.alarmepedidos

import android.util.Log
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

/**
 * Recebe o push do backend. Mensagens de prioridade alta acordam o app mesmo com a tela
 * bloqueada e permitem iniciar o serviço do alarme.
 */
class AlarmMessagingService : FirebaseMessagingService() {

    override fun onMessageReceived(message: RemoteMessage) {
        val data = message.data
        val type = data["type"] ?: return
        if (type != "order" && type != "test") return
        if (!Prefs.isPaired(this)) return

        val title = data["title"]?.ifBlank { null } ?: "Pedido novo!"
        val body = data["body"]?.ifBlank { null } ?: "Chegou pedido na loja."
        try {
            AlarmService.ring(this, title, body)
        } catch (e: Exception) {
            Log.e(TAG, "Não consegui iniciar o alarme", e)
        }
    }

    override fun onNewToken(token: String) {
        Prefs.setFcmToken(this, token)
        if (!Prefs.isPaired(this)) return
        Thread {
            try {
                BackendApi.updateFcmToken(this, token)
            } catch (e: Exception) {
                Log.w(TAG, "Não consegui atualizar o token no servidor", e)
            }
        }.start()
    }

    companion object {
        private const val TAG = "AlarmMessaging"
    }
}
