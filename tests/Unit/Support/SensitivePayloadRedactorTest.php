<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SensitivePayloadRedactor;
use App\Services\Widget\WidgetPiiMasker;
use PHPUnit\Framework\TestCase;

final class SensitivePayloadRedactorTest extends TestCase
{
    public function test_nested_and_string_encoded_credentials_and_error_payloads_are_redacted(): void
    {
        $safe = (new SensitivePayloadRedactor(new WidgetPiiMasker()))->redact([
            'nested' => ['credentials' => ['value' => 'CREDENTIAL'], 'accessToken' => 'ACCESS', 'password' => 'PASSWORD'],
            'error' => 'request https://operator:URLPASS@example.test/path?api_key=QUERYSECRET failed Authorization: Bearer SHORTSECRET password="WITH SPACE"',
            'encoded' => '{"payload":{"client_secret":"CLIENTSECRET","message":"Bearer ENCODEDSECRET"}}',
            'embedded_error' => 'Upstream error: {"nested":{"apiKey":"EMBEDDEDSECRET","password":"EMBEDDEDPASSWORD"}}',
            'exception' => new \RuntimeException('OBJECTSECRET'),
        ]);
        $encoded = json_encode($safe);
        foreach (['CREDENTIAL', 'ACCESS', 'PASSWORD', 'URLPASS', 'QUERYSECRET', 'SHORTSECRET', 'WITH SPACE', 'CLIENTSECRET', 'ENCODEDSECRET', 'OBJECTSECRET', 'EMBEDDEDSECRET', 'EMBEDDEDPASSWORD'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        $this->assertSame('[REDACTED]', $safe['nested']['credentials']);
    }

    public function test_regular_text_and_usage_numbers_retain_their_shape(): void
    {
        $payload = ['message' => 'A regular diagnostic observation.', 'nested' => ['prompt_tokens' => 120, 'completion_tokens' => 35]];
        $this->assertSame($payload, (new SensitivePayloadRedactor(new WidgetPiiMasker()))->redact($payload));
    }
}
