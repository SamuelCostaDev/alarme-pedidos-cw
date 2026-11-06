package com.samuelcosta.alarmepedidos

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.Handler
import android.os.HandlerThread
import android.os.IBinder
import android.os.PowerManager
import android.util.Log
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * Serviço em primeiro plano: consulta a API a cada 30 s (recomendação da documentação),
 * mesmo com a tela bloqueada, e dispara o alarme quando aparece pedido novo.
 */
class PollingService : Service() {

    companion object {
        private const val TAG = "PollingService"
        const val ACTION_START = "com.samuelcosta.alarmepedidos.START"
        const val ACTION_STOP = "com.samuelcosta.alarmepedidos.STOP"
        const val ACTION_ACK = "com.samuelcosta.alarmepedidos.ACK"

        const val CH_STATUS = "status"
        const val CH_ALARM = "alarme_pedido"
        const val CH_WARN = "avisos"
        const val NOTIF_STATUS = 1
        const val NOTIF_ALARM = 2
        const val NOTIF_WARN = 3

        private const val INTERVAL_MS = 30_000L
        private const val CLOCK_MARGIN_MS = 60_000L
        private const val MAX_SINCE_MS = 23L * 60 * 60 * 1000
        private const val OFFLINE_WARN_MS = 10L * 60 * 1000

        /** Status que não interessam pro alarme (pedido já resolvido). */
        private val IGNORE_STATUS = setOf("closed", "canceled", "canceling", "delivered")

        fun start(ctx: Context) {
            Prefs.setRunning(ctx, true)
            val i = Intent(ctx, PollingService::class.java).setAction(ACTION_START)
            ctx.startForegroundService(i)
        }

        fun stop(ctx: Context) {
            Prefs.setRunning(ctx, false)
            AlarmSound.stop()
            ctx.startService(Intent(ctx, PollingService::class.java).setAction(ACTION_STOP))
        }

        fun ack(ctx: Context) {
            AlarmSound.stop()
            val nm = ctx.getSystemService(NotificationManager::class.java)
            nm.cancel(NOTIF_ALARM)
            if (Prefs.isRunning(ctx)) {
                try {
                    ctx.startService(Intent(ctx, PollingService::class.java).setAction(ACTION_ACK))
                } catch (_: Exception) {
                }
            }
        }

        fun createChannels(ctx: Context) {
            val nm = ctx.getSystemService(NotificationManager::class.java)
            nm.createNotificationChannel(
                NotificationChannel(CH_STATUS, "Monitoramento", NotificationManager.IMPORTANCE_LOW).apply {
                    description = "Aviso fixo enquanto o app vigia os pedidos"
                    setShowBadge(false)
                }
            )
            nm.createNotificationChannel(
                NotificationChannel(CH_ALARM, "Pedido novo (alarme)", NotificationManager.IMPORTANCE_HIGH).apply {
                    description = "Tela cheia quando chega pedido. O som é tocado pelo próprio app."
                    setSound(null, null)
                    enableVibration(false)
                    lockscreenVisibility = Notification.VISIBILITY_PUBLIC
                }
            )
            nm.createNotificationChannel(
                NotificationChannel(CH_WARN, "Problemas de conexão", NotificationManager.IMPORTANCE_HIGH).apply {
                    description = "Avisa quando o app não consegue falar com o Cardápio Web"
                }
            )
        }
    }

    private lateinit var thread: HandlerThread
    private lateinit var handler: Handler
    private var wakeLock: PowerManager.WakeLock? = null
    private var loopActive = false

    private val pollRunnable = object : Runnable {
        override fun run() {
            try {
                pollOnce()
            } catch (t: Throwable) {
                Log.e(TAG, "Erro inesperado no polling", t)
                setStatus("Erro inesperado: ${t.message ?: t.javaClass.simpleName}")
            }
            if (loopActive) handler.postDelayed(this, INTERVAL_MS)
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        createChannels(this)
        thread = HandlerThread("cw-polling").apply { start() }
        handler = Handler(thread.looper)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_STOP -> {
                shutdown()
                return START_NOT_STICKY
            }
            ACTION_ACK -> {
                AlarmSound.stop()
                getSystemService(NotificationManager::class.java).cancel(NOTIF_ALARM)
                return START_STICKY
            }
            else -> {
                // ACTION_START ou reinício pelo sistema (intent nulo)
                if (!Prefs.isRunning(this) || Prefs.apiKey(this) == null) {
                    goForeground("Parado")
                    shutdown()
                    return START_NOT_STICKY
                }
                goForeground("Iniciando…")
                acquireWakeLock()
                if (!loopActive) {
                    loopActive = true
                    handler.post(pollRunnable)
                }
                return START_STICKY
            }
        }
    }

    override fun onDestroy() {
        loopActive = false
        handler.removeCallbacksAndMessages(null)
        thread.quitSafely()
        releaseWakeLock()
        super.onDestroy()
    }

    // ---------------------------------------------------------------------

    private fun pollOnce() {
        val key = Prefs.apiKey(this) ?: run {
            setStatus("Sem token configurado")
            return
        }
        val now = System.currentTimeMillis()
        val last = Prefs.lastSuccess(this)
        val since = if (last > 0 && now - last < MAX_SINCE_MS) last - CLOCK_MARGIN_MS else null

        val orders = try {
            CardapioApi.fetchOrders(Prefs.baseUrl(this), key, since)
        } catch (e: CardapioApi.ApiException) {
            val msg = when (e.httpCode) {
                401, 403 -> "Token recusado (${e.httpCode}). Confira o token no app."
                429 -> "Muitas consultas (429). Tentando de novo…"
                in 500..599 -> "Cardápio Web fora do ar (${e.httpCode}). Tentando de novo…"
                else -> "Erro ${e.httpCode} na API. Tentando de novo…"
            }
            setStatus(msg)
            checkOffline(now, last)
            return
        } catch (e: Exception) {
            setStatus("Sem conexão com a internet. Tentando de novo…")
            checkOffline(now, last)
            return
        }

        val firstRun = Prefs.firstRun(this)
        val seen = Prefs.seenIds(this)
        val fresh = orders.filter { o ->
            o.id !in seen && o.status !in IGNORE_STATUS &&
                (!firstRun || o.status == "waiting_confirmation")
        }
        orders.forEach { seen.add(it.id) }
        Prefs.setSeenIds(this, seen)
        Prefs.setFirstRun(this, false)
        Prefs.setLastSuccess(this, now)
        if (Prefs.offlineWarned(this)) {
            Prefs.setOfflineWarned(this, false)
            getSystemService(NotificationManager::class.java).cancel(NOTIF_WARN)
        }

        setStatus("Vigiando pedidos · última verificação ${hhmmss(now)}")

        if (fresh.isNotEmpty()) {
            val text = if (fresh.size == 1) {
                "Chegou 1 pedido novo!\n" + CardapioApi.describe(fresh[0])
            } else {
                "Chegaram ${fresh.size} pedidos novos!\n" + fresh.joinToString("\n") { CardapioApi.describe(it) }
            }
            fireAlarm(text)
        }
    }

    private fun checkOffline(now: Long, last: Long) {
        if (last == 0L || now - last < OFFLINE_WARN_MS || Prefs.offlineWarned(this)) return
        Prefs.setOfflineWarned(this, true)
        val n = Notification.Builder(this, CH_WARN)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle("Alarme de pedidos sem conexão")
            .setContentText("Há mais de 10 minutos sem falar com o Cardápio Web. Pedidos podem não tocar.")
            .setContentIntent(openAppIntent())
            .setAutoCancel(true)
            .build()
        getSystemService(NotificationManager::class.java).notify(NOTIF_WARN, n)
    }

    private fun fireAlarm(text: String) {
        Prefs.setAlarmText(this, text)
        AlarmSound.start(this)

        val fullScreen = PendingIntent.getActivity(
            this, 10,
            Intent(this, AlarmActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val ackIntent = PendingIntent.getService(
            this, 11,
            Intent(this, PollingService::class.java).setAction(ACTION_ACK),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val n = Notification.Builder(this, CH_ALARM)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle("🔔 Pedido novo!")
            .setContentText(text.substringAfter('\n'))
            .setStyle(Notification.BigTextStyle().bigText(text))
            .setCategory(Notification.CATEGORY_ALARM)
            .setVisibility(Notification.VISIBILITY_PUBLIC)
            .setFullScreenIntent(fullScreen, true)
            .setContentIntent(fullScreen)
            .setOngoing(true)
            .addAction(Notification.Action.Builder(null, "Parar alarme", ackIntent).build())
            .build()
        getSystemService(NotificationManager::class.java).notify(NOTIF_ALARM, n)
    }

    private fun goForeground(text: String) {
        val n = statusNotification(text)
        if (Build.VERSION.SDK_INT >= 34) {
            startForeground(NOTIF_STATUS, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE)
        } else {
            startForeground(NOTIF_STATUS, n)
        }
    }

    private fun setStatus(text: String) {
        Prefs.setStatus(this, text)
        if (loopActive) {
            getSystemService(NotificationManager::class.java).notify(NOTIF_STATUS, statusNotification(text))
        }
    }

    private fun statusNotification(text: String): Notification =
        Notification.Builder(this, CH_STATUS)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle("Alarme de pedidos ativo")
            .setContentText(text)
            .setContentIntent(openAppIntent())
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .build()

    private fun openAppIntent(): PendingIntent = PendingIntent.getActivity(
        this, 0,
        Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP),
        PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
    )

    private fun shutdown() {
        loopActive = false
        handler.removeCallbacksAndMessages(null)
        AlarmSound.stop()
        getSystemService(NotificationManager::class.java).cancel(NOTIF_ALARM)
        Prefs.setStatus(this, "Monitoramento desligado")
        releaseWakeLock()
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    private fun acquireWakeLock() {
        if (wakeLock?.isHeld == true) return
        val pm = getSystemService(PowerManager::class.java)
        wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "AlarmePedidos::polling").apply {
            setReferenceCounted(false)
            acquire()
        }
    }

    private fun releaseWakeLock() {
        try {
            if (wakeLock?.isHeld == true) wakeLock?.release()
        } catch (_: Exception) {
        }
        wakeLock = null
    }

    private fun hhmmss(ms: Long): String =
        SimpleDateFormat("HH:mm:ss", Locale("pt", "BR")).format(Date(ms))
}
