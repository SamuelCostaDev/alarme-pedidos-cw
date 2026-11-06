<?php

namespace App\Http\Controllers;

use App\Models\Installation;
use App\Services\CardapioWebClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Instalação pela CW App Store:
 * "Instalar" → /install → portal da CW (consentimento) → /oauth/callback → painel.
 *
 * @see https://docs.cardapioweb.com/cw-app-store/instalacao-e-autorizacao.md
 */
class InstallController extends Controller
{
    public function __construct(private CardapioWebClient $cw) {}

    /** URL de instalação cadastrada na CW. A CW não manda parâmetros: vamos direto pra autorização. */
    public function install()
    {
        $auth = $this->cw->buildAuthorizeUrl();
        Cache::put('oauth_state:'.$auth['state'], $auth['code_verifier'], now()->addMinutes(15));

        return redirect()->away($auth['url']);
    }

    /** Redirect URI cadastrada na CW. Recebe ?code=...&state=... */
    public function callback(Request $request)
    {
        $state = (string) $request->query('state');
        $code = (string) $request->query('code');
        $verifier = $state !== '' ? Cache::pull('oauth_state:'.$state) : null;

        if (! $verifier || $code === '') {
            return response()->view('error', [
                'message' => 'Link de autorização inválido ou expirado. Volte na CW App Store e clique em Instalar de novo.',
            ], 400);
        }

        try {
            $tokens = $this->cw->exchangeCode($code, $verifier);
            $context = $this->cw->identityContext($tokens['access_token']);
        } catch (Throwable $e) {
            Log::error('Falha na instalação', ['error' => $e->getMessage()]);

            return response()->view('error', [
                'message' => 'Não consegui concluir a autorização com a Cardápio Web. Tente instalar de novo em alguns minutos.',
            ], 502);
        }

        $companyId = (string) data_get($context, 'company.id');
        if ($companyId === '') {
            return response()->view('error', ['message' => 'A Cardápio Web não informou qual loja autorizou o app.'], 502);
        }

        $installation = Installation::firstOrNew(['company_external_id' => $companyId]);
        $installation->fill([
            'company_name' => data_get($context, 'company.name') ?? $installation->company_name,
            'owner_name' => data_get($context, 'user.name'),
            'owner_email' => data_get($context, 'user.email'),
            'owner_external_id' => (string) data_get($context, 'user.id'),
            'status' => 'active',
            'access_token' => $tokens['access_token'],
        ]);
        $this->cw->storeTokens($installation, $tokens);

        $request->session()->regenerate();
        $request->session()->put('installation_id', $installation->id);

        return redirect()->route('panel');
    }
}
