<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Services\Widget\WidgetPiiMasker;
use PHPUnit\Framework\TestCase;

/**
 * M5.6 — WidgetPiiMasker: maschera PII (email, telefono, IBAN, token/card)
 * in payload JSON e stringhe prima del salvataggio step e nei log.
 */
final class WidgetPiiMaskerTest extends TestCase
{
    private WidgetPiiMasker $masker;

    protected function setUp(): void
    {
        $this->masker = new WidgetPiiMasker;
    }

    // ─── maskString: email ──────────────────────────────────────────

    public function test_masks_email_addresses(): void
    {
        $this->assertSame(
            'Contatta [EMAIL] per assistenza.',
            $this->masker->maskString('Contatta user@example.com per assistenza.'),
        );
    }

    public function test_masks_multiple_emails(): void
    {
        $this->assertSame(
            '[EMAIL] e [EMAIL]',
            $this->masker->maskString('alice@test.it e bob@company.org'),
        );
    }

    // ─── #42 — Codice Fiscale & Partita IVA ─────────────────────────

    public function test_masks_italian_codice_fiscale(): void
    {
        $this->assertSame(
            'Il mio CF è [CF].',
            $this->masker->maskString('Il mio CF è RSSMRA85T10A562S.'),
        );
        // case-insensitive
        $this->assertStringContainsString('[CF]', $this->masker->maskString('rssmra85t10a562s'));
    }

    public function test_masks_italian_partita_iva(): void
    {
        $this->assertSame(
            'P.IVA [VAT]',
            $this->masker->maskString('P.IVA 12345678901'),
        );
    }

    // ─── maskString: telefono ───────────────────────────────────────

    public function test_masks_italian_phone_with_country_code(): void
    {
        $this->assertSame(
            'Chiama [PHONE]',
            $this->masker->maskString('Chiama +39 333 1234567'),
        );
    }

    public function test_masks_italian_mobile_local(): void
    {
        $this->assertSame(
            'Numero [PHONE]',
            $this->masker->maskString('Numero 3331234567'),
        );
    }

    public function test_masks_international_phone(): void
    {
        $this->assertSame(
            'Tel [PHONE]',
            $this->masker->maskString('Tel +1 555 1234567'),
        );
    }

    // ─── maskString: IBAN ──────────────────────────────────────────

    public function test_masks_iban(): void
    {
        $this->assertSame(
            'IBAN [IBAN]',
            $this->masker->maskString('IBAN IT60X0542811101000000123456'),
        );
    }

    // ─── maskString: carta ──────────────────────────────────────────

    public function test_masks_card_number(): void
    {
        $this->assertSame(
            'Carta [CARD]',
            $this->masker->maskString('Carta 4111 1111 1111 1111'),
        );
    }

    // ─── maskString: token/secret ───────────────────────────────────

    public function test_masks_sk_live_token(): void
    {
        // Token costruito per concatenazione di proposito: evita che il
        // secret-scanning di GitHub (push protection) scambi questo fixture
        // per una vera Stripe secret key. A runtime la stringa è identica,
        // quindi il masker la riconosce comunque.
        $secret = 'sk_'.'live_abc123def456ghi789jkl012';
        $this->assertSame(
            'Key [sk_***]',
            $this->masker->maskString('Key '.$secret),
        );
    }

    public function test_masks_pk_test_token(): void
    {
        $this->assertSame(
            'Pub [pk_***]',
            $this->masker->maskString('Pub pk_test_abc123def456ghi789jkl012'),
        );
    }

    public function test_masks_bearer_token(): void
    {
        $this->assertSame(
            'Auth Bearer [TOKEN]',
            $this->masker->maskString('Auth Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.abc123def456'),
        );
    }

    public function test_masks_jwt(): void
    {
        // JWT realistico: tre segmentri base64url separati da dot
        $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIn0';
        $result = $this->masker->maskString('Token '.$jwt);

        $this->assertStringContainsString('[JWT]', $result);
        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', $result);
    }

    // ─── maskString: no false positives ─────────────────────────────

    public function test_does_not_mask_normal_text(): void
    {
        $this->assertSame(
            'Il modulo è stato salvato con successo.',
            $this->masker->maskString('Il modulo è stato salvato con successo.'),
        );
    }

    public function test_does_not_mask_short_numbers(): void
    {
        // Numeri troppo corti per essere carta/IBAN non devono essere mascherati
        $this->assertSame(
            'ID 12345',
            $this->masker->maskString('ID 12345'),
        );
    }

    // ─── maskArray: ricorsivo ───────────────────────────────────────

    public function test_masks_strings_in_nested_array(): void
    {
        $input = [
            'content' => 'Scrivi a user@example.com',
            'meta' => [
                'phone' => '+39 333 1234567',
                'safe' => 'nessun PII',
            ],
            'count' => 42,
        ];

        $result = $this->masker->maskArray($input);

        $this->assertSame('Scrivi a [EMAIL]', $result['content']);
        $this->assertSame('[PHONE]', $result['meta']['phone']);
        $this->assertSame('nessun PII', $result['meta']['safe']);
        $this->assertSame(42, $result['count']);
    }

    public function test_mask_array_returns_null_for_null_input(): void
    {
        $this->assertNull($this->masker->maskArray(null));
    }

    public function test_mask_array_preserves_empty_array(): void
    {
        $this->assertSame([], $this->masker->maskArray([]));
    }

    // ─── maskJsonString ─────────────────────────────────────────────

    public function test_masks_json_string_recursively(): void
    {
        $json = '{"email":"user@example.com","phone":"+39 333 1234567"}';

        $result = $this->masker->maskJsonString($json);

        $decoded = json_decode($result, true);
        $this->assertSame('[EMAIL]', $decoded['email']);
        $this->assertSame('[PHONE]', $decoded['phone']);
    }

    public function test_mask_json_string_returns_null_for_null(): void
    {
        $this->assertNull($this->masker->maskJsonString(null));
    }

    public function test_mask_json_string_returns_empty_for_empty(): void
    {
        $this->assertSame('', $this->masker->maskJsonString(''));
    }

    public function test_mask_json_string_fallback_on_invalid_json(): void
    {
        // JSON invalido → maschera la stringa grezza
        $result = $this->masker->maskJsonString('Contact user@example.com now!');

        $this->assertSame('Contact [EMAIL] now!', $result);
    }

    // ─── integrazione: step persistito con PII ──────────────────────

    public function test_masks_all_pii_types_in_complex_payload(): void
    {
        // Token concatenato per non far scattare il secret-scanning di
        // GitHub sul fixture (vedi test_masks_sk_live_token).
        $secret = 'sk_'.'live_abc123def456ghi789jkl012mno345';
        $input = 'User john@acme.com called +39 02 12345678, IBAN IT60X0542811101000000123456, card 4111111111111111, token '.$secret;

        $result = $this->masker->maskString($input);

        $this->assertStringNotContainsString('john@acme.com', $result);
        $this->assertStringNotContainsString('+39 02 12345678', $result);
        $this->assertStringNotContainsString('IT60X0542811101000000123456', $result);
        $this->assertStringNotContainsString('4111111111111111', $result);
        $this->assertStringNotContainsString($secret, $result);
        $this->assertStringContainsString('[EMAIL]', $result);
        $this->assertStringContainsString('[PHONE]', $result);
        $this->assertStringContainsString('[IBAN]', $result);
        $this->assertStringContainsString('[CARD]', $result);
    }
}