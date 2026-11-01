<?php

namespace Tests\Unit;

use App\Modules\Payments\Clients\DokuClient;
use PHPUnit\Framework\TestCase;

class DokuSignatureTest extends TestCase
{
    public function test_signature_matches_documented_algorithm(): void
    {
        // Docs: developers.doku.com → Signature Component (non-SNAP), "Signature Component
        // from Request Header": komponen Client-Id/Request-Id/Request-Timestamp/Request-Target/
        // Digest(base64 SHA-256 body) dipisah \n, HMAC-SHA256 base64 dengan secret key,
        // prefix HMACSHA256=.
        $raw = '{"order":{"invoice_number":"INV-1","amount":90000}}';
        $digest = base64_encode(hash('sha256', $raw, true));
        $components = "Client-Id:CLIENT\nRequest-Id:req-1\nRequest-Timestamp:2026-11-01T00:00:00Z\n"
            ."Request-Target:/api/v1/webhooks/doku\nDigest:{$digest}";
        $expected = 'HMACSHA256='.base64_encode(hash_hmac('sha256', $components, 'secret', true));

        $this->assertSame(
            $expected,
            DokuClient::signature('CLIENT', 'req-1', '2026-11-01T00:00:00Z', '/api/v1/webhooks/doku', $raw, 'secret'),
        );
    }

    public function test_signature_different_secret_or_body_produces_different_value(): void
    {
        $raw = '{"order":{"invoice_number":"INV-1","amount":90000}}';

        $a = DokuClient::signature('CLIENT', 'req-1', 't', '/n', $raw, 'secret-a');
        $b = DokuClient::signature('CLIENT', 'req-1', 't', '/n', $raw, 'secret-b');
        $c = DokuClient::signature('CLIENT', 'req-1', 't', '/n', '{"other":1}', 'secret-a');

        $this->assertNotSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertStringStartsWith('HMACSHA256=', $a);
    }
}
