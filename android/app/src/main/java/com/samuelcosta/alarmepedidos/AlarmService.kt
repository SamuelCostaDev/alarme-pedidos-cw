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
import android.os.IBinder
import android.os.Looper

/**
 * Toca o alarme até alguém parar (ou até 15 minutos, por segurança).
 * Roda em primeiro plano pra o sistema não matar o som no meio.
 */
class AlarmService : Service() {

    companion object {
        private const val ACTION_RING = "com.samuelcosta.alarmepedidos.RING"
        private const val ACTION_STOP = "com.samuelcosta.alarmepedidos.STOP"
        private const val EXTRA_TITLE = "title"
        private const val EXTRA_BODY = "body"

        const val CH_ALARM = "alarme_pedido"
        const val NOTIF_ALARM = 2
        private const val MAX_RING_MS = 15L * 60 * 1000

        fun ring(ctx: Context, title: String, body: String) {
            val i = Intent(ctx, AlarmService::class.java)
                .setAction(ACTION_RING)
                .putExtra(EXTRA_TITLE, title)
                .putExtra(EXTRA_BODY, body)
            ctx.startForegroundService(i)
        }

        fun stop(ctx: Context) {
            AlarmSound.stop()
            ctx.getSystemService(NotificationManager::class.java).cancel(NOTIF_ALARM)
            try {
                ctx.startService(Intent(ctx, AlarmService::class.java).setAction(ACTION_STOP))
            } catch (_: Exception) {
                // Serviço já parado
            }
        }

        fun createChannels(ctx: Context) {
            val nm = ctx.getSystemService(NotificationManager::class.java)
            nm.createNotificationChannel(
                NotificationChannel(CH_ALARM, "Pedido novo (alarme)", NotificationManager.IMPORTANCE_HIGH).apply {
                    description = "Tela cheia quando chega pedido. O som é tocado pelo próprio app."
                    setSound(null, null)
                    enableVibration(false)
                    lockscreenVisibility = Notification.VISIBILITY_PUBLIC
                }
            )
        }
    }

    private val handler = Handler(Looper.getMainLooper())
    private val autoStop = Runnable { stopAlarm() }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopAlarm()
            return START_NOT_STICKY
        }

        val title = intent?.getStringExtra(EXTRA_TITLE) ?: "Pedido novo!"
        val body = intent?.getStringExtra(EXTRA_BODY) ?: ""
        Prefs.setAlarm(this, title, body)
        Prefs.setLastAlarmAt(this, System.currentTimeMillis())

        val n = buildNotification(title, body)
        if (Build.VERSION.SDK_INT >= 29) {
            startForeground(NOTIF_ALARM, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PLAYBACK)
        } else {
            startForeground(NOTIF_ALARM, n)
        }
        AlarmSound.start(this)

        handler.removeCallbacks(autoStop)
        handler.postDelayed(autoStop, MAX_RING_MS)
        return START_NOT_STICKY
    }

    override fun onDestroy() {
        handler.removeCallbacks(autoStop)
        AlarmSound.stop()
        super.onDestroy()
    }

    private fun stopAlarm() {
        handler.removeCallbacks(autoStop)
        AlarmSound.stop()
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    private fun buildNotification(title: String, body: String): Notification {
        val fullScreen = PendingIntent.getActivity(
            this, 10,
            Intent(this, AlarmActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val stopIntent = PendingIntent.getService(
            this, 11,
            Intent(this, AlarmService::class.java).setAction(ACTION_STOP),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        return Notification.Builder(this, CH_ALARM)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle("🔔 $title")
            .setContentText(body)
            .setStyle(Notification.BigTextStyle().bigText(body))
            .setCategory(Notification.CATEGORY_ALARM)
            .setVisibility(Notification.VISIBILITY_PUBLIC)
            .setFullScreenIntent(fullScreen, true)
            .setContentIntent(fullScreen)
            .setOngoing(true)
            .addAction(Notification.Action.Builder(null, "Parar alarme", stopIntent).build())
            .build()
    }
}
