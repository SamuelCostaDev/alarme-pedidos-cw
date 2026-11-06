package com.samuelcosta.alarmepedidos

import android.app.Activity
import android.app.KeyguardManager
import android.graphics.Color
import android.graphics.Typeface
import android.os.Build
import android.os.Bundle
import android.view.Gravity
import android.view.WindowManager
import android.widget.Button
import android.widget.LinearLayout
import android.widget.TextView

/** Tela cheia vermelha que aparece por cima da tela bloqueada quando chega pedido. */
class AlarmActivity : Activity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (Build.VERSION.SDK_INT >= 27) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
        } else {
            @Suppress("DEPRECATION")
            window.addFlags(
                WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or
                    WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON
            )
        }
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)

        val dp = resources.displayMetrics.density
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            setBackgroundColor(Color.parseColor("#DC2626"))
            setPadding((28 * dp).toInt(), (28 * dp).toInt(), (28 * dp).toInt(), (28 * dp).toInt())
        }
        root.addView(TextView(this).apply {
            text = "🔔"
            textSize = 72f
            gravity = Gravity.CENTER
        })
        root.addView(TextView(this).apply {
            text = "PEDIDO NOVO!"
            textSize = 34f
            setTypeface(typeface, Typeface.BOLD)
            setTextColor(Color.WHITE)
            gravity = Gravity.CENTER
        })
        root.addView(TextView(this).apply {
            text = Prefs.alarmText(this@AlarmActivity)
            textSize = 18f
            setTextColor(Color.WHITE)
            gravity = Gravity.CENTER
            setPadding(0, (16 * dp).toInt(), 0, (32 * dp).toInt())
        })
        root.addView(Button(this).apply {
            text = "Vi o pedido — parar alarme"
            textSize = 20f
            setTextColor(Color.parseColor("#DC2626"))
            setBackgroundColor(Color.WHITE)
            setPadding(0, (22 * dp).toInt(), 0, (22 * dp).toInt())
            setOnClickListener {
                PollingService.ack(this@AlarmActivity)
                // Pede pra desbloquear, pra pessoa já ir aceitar o pedido
                if (Build.VERSION.SDK_INT >= 26) {
                    getSystemService(KeyguardManager::class.java)
                        ?.requestDismissKeyguard(this@AlarmActivity, null)
                }
                finish()
            }
        }, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT))
        setContentView(root)
    }

    @Deprecated("Back fecha a tela mas o alarme continua até tocar no botão")
    override fun onBackPressed() {
        // Não deixa fechar sem parar o alarme de propósito
    }
}
