<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PairingCodeTest extends TestCase
{
    public function test_pkce_challenge_matches_rfc7636_example(): void
    {
        // Exemplo do RFC 7636, apêndice B
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $challenge);
    }
}
