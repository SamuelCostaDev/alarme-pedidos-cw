<?php

namespace App\Http\Controllers;

use App\Jobs\SendOrderAlarm;
use App\Models\Installation;
use App\Models\PairingCode;
use Illuminate\Http\Request;

/** Painel da loja: parear celulares, ver quem está recebendo, testar o alarme. */
class PanelController extends Controller
{
    private function installation(Request $request): Installation
    {
        return $request->attributes->get('installation');
    }

    public function show(Request $request)
    {
        $installation = $this->installation($request);
        $code = PairingCode::find($request->session()->get('pairing_code_id'));
        if ($code && ($code->installation_id !== $installation->id || ! $code->isUsable())) {
            $code = null;
        }

        return view('panel', [
            'installation' => $installation,
            'devices' => $installation->devices()->latest()->get(),
            'pairingCode' => $code,
            'pairingLink' => $code ? 'alarmepedidos://pair?code='.$code->code.'&server='.urlencode(url('/')) : null,
        ]);
    }

    public function createPairingCode(Request $request)
    {
        $code = PairingCode::generateFor($this->installation($request));
        $request->session()->put('pairing_code_id', $code->id);

        return redirect()->route('panel');
    }

    public function deleteDevice(Request $request, int $deviceId)
    {
        $this->installation($request)->devices()->whereKey($deviceId)->delete();

        return redirect()->route('panel')->with('status', 'Celular removido.');
    }

    public function testAlarm(Request $request)
    {
        $installation = $this->installation($request);
        if ($installation->devices()->whereNotNull('fcm_token')->doesntExist()) {
            return redirect()->route('panel')->with('status', 'Nenhum celular pareado pra receber o teste.');
        }

        SendOrderAlarm::dispatch($installation->id, 'test');

        return redirect()->route('panel')->with('status', 'Alarme de teste enviado pros celulares pareados.');
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();

        return response()->view('need-login');
    }
}
