<?php

namespace App\Services;

use App\Models\Installation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cliente da API de parceiros da Cardápio Web (OAuth 2.0 + PKCE).
 *
 * @see https://docs.cardapioweb.com/autenticacao/oauth.md
 * @see https://docs.cardapioweb.com/cw-app-store/onboarding-e-acesso.md
 */
class CardapioWebClient
{
    private function http(): PendingRequest
    {
        return Http::baseUrl(config('cardapioweb.base_url'))
            ->acceptJson()
            ->timeout(15);
    }

    /**
     * Monta a URL do portal pra o proprietário autorizar o app.
     *
     * @return array{url: string, state: string, code_verifier: string}
     */
    public function buildAuthorizeUrl(): array
    {
        $state = Str::random(40);
        $verifier = Str::random(64); // 43–128 caracteres [A-Za-z0-9]
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $query = http_build_query([
            'client_id' => config('cardapioweb.client_id'),
            'state' => $state,
            'redirect_uri' => config('cardapioweb.redirect_uri'),
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return [
            'url' => config('cardapioweb.authorize_url').'?'.$query,
            'state' => $state,
            'code_verifier' => $verifier,
        ];
    }

    /** Troca o `code` do callback por tokens. */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => config('cardapioweb.client_id'),
            'code' => $code,
            'redirect_uri' => config('cardapioweb.redirect_uri'),
            'code_verifier' => $codeVerifier,
        ]);
    }

    public function refresh(string $refreshToken): array
    {
        return $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'client_id' => config('cardapioweb.client_id'),
            'refresh_token' => $refreshToken,
        ]);
    }

    private function tokenRequest(array $form): array
    {
        $response = $this->http()->asForm()->post('/api/partner/oauth/token', $form);

        if ($response->failed()) {
            $error = $response->json('error') ?? 'http_'.$response->status();
            $desc = $response->json('error_description') ?? '';
            throw new RuntimeException("Falha no token da Cardápio Web: {$error} {$desc}");
        }

        return $response->json();
    }

    /**
     * Quem autorizou e qual loja. Campos usados: user.id, user.name, user.email, company.id.
     */
    public function identityContext(string $accessToken): array
    {
        return $this->http()
            ->withToken($accessToken)
            ->get('/api/partner/v1/identity/context')
            ->throw()
            ->json();
    }

    /**
     * Valida o ticket do botão "Acessar". Retorna o corpo (com valid=true) ou null se inválido.
     */
    public function validateSsoTicket(Installation $installation, string $ticket): ?array
    {
        $response = $this->http()
            ->withToken($this->freshAccessToken($installation))
            ->post('/api/partner/v1/identity/sso_tickets/validate', ['ticket' => $ticket]);

        if ($response->successful() && $response->json('valid') === true) {
            return $response->json();
        }

        return null;
    }

    /** Devolve um access token válido, renovando com o refresh_token se precisar. */
    public function freshAccessToken(Installation $installation): string
    {
        $expiresSoon = $installation->token_expires_at === null
            || $installation->token_expires_at->subMinutes(5)->isPast();

        if ($expiresSoon && $installation->refresh_token) {
            $tokens = $this->refresh($installation->refresh_token);
            $this->storeTokens($installation, $tokens);
        }

        return $installation->access_token;
    }

    public function storeTokens(Installation $installation, array $tokens): void
    {
        $installation->forceFill([
            'access_token' => $tokens['access_token'],
            // Se a CW não mandar um refresh novo, mantém o atual
            'refresh_token' => $tokens['refresh_token'] ?? $installation->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 7200)),
            'scope' => $tokens['scope'] ?? $installation->scope,
        ])->save();
    }
}
