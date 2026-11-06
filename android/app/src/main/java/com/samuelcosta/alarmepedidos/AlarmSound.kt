package com.samuelcosta.alarmepedidos

import android.content.Context
import android.media.AudioAttributes
import android.media.AudioManager
import android.media.MediaPlayer
import android.media.RingtoneManager
import android.os.Build
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import android.util.Log

/**
 * Toca o som de alarme do sistema no canal de ALARME (toca mesmo no silencioso,
 * igual despertador) e vibra até alguém parar.
 */
object AlarmSound {
    private const val TAG = "AlarmSound"
    private var player: MediaPlayer? = null
    private var vibrator: Vibrator? = null

    @get:Synchronized
    val isPlaying: Boolean
        get() = player != null

    @Synchronized
    fun start(context: Context) {
        if (player != null) return
        val ctx = context.applicationContext

        // Garante volume alto no canal de alarme
        try {
            val am = ctx.getSystemService(Context.AUDIO_SERVICE) as AudioManager
            val max = am.getStreamMaxVolume(AudioManager.STREAM_ALARM)
            am.setStreamVolume(AudioManager.STREAM_ALARM, max, 0)
        } catch (e: Exception) {
            Log.w(TAG, "Não consegui ajustar o volume", e)
        }

        val uri = RingtoneManager.getActualDefaultRingtoneUri(ctx, RingtoneManager.TYPE_ALARM)
            ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM)
            ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE)

        try {
            player = MediaPlayer().apply {
                setAudioAttributes(
                    AudioAttributes.Builder()
                        .setUsage(AudioAttributes.USAGE_ALARM)
                        .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                        .build()
                )
                setDataSource(ctx, uri)
                isLooping = true
                prepare()
                start()
            }
        } catch (e: Exception) {
            Log.e(TAG, "Falha ao tocar alarme", e)
            player?.release()
            player = null
        }

        try {
            vibrator = if (Build.VERSION.SDK_INT >= 31) {
                (ctx.getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as VibratorManager).defaultVibrator
            } else {
                @Suppress("DEPRECATION")
                ctx.getSystemService(Context.VIBRATOR_SERVICE) as Vibrator
            }
            val pattern = longArrayOf(0, 800, 400, 800, 400)
            vibrator?.vibrate(VibrationEffect.createWaveform(pattern, 0))
        } catch (e: Exception) {
            Log.w(TAG, "Sem vibração", e)
        }
    }

    @Synchronized
    fun stop() {
        try {
            player?.stop()
        } catch (_: Exception) {
        }
        player?.release()
        player = null
        try {
            vibrator?.cancel()
        } catch (_: Exception) {
        }
        vibrator = null
    }
}
