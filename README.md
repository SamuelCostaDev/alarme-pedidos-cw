# Alarme de Pedidos – Cardápio Web

App Android que toca um alarme (canal de despertador, toca até no silencioso) quando chega pedido novo numa loja do Cardápio Web, **mesmo com a tela bloqueada**.

## Como funciona

- Um serviço em primeiro plano consulta `GET /api/partner/v1/orders` a cada 30 s (intervalo recomendado na [documentação](https://docs.cardapioweb.com/fluxo-integracao.md)), usando `updated_since` com 1 min de margem.
- Pedido com `id` ainda não visto e status em aberto → toca o alarme, vibra e abre uma tela vermelha por cima da tela de bloqueio até alguém tocar em **"Vi o pedido"**.
- Ao ligar, pedidos que já estão em `waiting_confirmation` também disparam o alarme.
- Mais de 10 min sem conseguir falar com a API → notificação de aviso.
- Religa sozinho depois que o celular reinicia.

## Autenticação

Usa o token legado `X-API-KEY` da loja (Portal → Configurações → Integrações → API). A documentação avisa que esse modelo será descontinuado no futuro; a chamada está isolada em `CardapioApi.kt` para trocar por OAuth (PKCE + refresh token) depois.

## Instalar

1. Cada push na `main` gera o APK pelo GitHub Actions e publica na release **latest**.
2. No celular, abra a release e baixe `AlarmePedidos.apk`. Permita "instalar apps desconhecidos" para o navegador.
3. Abra o app, cole o token, toque em **Ligar monitoramento** e libere bateria/tela cheia nos botões que aparecerem.
4. Toque em **Testar alarme**.

O APK é assinado com a chave guardada nos secrets `KEYSTORE_B64` e `KEYSTORE_PASSWORD` do repositório, então versões novas instalam por cima da anterior. Sem esses secrets, o build usa a chave de debug.
