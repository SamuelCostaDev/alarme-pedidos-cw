<?php

namespace App\Http\Middleware;

use App\Models\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Painel só pra quem entrou pela instalação ou pelo botão "Acessar" da Cardápio Web. */
class EnsurePanelSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $installation = Installation::find($request->session()->get('installation_id'));

        if (! $installation || ! $installation->isActive()) {
            return response()->view('need-login', [], 401);
        }

        $request->attributes->set('installation', $installation);

        return $next($request);
    }
}
