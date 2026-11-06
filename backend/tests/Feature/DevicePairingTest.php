<?php

namespace Tests\Feature;

use App\Jobs\SendOrderAlarm;
use App\Models\Device;
use App\Models\Installation;
use App\Models\PairingCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DevicePairingTest extends TestCase
{
    use RefreshDatabase;

    private Installation $inst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inst = Installation::create([
            'company_external_id' => '3268', 'company_name' => 'Frederico Pasta e Grill',
            'access_token' => 'at', 'token_expires_at' => now()->addHour(),
        ]);
    }

    public function test_panel_generates_code_and_shows_qr_link(): void
    {
        $this->withSession(['installation_id' => $this->inst->id])
            ->post('/painel/codigo')->assertRedirect(route('panel'));

        $code = PairingCode::sole();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code->code);

        $this->get('/painel')->assertOk()
            ->assertSee($code->code)
            ->assertSee('alarmepedidos://pair?code='.$code->code, false);
    }

    public function test_full_pairing_flow(): void
    {
        $code = PairingCode::generateFor($this->inst);

        $res = $this->postJson('/api/devices/pair', [
            'code' => $code->code, 'fcm_token' => 'fcm-abc', 'device_name' => 'Moto G balcão',
        ])->assertCreated()->assertJson(['company_name' => 'Frederico Pasta e Grill']);

        $token = $res->json('device_token');
        $this->getJson('/api/devices/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()->assertJson(['name' => 'Moto G balcão', 'has_fcm_token' => true]);

        // O código não pode ser reutilizado
        $this->postJson('/api/devices/pair', ['code' => $code->code, 'fcm_token' => 'outro'])
            ->assertStatus(422);

        // Token do FCM pode mudar
        $this->putJson('/api/devices/me/fcm-token', ['fcm_token' => 'fcm-novo'], ['Authorization' => "Bearer {$token}"])
            ->assertOk();
        $this->assertSame('fcm-novo', Device::sole()->fcm_token);
    }

    public function test_expired_code_is_rejected(): void
    {
        $code = PairingCode::generateFor($this->inst);
        $code->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/devices/pair', ['code' => $code->code, 'fcm_token' => 'x'])->assertStatus(422);
    }

    public function test_device_api_requires_token(): void
    {
        $this->getJson('/api/devices/me')->assertStatus(401);
        $this->getJson('/api/devices/me', ['Authorization' => 'Bearer invalido'])->assertStatus(401);
    }

    public function test_device_can_request_test_alarm_and_unpair(): void
    {
        Queue::fake();
        [$plain, $hash] = Device::newApiToken();
        $device = $this->inst->devices()->create(['name' => 'X', 'api_token_hash' => $hash, 'fcm_token' => 'f']);

        $this->postJson('/api/devices/me/test', [], ['Authorization' => "Bearer {$plain}"])->assertOk();
        Queue::assertPushed(SendOrderAlarm::class, fn ($j) => $j->kind === 'test' && $j->onlyDeviceId === $device->id);

        $this->deleteJson('/api/devices/me', [], ['Authorization' => "Bearer {$plain}"])->assertOk();
        $this->assertDatabaseCount('devices', 0);
    }
}
