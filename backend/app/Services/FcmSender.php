<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Envio pelo Firebase Cloud Messaging (API HTTP v1), sem SDK:
 * assina o JWT da conta de serviço com openssl e troca por um access token do Google.
 */
class FcmSender
{
    public const RESULT_OK = 'ok';

    public const RESULT_INVALID_TOKEN = 'invalid_token';

    public const RESULT_ERROR = 'error';

    /**
     * Envia uma mensagem só de dados (data-only, prioridade alta) — o app decide como tocar.
     *
     * @param  array<string, string>  $data
     * @return array{result: string, detail: ?string}
     */
    public function send(string $fcmToken, array $data): array
    {
        $projectId = config('fcm.project_id');
        if (! $projectId) {
            throw new RuntimeException('FCM_PROJECT_ID não configurado.');
        }

        $response = Http::withToken($this->accessToken())
            ->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $fcmToken,
                    'data' => array_map('strval', $data),
                    'android' => [
                        'priority' => 'HIGH',
                        'ttl' => config('fcm.ttl_seconds').'s',
                    ],
                ],
            ]);

        if ($response->successful()) {
            return ['result' => self::RESULT_OK, 'detail' => null];
        }

        $status = $response->json('error.status');
        $code = collect($response->json('error.details', []))
            ->pluck('errorCode')->filter()->first();

        // Token de aparelho que não existe mais (app desinstalado, dados limpos…)
        if ($status === 'NOT_FOUND' || $code === 'UNREGISTERED'
            || ($code === 'INVALID_ARGUMENT' && str_contains((string) $response->body(), 'registration token'))) {
            return ['result' => self::RESULT_INVALID_TOKEN, 'detail' => $code ?? $status];
        }

        return ['result' => self::RESULT_ERROR, 'detail' => trim(($code ?? $status ?? 'http_'.$response->status()))];
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm_access_token', now()->addMinutes(50), function () {
            $sa = $this->serviceAccount();
            $now = time();
            $jwt = $this->jwt([
                'iss' => $sa['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], $sa['private_key']);

            $resp = Http::asForm()->timeout(10)->post($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ])->throw();

            return $resp->json('access_token');
        });
    }

    private function serviceAccount(): array
    {
        $raw = base64_decode((string) config('fcm.credentials_base64'), true);
        $json = $raw ? json_decode($raw, true) : null;

        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            throw new RuntimeException('FCM_CREDENTIALS_BASE64 ausente ou inválido.');
        }

        return $json;
    }

    private function jwt(array $claims, string $privateKey): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $b64(json_encode($claims));
        $signature = '';
        if (! openssl_sign("{$header}.{$payload}", $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Não consegui assinar o JWT do Firebase.');
        }

        return "{$header}.{$payload}.".$b64($signature);
    }
}
