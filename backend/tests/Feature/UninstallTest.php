<?php

namespace Tests\Feature;

use App\Jobs\SendOrderAlarm;
use App\Models\Device;
use App\Models\Installation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Desinstalação: a CW revoga os tokens e a API passa a responder 401.
 *
 * @see https://docs.cardapioweb.com/cw-app-store/instalacao-e-autorizacao.md
 */
class UninstallTest extends TestCase
{
    use RefreshDatabase;

    private function installation(array $attrs = []): Installation
    {
        $inst = Installation::create(array_merge([
            'company_external_id' => '3268', 'company_name' => 'Loja',
            'access_token' => 'at', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
        ], $attrs));
        [, $hash] = Device::newApiToken();
        $inst->devices()->create(['name' => 'Cel', 'api_token_hash' => $hash, 'fcm_token' => 'f']);

        return $inst;
    }

    public function test_refresh_rejected_marks_store_uninstalled(): void
    {
        $inst = $this->installation(['token_expires_at' => now()->subMinute()]);
        Http::fake(['*/api/partner/oauth/token' => Http::response(['error' => 'invalid_grant'], 401)]);

        $this->get('/sso?ticket=ST-1&company_external_id=3268')->assertStatus(401);

        $this->assertSame('revoked', $inst->fresh()->status);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_invalid_installation_token_on_sso_marks_store_uninstalled(): void
    {
        $inst = $this->installation();
        Http::fake(['*/sso_tickets/validate' => Http::response(['code' => 4010, 'message' => 'Token inválido.'], 401)]);

        $this->get('/sso?ticket=ST-1&company_external_id=3268')->assertStatus(401);

        $this->assertSame('revoked', $inst->fresh()->status);
    }

    public function test_bad_ticket_does_not_uninstall(): void
    {
        $inst = $this->installation();
        Http::fake(['*/sso_tickets/validate' => Http::response(['valid' => false, 'error' => 'invalid_ticket'], 401)]);

        $this->get('/sso?ticket=ST-1&company_external_id=3268')->assertStatus(401);

        $this->assertSame('active', $inst->fresh()->status);
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_uninstalled_store_does_not_ring(): void
    {
        config(['cardapioweb.webhook_token' => 'tok']);
        Queue::fake();
        $this->installation(['status' => 'revoked']);

        $this->postJson('/api/webhooks/cardapioweb', [
            'event_id' => 'e1', 'event_type' => 'ORDER_CREATED', 'merchant_id' => 3268,
            'order_id' => 1, 'order_status' => 'waiting_confirmation',
        ], ['X-Webhook-Token' => 'tok'])->assertOk();

        Queue::assertNotPushed(SendOrderAlarm::class);
    }

    public function test_reinstall_reactivates_with_new_tokens(): void
    {
        $inst = $this->installation(['status' => 'revoked']);
        Cache::put('oauth_state:s1', 'v1', 600);
        Http::fake([
            '*/api/partner/oauth/token' => Http::response(['access_token' => 'novo', 'refresh_token' => 'rt-novo', 'expires_in' => 7200]),
            '*/identity/context' => Http::response(['user' => ['id' => 1], 'company' => ['id' => 3268]]),
        ]);

        $this->get('/oauth/callback?code=c&state=s1')->assertRedirect(route('panel'));

        $inst->refresh();
        $this->assertSame('active', $inst->status);
        $this->assertSame('novo', $inst->access_token);
        $this->assertSame(1, Installation::count());
    }
}
