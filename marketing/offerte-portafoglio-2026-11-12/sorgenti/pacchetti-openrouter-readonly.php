<?php
// Solo GET di metadati della chiave locale. Nessuna generazione, nessun valore segreto in output.
declare(strict_types=1);
$billingProject = '/Users/marco/www/AskMyDocsDev';
require $billingProject.'/vendor/autoload.php';
$billingEnv = Dotenv\Dotenv::parse(file_get_contents($billingProject.'/.env'));
$billingKey = $billingEnv['OPENROUTER_API_KEY'] ?? null;
if (!$billingKey) {
    echo json_encode(['configured'=>false], JSON_PRETTY_PRINT).PHP_EOL;
    exit;
}
$billingCurl = curl_init('https://openrouter.ai/api/v1/key');
curl_setopt_array($billingCurl, [
    CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20,
    CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$billingKey,'Accept: application/json'],
]);
$billingResponse = curl_exec($billingCurl);
$billingStatus = curl_getinfo($billingCurl, CURLINFO_HTTP_CODE);
$billingData = json_decode($billingResponse ?: '', true)['data'] ?? [];
$billingAllowed = ['usage','usage_daily','usage_weekly','usage_monthly','byok_usage','byok_usage_monthly','limit','limit_remaining','limit_reset','is_free_tier'];
echo json_encode([
    'checked_at'=>gmdate('c'), 'http_status'=>$billingStatus,
    'scope'=>'Chiave configurata nel solo env locale AskMyDocsDev; non dimostra identità con produzione o uso esclusivo del prodotto',
    'data'=>array_intersect_key($billingData,array_flip($billingAllowed)),
], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
