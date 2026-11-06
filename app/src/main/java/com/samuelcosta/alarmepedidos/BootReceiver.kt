package com.samuelcosta.alarmepedidos

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log

/** Religa o monitoramento depois que o celular reinicia ou o app é atualizado. */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val action = intent.action ?: return
        if (action != Intent.ACTION_BOOT_COMPLETED && action != Intent.ACTION_MY_PACKAGE_REPLACED) return
        if (!Prefs.isRunning(context) || Prefs.apiKey(context) == null) return
        try {
            PollingService.start(context)
        } catch (e: Exception) {
            Log.e("BootReceiver", "Não consegui religar o monitoramento", e)
        }
    }
}
