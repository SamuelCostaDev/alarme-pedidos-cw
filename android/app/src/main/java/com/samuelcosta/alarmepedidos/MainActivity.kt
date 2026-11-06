package com.samuelcosta.alarmepedidos

import android.Manifest
import android.app.Activity
import android.app.NotificationManager
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Color
import android.graphics.Typeface
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.text.InputType
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import com.google.firebase.messaging.FirebaseMessaging
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class MainActivity : Activity() {

    private val brand = Color.parseColor("#A543FA")
    private val muted = Color.parseColor("#6B7280")
    private val dp get() = resources.displayMetrics.density

    private lateinit var root: LinearLayout
    private var busy = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(px(20), px(28), px(20), px(28))
        }
        setContentView(ScrollView(this).apply { addView(root) })

        if (Build.VERSION.SDK_INT >= 33 &&
            checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            requestPermissions(arrayOf(Manifest.permission.POST_NOTIFICATIONS), 1)
        }
        handleDeepLink(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        handleDeepLink(intent)
    }

    override fun onResume() {
        super.onResume()
        render()
        if (Prefs.isPaired(this)) syncWithServer()
    }

    // ------------------------------------------------------------------ telas

    private fun render(pendingCode: String? = null) {
        root.removeAllViews()
        header()
        if (!App.firebaseConfigured) {
            warning("Este APK foi gerado sem a configuração do Firebase, então não recebe alarmes. " +
                "Gere de novo com os secrets do Firebase no GitHub.")
        }
        if (Prefs.isPaired(this)) renderPaired() else renderPairing(pendingCode)
    }

    private fun renderPairing(pendingCode: String?) {
        section("Parear com a sua loja")
        text("1. No Portal da Cardápio Web, abra CW App Store → Alarme de Pedidos → Acessar.\n" +
            "2. Toque em “Gerar código de pareamento”.\n" +
            "3. Digite o código aqui — ou aponte a câmera do celular pro QR code.")

        var serverInput: EditText? = null
        if (BuildConfig.BACKEND_URL.isBlank()) {
            serverInput = EditText(this).apply {
                hint = "Endereço do servidor (https://...)"
                inputType = InputType.TYPE_TEXT_VARIATION_URI
                setText(Prefs.backendUrl(this@MainActivity) ?: "")
                isSingleLine = true
            }
            root.addView(serverInput, matchWrap(top = 12))
        }

        val codeInput = EditText(this).apply {
            hint = "Código de 6 dígitos"
            inputType = InputType.TYPE_CLASS_NUMBER
            textSize = 24f
            letterSpacing = 0.3f
            isSingleLine = true
            setText(pendingCode ?: "")
        }
        root.addView(codeInput, matchWrap(top = 12))

        root.addView(bigButton(if (busy) "Pareando…" else "Parear") {
            serverInput?.text?.toString()?.trim()?.takeIf { it.isNotEmpty() }?.let { Prefs.setBackendUrl(this, it) }
            pair(codeInput.text.toString())
        }, matchWrap(top = 12))

        section("Teste do som")
        root.addView(button("Tocar alarme aqui (sem servidor)") { localTest() }, matchWrap())
        permissionsBlock()
    }

    private fun renderPaired() {
        section("Pareado")
        root.addView(TextView(this).apply {
            text = Prefs.companyName(this@MainActivity) ?: "Sua loja"
            textSize = 22f
            setTypeface(typeface, Typeface.BOLD)
        })
        val last = Prefs.lastAlarmAt(this)
        text(
            "Os pedidos novos desta loja tocam neste celular, mesmo com a tela bloqueada.\n" +
                "Último alarme: " + (if (last == 0L) "nenhum ainda" else
                SimpleDateFormat("dd/MM 'às' HH:mm", Locale("pt", "BR")).format(Date(last)))
        )

        root.addView(bigButton("Testar alarme pelo servidor") { serverTest() }, matchWrap(top = 16))
        text("Faz o caminho completo: servidor → Firebase → este celular. Bloqueie a tela logo depois de tocar pra ver funcionando.")

        root.addView(button("Tocar alarme aqui (sem servidor)") { localTest() }, matchWrap(top = 8))
        permissionsBlock()

        section("Outras opções")
        root.addView(button("Desparear este celular") { unpair() }, matchWrap())
    }

    private fun permissionsBlock() {
        val notifOk = Build.VERSION.SDK_INT < 33 ||
            checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED
        val fullScreenOk = Build.VERSION.SDK_INT < 34 ||
            getSystemService(NotificationManager::class.java).canUseFullScreenIntent()

        section("Permissões")
        text(listOf(
            mark(notifOk) + " Notificações",
            mark(fullScreenOk) + " Alarme em tela cheia (por cima da tela bloqueada)",
        ).joinToString("\n"))
        if (!notifOk) root.addView(button("Permitir notificações") { openNotificationSettings() }, matchWrap(top = 8))
        if (!fullScreenOk) root.addView(button("Permitir tela cheia") { askFullScreen() }, matchWrap(top = 8))
    }

    // ------------------------------------------------------------------ ações

    private fun handleDeepLink(intent: Intent?) {
        val data = intent?.data ?: return
        if (data.scheme != "alarmepedidos" || data.host != "pair") return
        val code = data.getQueryParameter("code") ?: return
        data.getQueryParameter("server")?.takeIf { it.startsWith("https://") || it.startsWith("http://") }
            ?.let { if (BuildConfig.BACKEND_URL.isBlank()) Prefs.setBackendUrl(this, it) }
        if (Prefs.isPaired(this)) {
            toast("Este celular já está pareado. Desapareie antes de usar outro código.")
            return
        }
        render(code)
        pair(code)
    }

    private fun pair(rawCode: String) {
        if (busy) return
        val code = rawCode.filter { it.isDigit() }
        if (code.length != 6) return toast("Digite o código de 6 dígitos do painel.")
        if (Prefs.backendUrl(this) == null) return toast("Informe o endereço do servidor.")
        if (!App.firebaseConfigured) return toast("Este APK não tem a configuração do Firebase.")

        busy = true
        render(code)
        FirebaseMessaging.getInstance().token.addOnCompleteListener { task ->
            val fcm = if (task.isSuccessful) task.result else null
            if (fcm.isNullOrBlank()) {
                busy = false
                render(code)
                toast("Não consegui registrar o celular no Firebase. Confira a internet e tente de novo.")
                return@addOnCompleteListener
            }
            Prefs.setFcmToken(this, fcm)
            background({ BackendApi.pair(this, code, fcm) }) { result, error ->
                busy = false
                if (result != null) {
                    Prefs.savePairing(this, result.deviceToken, result.companyName)
                    toast("Pareado com ${result.companyName ?: "a loja"}!")
                    render()
                } else {
                    render(code)
                    toast(error ?: "Não consegui parear.")
                }
            }
        }
    }

    /** Confere se ainda está pareado e manda o token do Firebase mais recente. */
    private fun syncWithServer() {
        background({
            BackendApi.me(this)
            Prefs.fcmToken(this)?.let { BackendApi.updateFcmToken(this, it) }
        }) { _, error ->
            if (error != null && lastHttpCode == 401) {
                Prefs.clearPairing(this)
                render()
                toast("Este celular foi removido no painel. Pareie de novo.")
            }
        }
    }

    private fun serverTest() {
        background({ BackendApi.requestTestAlarm(this) }) { _, error ->
            toast(error ?: "Pedido de teste enviado. O alarme deve tocar em alguns segundos.")
        }
    }

    private fun localTest() {
        AlarmService.ring(this, "Teste do alarme", "Se está tocando, o som e a tela cheia funcionam neste celular.")
        startActivity(Intent(this, AlarmActivity::class.java))
    }

    private fun unpair() {
        background({ BackendApi.unpair(this) }) { _, _ ->
            Prefs.clearPairing(this)
            render()
            toast("Celular despareado.")
        }
    }

    private fun askFullScreen() {
        if (Build.VERSION.SDK_INT >= 34) {
            try {
                startActivity(Intent(Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT, Uri.parse("package:$packageName")))
            } catch (_: Exception) {
                openNotificationSettings()
            }
        }
    }

    private fun openNotificationSettings() {
        startActivity(Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, packageName))
    }

    // ------------------------------------------------------------------ helpers

    @Volatile private var lastHttpCode = 0

    private fun <T> background(work: () -> T, done: (T?, String?) -> Unit) {
        Thread {
            try {
                val r = work()
                lastHttpCode = 200
                runOnUiThread { if (!isFinishing) done(r, null) }
            } catch (e: BackendApi.ApiException) {
                lastHttpCode = e.httpCode
                runOnUiThread { if (!isFinishing) done(null, e.message) }
            } catch (e: Exception) {
                lastHttpCode = 0
                runOnUiThread { if (!isFinishing) done(null, "Sem conexão com o servidor.") }
            }
        }.start()
    }

    private fun header() {
        root.addView(TextView(this).apply {
            text = "🔔 Alarme de Pedidos"
            textSize = 26f
            setTypeface(typeface, Typeface.BOLD)
            setTextColor(brand)
        })
        root.addView(TextView(this).apply {
            text = "Para lojas Cardápio Web"
            textSize = 14f
            setTextColor(muted)
        })
    }

    private fun section(title: String) = root.addView(TextView(this).apply {
        text = title.uppercase()
        textSize = 13f
        setTypeface(typeface, Typeface.BOLD)
        setTextColor(muted)
        setPadding(0, px(26), 0, px(8))
    })

    private fun text(s: String) = root.addView(TextView(this).apply {
        text = s
        textSize = 15f
        setLineSpacing(0f, 1.25f)
        setPadding(0, px(4), 0, 0)
    })

    private fun warning(s: String) = root.addView(TextView(this).apply {
        text = "⚠️ $s"
        textSize = 14f
        setTextColor(Color.parseColor("#92400E"))
        setBackgroundColor(Color.parseColor("#FEF3C7"))
        setPadding(px(12), px(12), px(12), px(12))
    }, matchWrap(top = 16))

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
        setBackgroundColor(brand)
        setPadding(0, px(16), 0, px(16))
        isEnabled = !busy
        setOnClickListener { onClick() }
    }

    private fun matchWrap(top: Int = 0) = LinearLayout.LayoutParams(
        LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT,
    ).apply { topMargin = px(top) }

    private fun px(v: Int) = (v * dp).toInt()
    private fun mark(ok: Boolean) = if (ok) "✅" else "⚠️"
    private fun toast(s: String) = Toast.makeText(this, s, Toast.LENGTH_LONG).show()
}
