<?php

use App\Models\Installation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/*
| Comandos pra testar sem a Cardápio Web. Só funcionam com APP_ENV=local.
*/

Artisan::command('alarme:loja-teste {--id=3268} {--nome=Loja de Teste}', function () {
    if (! app()->environment('local')) {
        $this->error('Só disponível com APP_ENV=local.');

        return 1;
    }

    $inst = Installation::updateOrCreate(
        ['company_external_id' => (string) $this->option('id')],
        [
            'company_name' => $this->option('nome'),
            'owner_name' => 'Teste local',
            'access_token' => 'token-falso-local',
            'token_expires_at' => now()->addYears(10),
            'status' => 'active',
        ],
    );

    $link = URL::temporarySignedRoute('dev.login', now()->addHour(), ['installation' => $inst->id]);

    $this->info("Loja de teste: {$inst->company_name} (merchant_id {$inst->company_external_id})");
    $this->line('Abra o painel (link vale 1 hora):');
    $this->line($link);
})->purpose('Cria uma loja falsa e mostra o link do painel (só local)');

Artisan::command('alarme:pedido-teste {merchant_id=3268} {--status=waiting_confirmation}', function () {
    if (! app()->environment('local')) {
        $this->error('Só disponível com APP_ENV=local.');

        return 1;
    }

    $token = (string) config('cardapioweb.webhook_token');
    if ($token === '') {
        $this->error('Defina CARDAPIOWEB_WEBHOOK_TOKEN no .env (qualquer valor serve pra teste local).');

        return 1;
    }

    $payload = [
        'event_id' => (string) Str::uuid(),
        'event_type' => 'ORDER_CREATED',
        'merchant_id' => (int) $this->argument('merchant_id'),
        'order_id' => random_int(100000, 999999),
        'order_status' => $this->option('status'),
        'created_at' => now()->toIso8601String(),
    ];

    // Chama o próprio webhook pela URL, igual a CW faria
    $res = Http::withHeaders(['X-Webhook-Token' => $token])
        ->post(rtrim(config('app.url'), '/').'/api/webhooks/cardapioweb', $payload);

    $this->line('Enviado: '.json_encode($payload));
    $this->line("Resposta: HTTP {$res->status()} ".$res->body());
    $this->comment('Se o worker da fila estiver rodando (php artisan queue:work), o push sai agora.');
})->purpose('Simula o webhook ORDER_CREATED da Cardápio Web (só local)');
