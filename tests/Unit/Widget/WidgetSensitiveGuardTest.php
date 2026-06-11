<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Services\Widget\WidgetSnapshotValidator;
use PHPUnit\Framework\TestCase;

/**
 * M5.8 — Guard BE: i campi data-kitt-sensitive DEVONO avere value:null.
 *
 * Il FE già setta value:null per i field con sensitive:true, ma il BE
 * non si fida. Se un client compromesso invia value non-null, il BE
 * lo forza a null (difesa in profondità).
 */
final class WidgetSensitiveGuardTest extends TestCase
{
    private WidgetSnapshotValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new WidgetSnapshotValidator;
    }

    public function test_sensitive_field_with_value_is_forced_to_null(): void
    {
        $snapshot = [
            'fields' => [
                ['name' => 'email', 'label' => 'Email', 'type' => 'text', 'value' => 'user@test.com', 'sensitive' => true],
            ],
        ];

        $result = $this->validator->enforceSensitiveNull($snapshot);

        $this->assertNull($result['fields'][0]['value']);
    }

    public function test_sensitive_field_already_null_stays_null(): void
    {
        $snapshot = [
            'fields' => [
                ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'value' => null, 'sensitive' => true],
            ],
        ];

        $result = $this->validator->enforceSensitiveNull($snapshot);

        $this->assertNull($result['fields'][0]['value']);
    }

    public function test_non_sensitive_field_value_is_preserved(): void
    {
        $snapshot = [
            'fields' => [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => 'John', 'sensitive' => false],
            ],
        ];

        $result = $this->validator->enforceSensitiveNull($snapshot);

        $this->assertSame('John', $result['fields'][0]['value']);
    }

    public function test_field_without_sensitive_flag_is_preserved(): void
    {
        $snapshot = [
            'fields' => [
                ['name' => 'city', 'label' => 'City', 'type' => 'text', 'value' => 'Rome'],
            ],
        ];

        $result = $this->validator->enforceSensitiveNull($snapshot);

        $this->assertSame('Rome', $result['fields'][0]['value']);
    }

    /** #3 — type=password SENZA flag sensitive → comunque nullato (BE re-deriva). */
    public function test_password_type_without_sensitive_flag_is_forced_to_null(): void
    {
        $snapshot = [
            'fields' => [
                ['name' => 'pwd', 'label' => 'Password', 'type' => 'password', 'value' => 'hunter2'],
            ],
        ];

        $result = $this->validator->enforceSensitiveNull($snapshot);

        $this->assertNull($result['fields'][0]['value']);
    }

    /** #3 — type=hidden SENZA flag sensitive → nullato (token/csrf/id nascosti). */
    public function test_hidden_type_without_sensitive_flag_is_forced_to_null(): void
    {
        $snapshot = [
            'fields' => [
                ['name' => '_token', 'label' => '', 'type' => 'hidden', 'value' => 'csrf-abc123'],
            ],
            'regions' => [
                ['name' => 'form', 'fields' => [
                    ['name' => 'secret', 'type' => 'password', 'value' => 'p@ss'],
                ]],
            ],
        ];

        $result = $this->validator->enforceSensitiveNull($snapshot);

        $this->assertNull($result['fields'][0]['value']);
        $this->assertNull($result['regions'][0]['fields'][0]['value']);
    }

    public function test_sensitive_field_in_region_is_forced_to_null(): void
    {
        $snapshot = [
            'regions' => [
                [
                    'name' => 'login',
                    'fields' => [
                        ['name' => 'ssn', 'label' => 'SSN', 'type' => 'text', 'value' => '123-45-6789', 'sensitive' => true],
                    ],
                ],
            ],
        ];

        $result = $this->validator->enforceSensitiveNull($snapshot);

        $this->assertNull($result['regions'][0]['fields'][0]['value']);
    }

    public function test_empty_fields_array_passes_through(): void
    {
        $snapshot = [
            'fields' => [],
        ];

        $result = $this->validator->enforceSensitiveNull($snapshot);

        $this->assertSame([], $result['fields']);
    }

    public function test_snapshot_without_fields_passes_through(): void
    {
        $snapshot = ['page' => ['url' => 'https://test.com', 'title' => 'Test']];

        $result = $this->validator->enforceSensitiveNull($snapshot);

        $this->assertSame('https://test.com', $result['page']['url']);
    }

    public function test_multiple_sensitive_fields_all_forced_to_null(): void
    {
        $snapshot = [
            'fields' => [
                ['name' => 'card', 'type' => 'text', 'value' => '4111111111111111', 'sensitive' => true],
                ['name' => 'cvv', 'type' => 'text', 'value' => '123', 'sensitive' => true],
                ['name' => 'name', 'type' => 'text', 'value' => 'John', 'sensitive' => false],
            ],
        ];

        $result = $this->validator->enforceSensitiveNull($snapshot);

        $this->assertNull($result['fields'][0]['value']);
        $this->assertNull($result['fields'][1]['value']);
        $this->assertSame('John', $result['fields'][2]['value']);
    }
}