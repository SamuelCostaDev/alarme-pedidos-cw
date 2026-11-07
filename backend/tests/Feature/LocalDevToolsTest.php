<?php

namespace Tests\Feature;

use App\Models\Installation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LocalDevToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dev_commands_refuse_outside_local(): void
    {
        $this->artisan('alarme:loja-teste')->assertExitCode(1);
        $this->artisan('alarme:pedido-teste')->assertExitCode(1);
        $this->assertDatabaseCount('installations', 0);
    }

    public function test_dev_login_route_does_not_exist_outside_local(): void
    {
        $inst = Installation::create(['company_external_id' => '1', 'access_token' => 'x']);
        $this->get('/dev/entrar/'.$inst->id)->assertNotFound();
    }

    public function test_fake_order_command_posts_signed_webhook(): void
    {
        $this->app['env'] = 'local';
        config(['cardapioweb.webhook_token' => 'tok', 'app.url' => 'http://localhost:8000']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->artisan('alarme:pedido-teste 3268')->assertExitCode(0);

        Http::assertSent(fn ($r) => $r->url() === 'http://localhost:8000/api/webhooks/cardapioweb'
            && $r->header('X-Webhook-Token')[0] === 'tok'
            && $r['event_type'] === 'ORDER_CREATED'
            && $r['merchant_id'] === 3268);
    }

    public function test_diagnostics_reports_missing_firebase(): void
    {
        config(['fcm.project_id' => null, 'cardapioweb.webhook_token' => 'x', 'app.url' => 'https://a.test']);

        $this->artisan('alarme:diagnostico')
            ->expectsOutputToContain('FCM_PROJECT_ID vazio')
            ->assertExitCode(1);
    }

    public function test_diagnostics_checks_google_login(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        config([
            'app.url' => 'https://a.test',
            'cardapioweb.webhook_token' => 'x',
            'fcm.project_id' => 'p1',
            'fcm.credentials_base64' => base64_encode(json_encode([
                'project_id' => 'p1', 'client_email' => 'sa@p1.iam.gserviceaccount.com', 'private_key' => $pem,
            ])),
        ]);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'g', 'expires_in' => 3600])]);

        $this->artisan('alarme:diagnostico')
            ->expectsOutputToContain('Login no Google com a conta de serviço funcionou')
            ->assertExitCode(0);
    }
}
