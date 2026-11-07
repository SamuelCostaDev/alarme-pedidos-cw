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

Artisan::command('alarme:diagnostico', function () {
    $ok = fn (string $s) => $this->line("  <fg=green>✔</> {$s}");
    $bad = fn (string $s) => $this->line("  <fg=red>✘</> {$s}");
    $warn = fn (string $s) => $this->line("  <fg=yellow>!</> {$s}");
    $problems = 0;

    $this->info('Configuração');
    $url = (string) config('app.url');
    str_starts_with($url, 'https://') ? $ok("APP_URL em HTTPS: {$url}") : ($warn("APP_URL sem HTTPS ({$url}) — o celular e a CW precisam de HTTPS") ?? $problems++);
    $this->line('  · Ambiente da CW: '.config('cardapioweb.env').' ('.config('cardapioweb.base_url').')');
    config('cardapioweb.client_id') ? $ok('CARDAPIOWEB_CLIENT_ID definido') : $warn('CARDAPIOWEB_CLIENT_ID vazio — instalação pela CW não funciona (ok pra teste local)');
    config('cardapioweb.webhook_token') ? $ok('CARDAPIOWEB_WEBHOOK_TOKEN definido') : ($bad('CARDAPIOWEB_WEBHOOK_TOKEN vazio — todo webhook vai ser recusado') ?? $problems++);

    $this->info('Firebase');
    $raw = base64_decode((string) config('fcm.credentials_base64'), true);
    $sa = $raw ? json_decode($raw, true) : null;
    if (! config('fcm.project_id')) {
        $bad('FCM_PROJECT_ID vazio');
        $problems++;
    } elseif (! is_array($sa) || empty($sa['client_email'])) {
        $bad('FCM_CREDENTIALS_BASE64 ausente ou inválido');
        $problems++;
    } else {
        if (($sa['project_id'] ?? config('fcm.project_id')) !== config('fcm.project_id')) {
            $warn('A conta de serviço é do projeto "'.$sa['project_id'].'", mas FCM_PROJECT_ID é "'.config('fcm.project_id').'"');
        }
        try {
            \Illuminate\Support\Facades\Cache::forget('fcm_access_token');
            (new ReflectionMethod(\App\Services\FcmSender::class, 'accessToken'))->invoke(app(\App\Services\FcmSender::class));
            $ok('Login no Google com a conta de serviço funcionou ('.$sa['client_email'].')');
        } catch (Throwable $e) {
            $bad('Login no Google falhou: '.$e->getMessage());
            $problems++;
        }
    }

    $this->info('Fila');
    $this->line('  · QUEUE_CONNECTION='.config('queue.default'));
    if (config('queue.default') === 'database') {
        $pending = \Illuminate\Support\Facades\DB::table('jobs')->count();
        $oldest = \Illuminate\Support\Facades\DB::table('jobs')->min('created_at');
        if ($pending > 0 && $oldest && now()->timestamp - $oldest > 60) {
            $bad("{$pending} job(s) parados há mais de 1 min — o php artisan queue:work está rodando?");
            $problems++;
        } else {
            $ok("{$pending} job(s) na fila");
        }
    }
    $failed = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
    $failed ? $warn("{$failed} job(s) com falha (php artisan queue:failed)") : $ok('Nenhum job com falha');

    $this->info('Lojas e celulares');
    $active = Installation::where('status', 'active')->count();
    $this->line("  · Lojas ativas: {$active} · desinstaladas: ".Installation::where('status', 'revoked')->count());
    $devices = \App\Models\Device::with('installation')->latest()->take(10)->get();
    foreach ($devices as $d) {
        $state = ! $d->fcm_token ? '<fg=red>sem token FCM</>' : ($d->last_push_error ? '<fg=yellow>erro: '.$d->last_push_error.'</>' : '<fg=green>ok</>');
        $last = $d->last_push_at?->diffForHumans() ?? 'nunca';
        $this->line("  · {$d->installation->company_name} — {$d->name} — último push: {$last} — {$state}");
    }
    if ($devices->isEmpty()) {
        $warn('Nenhum celular pareado ainda');
    }

    $this->info('Últimos webhooks recebidos');
    $events = \App\Models\WebhookEvent::latest()->take(5)->get();
    foreach ($events as $e) {
        $this->line("  · {$e->created_at->diffForHumans()} — {$e->event_type} loja {$e->merchant_id} pedido {$e->order_id} ({$e->order_status}) → {$e->result}");
    }
    if ($events->isEmpty()) {
        $warn('Nenhum webhook recebido ainda');
    }

    $this->newLine();
    $problems ? $this->error("{$problems} problema(s) encontrado(s).") : $this->info('Nada quebrado na configuração.');

    return $problems ? 1 : 0;
})->purpose('Confere configuração, Firebase, fila, celulares e últimos webhooks');
