<?php

namespace Tests\Feature;

use App\Jobs\SendOrderAlarm;
use App\Models\Device;
use App\Models\Installation;
use App\Services\FcmSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendOrderAlarmTest extends TestCase
{
    use RefreshDatabase;

    private Installation $inst;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        config([
            'fcm.project_id' => 'alarme-teste',
            'fcm.credentials_base64' => base64_encode(json_encode([
                'client_email' => 'fcm@alarme-teste.iam.gserviceaccount.com',
                'private_key' => $pem,
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ])),
        ]);

        $this->inst = Installation::create([
            'company_external_id' => '3268', 'company_name' => 'Frederico Pasta e Grill',
            'access_token' => 'at', 'token_expires_at' => now()->addHour(),
        ]);
    }

    private function device(string $fcm): Device
    {
        [, $hash] = Device::newApiToken();

        return $this->inst->devices()->create(['name' => 'Cel', 'api_token_hash' => $hash, 'fcm_token' => $fcm]);
    }

    public function test_sends_high_priority_data_message_to_each_device(): void
    {
        $this->device('tok-1');
        $this->device('tok-2');
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'g-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/alarme-teste/messages/1']),
        ]);

        (new SendOrderAlarm($this->inst->id, 'order', '237456', 'waiting_confirmation'))->handle(app(FcmSender::class));

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), 'fcm.googleapis.com')) {
                return false;
            }

            return $r->url() === 'https://fcm.googleapis.com/v1/projects/alarme-teste/messages:send'
                && $r->header('Authorization')[0] === 'Bearer g-token'
                && $r['message']['android']['priority'] === 'HIGH'
                && $r['message']['data']['type'] === 'order'
                && $r['message']['data']['order_id'] === '237456';
        });
        Http::assertSentCount(3); // 1 token do Google + 2 pushes
        $this->assertNotNull(Device::first()->last_push_at);
    }

    public function test_jwt_is_signed_for_google(): void
    {
        $this->device('tok-1');
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'g', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response([]),
        ]);

        (new SendOrderAlarm($this->inst->id, 'test'))->handle(app(FcmSender::class));

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), 'oauth2.googleapis.com')) {
                return false;
            }
            [, $payload] = explode('.', $r['assertion']);
            $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

            return $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && $claims['scope'] === 'https://www.googleapis.com/auth/firebase.messaging'
                && $claims['iss'] === 'fcm@alarme-teste.iam.gserviceaccount.com';
        });
    }

    public function test_unregistered_token_is_cleared(): void
    {
        $device = $this->device('tok-morto');
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'g', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 404, 'status' => 'NOT_FOUND',
                    'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
                ],
            ], 404),
        ]);

        (new SendOrderAlarm($this->inst->id, 'order', '1'))->handle(app(FcmSender::class));

        $this->assertNull($device->fresh()->fcm_token);
    }
}
