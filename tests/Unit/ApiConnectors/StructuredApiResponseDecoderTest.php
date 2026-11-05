<?php

declare(strict_types=1);

namespace Tests\Unit\ApiConnectors;

use App\ApiConnectors\StructuredApiResponseDecoder;
use PHPUnit\Framework\TestCase;

final class StructuredApiResponseDecoderTest extends TestCase
{
    private function options(): array
    {
        return ['response_format' => 'xml', 'xml_root' => 'resi', 'xml_list_paths' => ['resi.reso', 'resi.reso.articoli.articolo']];
    }

    public function test_json_keeps_original_types_without_xml_configuration(): void
    {
        $this->assertSame(['order' => [['id' => 1, 'amount' => 10.5, 'code' => '0012']]],
            (new StructuredApiResponseDecoder)->decode('{"order":[{"id":1,"amount":10.5,"code":"0012"}]}'));
    }

    public function test_xml_is_opt_in_and_single_records_remain_lists(): void
    {
        $xml = '<resi><reso><reso_ID>0012</reso_ID><stato_descr><![CDATA[RESO CONFERMATO]]></stato_descr><reso_prezzotot>215.00</reso_prezzotot><articoli><articolo><barcode>0123456789012</barcode></articolo></articoli></reso></resi>';
        $decoder = new StructuredApiResponseDecoder;
        $this->assertNull($decoder->decode($xml));
        $result = $decoder->decode($xml, $this->options());
        $this->assertSame('0012', $result['resi']['reso'][0]['reso_ID']);
        $this->assertSame('215.00', $result['resi']['reso'][0]['reso_prezzotot']);
        $this->assertSame('RESO CONFERMATO', $result['resi']['reso'][0]['stato_descr']);
        $this->assertSame('0123456789012', $result['resi']['reso'][0]['articoli']['articolo'][0]['barcode']);
    }

    public function test_multiple_records_and_empty_collection_have_stable_shape(): void
    {
        $decoder = new StructuredApiResponseDecoder;
        $result = $decoder->decode('<resi><reso><id>1</id></reso><reso><id>2</id></reso></resi>', $this->options());
        $this->assertCount(2, $result['resi']['reso']);
        $this->assertSame(['resi' => ['reso' => []]], $decoder->decode('<resi/>', $this->options()));
    }

    public function test_rejects_invalid_xml_error_envelopes_entities_and_large_documents(): void
    {
        $decoder = new StructuredApiResponseDecoder;
        foreach (['<resi>', '<response><ack>KO</ack><message>Invalid filter</message></response>',
            '<!DOCTYPE resi [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><resi>&xxe;</resi>',
            '<!DOCTYPE resi [<!ENTITY a "test">]><resi>&a;</resi>', str_repeat('x', 2 * 1024 * 1024 + 1)] as $xml) {
            $this->assertNull($decoder->decode($xml, $this->options()));
        }
    }
}
