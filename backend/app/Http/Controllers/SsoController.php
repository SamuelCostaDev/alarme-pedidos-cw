<?php

namespace App\Http\Controllers;

use App\Models\Installation;
use App\Services\CardapioWebClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Botão "Acessar" na CW App Store → /sso?ticket=ST-...&company_external_id=123
 *
 * @see https://docs.cardapioweb.com/cw-app-store/onboarding-e-acesso.md
 */
class SsoController extends Controller
{
    public function __invoke(Request $request, CardapioWebClient $cw)
    {
        $ticket = (string) $request->query('ticket');
        $companyId = (string) $request->query('company_external_id');
        $installation = $companyId !== ''
            ? Installation::where('company_external_id', $companyId)->first()
            : null;

        if ($ticket === '' || ! $installation || ! $installation->isActive()) {
            return response()->view('need-login', [], 401);
        }

        try {
            $result = $cw->validateSsoTicket($installation, $ticket);
        } catch (Throwable $e) {
            Log::warning('Falha ao validar ticket SSO', ['error' => $e->getMessage()]);
            $result = null;
        }

        // Ticket é de uso único: se falhou, não reenvia — mostra o caminho normal
        if (! $result || (string) data_get($result, 'company.id', $companyId) !== $companyId) {
            return response()->view('need-login', [], 401);
        }

        if ($name = data_get($result, 'company.name')) {
            $installation->forceFill(['company_name' => $name])->save();
        }

        $request->session()->regenerate();
        $request->session()->put('installation_id', $installation->id);

        return redirect()->route('panel');
    }
}
