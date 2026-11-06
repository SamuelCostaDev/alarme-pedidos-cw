package com.samuelcosta.alarmepedidos

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.app.NotificationManager
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Color
import android.graphics.Typeface
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.PowerManager
import android.provider.Settings
import android.text.InputType
import android.view.View
import android.widget.Button
import android.widget.CheckBox
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast

class MainActivity : Activity() {

    private lateinit var statusText: TextView
    private lateinit var tokenInfo: TextView
    private lateinit var tokenInput: EditText
    private lateinit var toggleBtn: Button
    private lateinit var checks: TextView
    private lateinit var batteryBtn: Button
    private lateinit var fullScreenBtn: Button
    private val ui = Handler(Looper.getMainLooper())
    private val refresher = object : Runnable {
        override fun run() {
            refresh()
            ui.postDelayed(this, 2000)
        }
    }

    private val dp get() = resources.displayMetrics.density

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        PollingService.createChannels(this)
        setContentView(buildUi())

        if (Build.VERSION.SDK_INT >= 33 &&
            checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            requestPermissions(arrayOf(Manifest.permission.POST_NOTIFICATIONS), 1)
        }
    }

    override fun onResume() {
        super.onResume()
        ui.post(refresher)
    }

    override fun onPause() {
        super.onPause()
        ui.removeCallbacks(refresher)
    }

    // ---------------------------------------------------------------------

    private fun buildUi(): View {
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(px(20), px(28), px(20), px(28))
        }

        root.addView(TextView(this).apply {
            text = "Alarme de Pedidos"
            textSize = 26f
            setTypeface(typeface, Typeface.BOLD)
        })
        root.addView(TextView(this).apply {
            text = "Toca um alarme quando chega pedido no Cardápio Web — mesmo com a tela bloqueada."
            textSize = 14f
            setTextColor(Color.parseColor("#6B7280"))
            setPadding(0, px(4), 0, px(20))
        })

        statusText = TextView(this).apply {
            textSize = 16f
            setPadding(px(14), px(14), px(14), px(14))
            setBackgroundColor(Color.parseColor("#F3F4F6"))
        }
        root.addView(statusText, matchWrap())

        toggleBtn = bigButton("") { toggle() }
        root.addView(toggleBtn, matchWrap(top = 12))

        root.addView(button("Testar alarme") { testAlarm() }, matchWrap(top = 8))

        // --- Token ---
        root.addView(section("Token da loja"))
        tokenInfo = TextView(this).apply {
            textSize = 14f
            setTextColor(Color.parseColor("#374151"))
        }
        root.addView(tokenInfo)
        root.addView(TextView(this).apply {
            text = "Gere no Portal: Configurações → Integrações → API (token X-API-KEY)."
            textSize = 13f
            setTextColor(Color.parseColor("#6B7280"))
            setPadding(0, px(2), 0, px(8))
        })
        tokenInput = EditText(this).apply {
            hint = "Cole o token aqui"
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
            isSingleLine = true
        }
        root.addView(tokenInput, matchWrap())
        root.addView(button("Salvar token") { saveToken() }, matchWrap(top = 4))
        root.addView(CheckBox(this).apply {
            text = "Usar ambiente sandbox (testes)"
            isChecked = Prefs.sandbox(this@MainActivity)
            setOnCheckedChangeListener { _, checked ->
                Prefs.setSandbox(this@MainActivity, checked)
                Prefs.setApiKey(this@MainActivity, Prefs.apiKey(this@MainActivity) ?: "")
            }
        }, matchWrap(top = 4))

        // --- Permissões ---
        root.addView(section("Pra funcionar com a tela bloqueada"))
        checks = TextView(this).apply {
            textSize = 14f
            setLineSpacing(0f, 1.3f)
        }
        root.addView(checks)
        batteryBtn = button("Liberar uso de bateria") { askBattery() }
        root.addView(batteryBtn, matchWrap(top = 8))
        fullScreenBtn = button("Permitir alarme em tela cheia") { askFullScreen() }
        root.addView(fullScreenBtn, matchWrap(top = 8))
        root.addView(button("Configurações de notificação") { openNotificationSettings() }, matchWrap(top = 8))

        root.addView(TextView(this).apply {
            text = "Dica: alguns celulares (Xiaomi, Samsung, Motorola) têm economia de bateria própria. " +
                "Se o alarme parar de tocar com a tela bloqueada, procure o app em Configurações → Bateria " +
                "e marque “Sem restrições” / “Inicialização automática”."
            textSize = 13f
            setTextColor(Color.parseColor("#6B7280"))
            setPadding(0, px(16), 0, 0)
        })

        return ScrollView(this).apply { addView(root) }
    }

    @SuppressLint("SetTextI18n")
    private fun refresh() {
        val running = Prefs.isRunning(this)
        val key = Prefs.apiKey(this)
        statusText.text = (if (running) "🟢 " else "⚪ ") + Prefs.status(this)
        toggleBtn.text = if (running) "Desligar monitoramento" else "Ligar monitoramento"
        toggleBtn.setBackgroundColor(Color.parseColor(if (running) "#6B7280" else "#2563EB"))
        tokenInfo.text = if (key == null) "Nenhum token salvo." else "Token salvo: ••••" + key.takeLast(4) +
            if (Prefs.sandbox(this)) " (sandbox)" else ""

        val notifOk = Build.VERSION.SDK_INT < 33 ||
            checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED
        val batteryOk = getSystemService(PowerManager::class.java).isIgnoringBatteryOptimizations(packageName)
        val fullScreenOk = Build.VERSION.SDK_INT < 34 ||
            getSystemService(NotificationManager::class.java).canUseFullScreenIntent()

        checks.text = listOf(
            mark(notifOk) + " Notificações permitidas",
            mark(batteryOk) + " Sem restrição de bateria",
            mark(fullScreenOk) + " Alarme em tela cheia",
        ).joinToString("\n")
        batteryBtn.visibility = if (batteryOk) View.GONE else View.VISIBLE
        fullScreenBtn.visibility = if (fullScreenOk) View.GONE else View.VISIBLE
    }

    private fun toggle() {
        if (Prefs.isRunning(this)) {
            PollingService.stop(this)
        } else {
            if (Prefs.apiKey(this) == null) {
                toast("Salve o token da loja primeiro.")
                return
            }
            PollingService.start(this)
        }
        refresh()
    }

    private fun saveToken() {
        val t = tokenInput.text.toString().trim()
        if (t.length < 8) {
            toast("Esse token parece curto demais.")
            return
        }
        Prefs.setApiKey(this, t)
        tokenInput.setText("")
        toast("Token salvo.")
        if (Prefs.isRunning(this)) PollingService.start(this)
        refresh()
    }

    private fun testAlarm() {
        Prefs.setAlarmText(this, "Isto é um teste.\nSe você está ouvindo, o alarme funciona.")
        AlarmSound.start(this)
        startActivity(Intent(this, AlarmActivity::class.java))
    }

    @SuppressLint("BatteryLife")
    private fun askBattery() {
        try {
            startActivity(
                Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:$packageName"))
            )
        } catch (_: Exception) {
            startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
        }
    }

    private fun askFullScreen() {
        if (Build.VERSION.SDK_INT >= 34) {
            try {
                startActivity(
                    Intent(Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT, Uri.parse("package:$packageName"))
                )
            } catch (_: Exception) {
                openNotificationSettings()
            }
        }
    }

    private fun openNotificationSettings() {
        startActivity(
            Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                .putExtra(Settings.EXTRA_APP_PACKAGE, packageName)
        )
    }

    // ---- helpers de UI ----
    private fun px(v: Int) = (v * dp).toInt()
    private fun mark(ok: Boolean) = if (ok) "✅" else "⚠️"
    private fun toast(s: String) = Toast.makeText(this, s, Toast.LENGTH_LONG).show()

    private fun matchWrap(top: Int = 0) = LinearLayout.LayoutParams(
        LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT,
    ).apply { topMargin = px(top) }

    private fun section(title: String) = TextView(this).apply {
        text = title.uppercase()
        textSize = 13f
        setTypeface(typeface, Typeface.BOLD)
        setTextColor(Color.parseColor("#6B7280"))
        setPadding(0, px(28), 0, px(8))
    }

    private fun button(label: String, onClick: () -> Unit) = Button(this).apply {
        text = label
        isAllCaps = false
        setOnClickListener { onClick() }
    }

    private fun bigButton(label: String, onClick: () -> Unit) = Button(this).apply {
        text = label
        isAllCaps = false
        textSize = 18f
        setTextColor(Color.WHITE)
        setPadding(0, px(18), 0, px(18))
        setOnClickListener { onClick() }
    }
}
