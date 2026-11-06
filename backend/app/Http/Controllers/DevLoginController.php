<?php

namespace App\Http\Controllers;

use App\Models\Installation;
use Illuminate\Http\Request;

/** Entrada no painel sem a Cardápio Web — só existe com APP_ENV=local. */
class DevLoginController extends Controller
{
    public function __invoke(Request $request, Installation $installation)
    {
        abort_unless(app()->environment('local'), 404);

        $request->session()->regenerate();
        $request->session()->put('installation_id', $installation->id);

        return redirect()->route('panel');
    }
}
