# Alarme de Pedidos – app da CW App Store

Toca um alarme no celular da loja quando chega pedido na Cardápio Web — mesmo com a tela bloqueada e no silencioso — até alguém tocar em **"Vi o pedido"**.

```
Pedido criado na CW
   │  webhook ORDER_CREATED
   ▼
backend/ (Laravel) ── fila ──► Firebase Cloud Messaging ──► android/ (app) ──► alarme
   ▲
   └── instalação pela CW App Store (OAuth + PKCE), botão Acessar (SSO), painel de pareamento
```

## Pastas

| Pasta | O que é |
|---|---|
| `backend/` | Laravel 12. Instalação OAuth, SSO, painel da loja, webhook de pedidos, envio FCM. |
| `android/` | App Kotlin. Pareia com a loja por código/QR e toca o alarme ao receber o push. |

## Backend

Rotas principais:

| Rota | Uso |
|---|---|
| `GET /install` | **URL de instalação** cadastrada na CW. Redireciona pro portal com PKCE. |
| `GET /oauth/callback` | **Redirect URI** cadastrada na CW. Troca o `code`, chama `identity/context`, cria a instalação. |
| `GET /sso` | **URL de login** (botão Acessar). Valida o ticket e abre o painel. |
| `GET /painel` | Painel: gerar código/QR de pareamento, ver celulares, tocar alarme de teste. |
| `POST /api/webhooks/cardapioweb` | **URL do webhook** cadastrada na CW. Valida `X-Webhook-Token`, descarta duplicados por `event_id`, enfileira o alarme. |
| `POST /api/devices/pair` | App troca o código de 6 dígitos por um token do aparelho. |
| `/api/devices/me` (+ `/fcm-token`, `/test`) | Status, atualização do token FCM, teste ponta a ponta, desparear. |

Configuração (`.env`):

```
APP_URL=https://seu-dominio
CARDAPIOWEB_ENV=sandbox            # ou production
CARDAPIOWEB_CLIENT_ID=             # enviado pela CW após o cadastro
CARDAPIOWEB_REDIRECT_URI="${APP_URL}/oauth/callback"
CARDAPIOWEB_WEBHOOK_TOKEN=         # enviado pela CW após o cadastro
FCM_PROJECT_ID=
FCM_CREDENTIALS_BASE64=            # base64 -w0 service-account.json
QUEUE_CONNECTION=database          # ou redis
```

Rodar:

```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve          # web
php artisan queue:work     # envia os pushes — precisa ficar rodando (Supervisor / Horizon)
php artisan test
```

## Testar local (sem a Cardápio Web)

```bash
cd backend
composer install && cp .env.example .env && php artisan key:generate
# no .env: APP_ENV=local, CARDAPIOWEB_WEBHOOK_TOKEN=qualquer-coisa, FCM_PROJECT_ID e FCM_CREDENTIALS_BASE64
touch database/database.sqlite && php artisan migrate

php artisan serve                      # terminal 1
cloudflared tunnel --url http://localhost:8000   # terminal 2 → copie a URL https://....trycloudflare.com
# coloque essa URL em APP_URL no .env e reinicie o serve
php artisan queue:work                 # terminal 3

php artisan alarme:loja-teste          # cria a loja falsa e mostra o link do painel
# abra o link, gere o código, pareie o celular (pelo QR o app já pega o endereço do servidor)
php artisan alarme:pedido-teste        # simula um pedido novo → o celular toca
```

O túnel é necessário porque o Android bloqueia `http://` sem TLS. Os comandos `alarme:*` e a rota `/dev/entrar` só existem com `APP_ENV=local`.

## App Android

O APK é gerado pelo GitHub Actions a cada push em `android/` e publicado na release **latest**.

Configuração em **Settings → Secrets and variables → Actions**:

| Tipo | Nome | Valor |
|---|---|---|
| Secret | `KEYSTORE_B64`, `KEYSTORE_PASSWORD` | Chave de assinatura (versões novas instalam por cima) |
| Variable | `FIREBASE_PROJECT_ID`, `FIREBASE_APP_ID`, `FIREBASE_API_KEY`, `FIREBASE_SENDER_ID` | Do app Android no console do Firebase (pacote `com.samuelcosta.alarmepedidos`) |
| Variable | `BACKEND_URL` | Opcional. Sem ele, o app pega o endereço do servidor pelo QR code. |

## Cadastro na CW App Store

Enviar ao suporte da CW: nome, ícone, descrição e as URLs `…/install` (instalação), `…/oauth/callback` (redirect), `…/sso` (login) e `…/api/webhooks/cardapioweb` (webhook), com as permissões `auth` e `orders`. Começar como **app privado** no **sandbox**.

Documentação: https://docs.cardapioweb.com
