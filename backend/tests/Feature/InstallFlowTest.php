<?php

namespace Tests\Feature;

use App\Models\Installation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstallFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cardapioweb.client_id' => 'client-123',
            'cardapioweb.redirect_uri' => 'https://alarme.test/oauth/callback',
        ]);
    }

    public function test_install_redirects_to_portal_with_pkce(): void
    {
        $response = $this->get('/install')->assertRedirect();
        $location = $response->headers->get('Location');

        $this->assertStringStartsWith(config('cardapioweb.authorize_url').'?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $q);
        $this->assertSame('client-123', $q['client_id']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame('https://alarme.test/oauth/callback', $q['redirect_uri']);

        $verifier = Cache::get('oauth_state:'.$q['state']);
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->assertSame($expected, $q['code_challenge']);
    }

    public function test_callback_creates_installation_and_opens_panel(): void
    {
        Cache::put('oauth_state:abc', 'verifier-xyz', 600);
        Http::fake([
            '*/api/partner/oauth/token' => Http::response([
                'access_token' => 'access-1', 'token_type' => 'Bearer', 'expires_in' => 7200,
                'refresh_token' => 'refresh-1', 'scope' => 'auth orders',
            ]),
            '*/api/partner/v1/identity/context' => Http::response([
                'user' => ['id' => 77, 'name' => 'Samuel', 'email' => 's@example.com'],
                'company' => ['id' => 3268, 'name' => 'Frederico Pasta e Grill'],
            ]),
        ]);

        $this->get('/oauth/callback?code=the-code&state=abc')->assertRedirect(route('panel'));

        $inst = Installation::sole();
        $this->assertSame('3268', $inst->company_external_id);
        $this->assertSame('Frederico Pasta e Grill', $inst->company_name);
        $this->assertSame('access-1', $inst->access_token);
        $this->assertSame('refresh-1', $inst->refresh_token);
        // Token criptografado no banco
        $this->assertNotSame('access-1', DB::table('installations')->value('access_token'));
        $this->assertSame($inst->id, session('installation_id'));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/partner/oauth/token')
            && $r['grant_type'] === 'authorization_code'
            && $r['code_verifier'] === 'verifier-xyz'
            && $r['client_id'] === 'client-123');

        // State é de uso único
        $this->get('/oauth/callback?code=the-code&state=abc')->assertStatus(400);
    }

    public function test_callback_rejects_unknown_state(): void
    {
        Http::fake();
        $this->get('/oauth/callback?code=x&state=nao-existe')->assertStatus(400);
        Http::assertNothingSent();
    }

    public function test_sso_ticket_opens_panel(): void
    {
        $inst = Installation::create([
            'company_external_id' => '3268', 'company_name' => 'Loja',
            'access_token' => 'at', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
        ]);
        Http::fake([
            '*/identity/sso_tickets/validate' => Http::response([
                'valid' => true, 'user' => ['id' => 1], 'company' => ['id' => '3268', 'name' => 'Loja Nova'],
            ]),
        ]);

        $this->get('/sso?ticket=ST-1&company_external_id=3268')->assertRedirect(route('panel'));
        $this->assertSame($inst->id, session('installation_id'));
        $this->assertSame('Loja Nova', $inst->fresh()->company_name);
    }

    public function test_invalid_sso_ticket_shows_login_hint(): void
    {
        Installation::create([
            'company_external_id' => '3268', 'access_token' => 'at', 'token_expires_at' => now()->addHour(),
        ]);
        Http::fake([
            '*/identity/sso_tickets/validate' => Http::response(['valid' => false, 'error' => 'invalid_ticket'], 401),
        ]);

        $this->get('/sso?ticket=ST-1&company_external_id=3268')->assertStatus(401);
        $this->assertNull(session('installation_id'));
    }

    public function test_expired_token_is_refreshed_before_sso(): void
    {
        $inst = Installation::create([
            'company_external_id' => '3268', 'access_token' => 'velho', 'refresh_token' => 'rt-1',
            'token_expires_at' => now()->subMinute(),
        ]);
        Http::fake([
            '*/api/partner/oauth/token' => Http::response(['access_token' => 'novo', 'expires_in' => 7200, 'refresh_token' => 'rt-2']),
            '*/identity/sso_tickets/validate' => Http::response(['valid' => true, 'company' => ['id' => 3268]]),
        ]);

        $this->get('/sso?ticket=ST-1&company_external_id=3268')->assertRedirect(route('panel'));

        $inst->refresh();
        $this->assertSame('novo', $inst->access_token);
        $this->assertSame('rt-2', $inst->refresh_token);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sso_tickets') && $r->header('Authorization')[0] === 'Bearer novo');
    }

    public function test_panel_requires_session(): void
    {
        $this->get('/painel')->assertStatus(401);
    }
}
