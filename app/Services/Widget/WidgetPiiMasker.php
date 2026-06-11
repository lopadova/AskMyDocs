<?php

declare(strict_types=1);

namespace App\Services\Widget;

/**
 * WidgetPiiMasker — maschera PII (email, telefono, IBAN, token/card) nei
 * payload JSON e nei log PRIMA del salvataggio e della scrittura su log.
 *
 * Port di KittPiiMasker adattato al pattern AskMyDocs. Applicato da
 * WidgetOrchestratorService::addStep() su args_json e diagnostic_json
 * e dal replay endpoint (M5.9) prima di restituire i dati.
 *
 * Difesa in profondità: il FE fa data-kitt-sensitive, ma il BE non si fida.
 * I pattern coprono PII tipica dei form (contatti, pagamenti) che potrebbe
 * finire in args_json o diagnostic_json durante il loop ReAct.
 */
final class WidgetPiiMasker
{
    /**
     * Mask una stringa rimpiazzando i pattern PII con placeholder.
     * I pattern coprono: email, telefono (IT/intl), IBAN, numeri carta,
     * token API/segreti comuni (sk_, pk_, Bearer, JWT), Codice Fiscale e
     * Partita IVA italiani (#42). Per una detection più ricca esiste
     * padosoft/laravel-pii-redactor; questo masker resta always-on e leggero.
     */
    public function maskString(string $input): string
    {
        // 1. Email — qualunque <local>@<domain>
        $result = preg_replace(
            '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
            '[EMAIL]',
            $input,
        );

        // 2. IBAN — 2 lettere + 2 cifre di check + fino a 30 alfanumerici (spazi ammessi)
        $result = preg_replace(
            '/\b[A-Z]{2}\d{2}[A-Z0-9 ]{11,30}\b/',
            '[IBAN]',
            $result,
        );

        // 3. Numeri carta — 13-19 cifre, spazi/dash ammessi
        $result = preg_replace(
            '/\b\d{4}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{1,4}\b/',
            '[CARD]',
            $result,
        );

        // 4. Telefono — +39… o 0039… o 3xx locale IT, e formato intl generico
        $result = preg_replace(
            '/(?:\+39|0039)\s*[\d\s.\-]{6,15}|\b3\d{2}[\s.\-]?\d{3,7}\b|\+\d{1,3}\s*[\d\s.\-]{6,15}/',
            '[PHONE]',
            $result,
        );

        // 5. Token/secret comuni — sk_live_…, pk_live_…, Bearer …, token API
        $result = preg_replace(
            '/\b(sk|pk)_(live|test|key)_[a-zA-Z0-9]{10,}\b/',
            '[$1_***]',
            $result,
        );
        $result = preg_replace(
            '/\bBearer\s+[a-zA-Z0-9.\-_]{20,}\b/',
            'Bearer [TOKEN]',
            $result,
        );

        // 6. Token generici lunghi (JWT-like: xxx.yyy.zzz) — almeno 20 chars per segment
        $result = preg_replace(
            '/\beyJ[a-zA-Z0-9_\-]{10,}\.[a-zA-Z0-9_\-]{10,}/',
            '[JWT]',
            $result,
        );

        // 7. Codice Fiscale italiano (#42) — 6 lettere, 2 cifre, 1 lettera, 2 cifre,
        // 1 lettera, 3 cifre, 1 lettera (16 char). Era una delle PII IT che il
        // padosoft/laravel-pii-redactor copre e questo masker no (drift GDPR).
        $result = preg_replace(
            '/\b[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]\b/i',
            '[CF]',
            $result,
        ) ?? $result;

        // 8. Partita IVA italiana (#42) — 11 cifre. Le carte (13-19 cifre) e gli
        // IBAN sono già mascherati sopra, quindi qui restano solo gli 11-cifre.
        $result = preg_replace(
            '/\b\d{11}\b/',
            '[VAT]',
            $result,
        ) ?? $result;

        return $result;
    }

    /**
     * Maschera ricorsivamente tutti i valori stringa in un array/JSON.
     * I valori non-stringa (int, bool, null, array annidati) passano invariati.
     *
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    public function maskArray(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        return $this->maskRecursive($data);
    }

    /**
     * Maschera il contenuto JSON di un campo (longText) ritornando la
     * stringa JSON encodata. Se il JSON non è validamente decodificabile,
     * maschera la stringa grezza (difesa in profondità).
     */
    public function maskJsonString(?string $json): ?string
    {
        if ($json === null || $json === '') {
            return $json;
        }

        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $masked = $this->maskRecursive($decoded);

            return (string) json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Fallback: maschera la stringa grezza
        return $this->maskString($json);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function maskRecursive(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $result[$key] = $this->maskString($value);
            } elseif (is_array($value)) {
                $result[$key] = $this->maskRecursive($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}