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
}
