<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\AiServiceProvider::class,
    App\Providers\ChatLogServiceProvider::class,
    // v4.1/W4.1.B — PII redactor package SP. Listed explicitly because
    // package auto-discovery via `bootstrap/cache/packages.php` is
    // brittle on the Windows + Herd dev environment (artisan
    // `package:discover` intermittently flags the cache dir as
    // unwritable even when it isn't). Listing it here is a no-op
    // when auto-discovery succeeds and a safety net when it doesn't.
    Padosoft\PiiRedactor\PiiRedactorServiceProvider::class,
];
