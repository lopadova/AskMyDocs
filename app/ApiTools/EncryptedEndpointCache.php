<?php

declare(strict_types=1);
namespace App\ApiTools;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/** Private cache namespace: structured connector intermediates are encrypted on every backend. */
final class EncryptedEndpointCache
{
    public function remember(string $key,int $seconds,callable $call): array
    {
        if ($seconds <= 0) { return $call(); }
        $key='api-tool:encrypted:'.$key;
        $cached=Cache::get($key);
        if (is_string($cached)) {
            try { $value=json_decode(Crypt::decryptString($cached),true,64,JSON_THROW_ON_ERROR); if (is_array($value)) { return $value; } }
            catch (\Throwable) { Cache::forget($key); }
        }
        $value=$call();
        if (! isset($value['error'])) { Cache::put($key,Crypt::encryptString(json_encode($value,JSON_THROW_ON_ERROR)),$seconds); }
        return $value;
    }
}
