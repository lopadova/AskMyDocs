<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Code;

use App\Services\Kb\Code\SecretRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecretRedactorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function secrets(): iterable
    {
        yield 'aws access key' => ["\$k = 'AKIAIOSFODNN7EXAMPLE';", 'AKIAIOSFODNN7EXAMPLE'];
        yield 'github token' => ['token: '.('gh'.'p_'.'abcdefghijklmnopqrstuvwxyz0123456789'), 'gh'.'p_'.'abcdefghijklmnopqrstuvwxyz'];
        // Assembled at runtime so no scanner-recognisable literal sits in the repository.
        yield 'stripe secret' => ["'key' => '".('sk_'.'live_'.'4eC39HqLyjWDarjtT1zdp7dc')."'", 'sk_'.'live_'.'4eC39HqLyjWDarjtT1zdp7dc'];
        yield 'anthropic key' => ['KEY = "sk-ant-api03-AbCdEfGhIjKlMnOpQrStUvWxYz"', 'sk-ant-api03-AbCdEfGhIjKlMnOpQrStUvWxYz'];
        yield 'jwt' => ['$jwt = "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fw";', 'eyJhbGciOiJIUzI1NiJ9'];
        yield 'url password' => ['DSN = "postgres://admin:hunter2pass@db.internal:5432/app"', 'hunter2pass'];
        yield 'high-entropy password assignment' => ["'password' => 'Zx9\$kq2LmN8vB4tR',", 'Zx9$kq2LmN8vB4tR'];
        yield 'secret const' => ['const clientSecret = "a8Fj2kLm9QpX4vTz7Wn";', 'a8Fj2kLm9QpX4vTz7Wn'];
        yield 'pem block' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA\n-----END RSA PRIVATE KEY-----", 'MIIEowIBAAKCAQEA'];
    }

    #[Test]
    #[DataProvider('secrets')]
    public function it_redacts_known_credentials_and_never_returns_the_value(string $code, string $needle): void
    {
        $result = (new SecretRedactor())->redact($code);

        $this->assertStringNotContainsString($needle, $result['text']);
        $this->assertStringContainsString(SecretRedactor::PLACEHOLDER, $result['text']);
        $this->assertGreaterThanOrEqual(1, $result['count']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function benign(): iterable
    {
        yield 'config reference' => ["\$apiKey = config('services.openai.key');"];
        yield 'env var name' => ["define('API_TOKEN', 'OPENAI_API_KEY');"];
        yield 'table name' => ["\$t = 'password_reset_token_table_name';"];
        yield 'placeholder' => ["password = 'changeme-changeme'"];
        yield 'short value' => ["token = 'abc123'"];
        yield 'plain code' => ['function total(int $a, int $b): int { return $a + $b; }'];
    }

    #[Test]
    #[DataProvider('benign')]
    public function it_leaves_ordinary_code_alone(string $code): void
    {
        $result = (new SecretRedactor())->redact($code);

        $this->assertSame($code, $result['text']);
        $this->assertSame(0, $result['count']);
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $redactor = new SecretRedactor();
        $first = $redactor->redact("password = 'Zx9\$kq2LmN8vB4tR' // ghp_abcdefghijklmnopqrstuvwxyz0123456789");
        $second = $redactor->redact($first['text']);

        $this->assertSame($first['text'], $second['text']);
        $this->assertSame(0, $second['count']);
    }
}
