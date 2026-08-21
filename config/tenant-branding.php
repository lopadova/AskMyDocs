<?php

return [
    // Private by default. Logos are streamed through an authenticated route,
    // so neither a public storage symlink nor a world-readable bucket is needed.
    'disk' => env('TENANT_LOGO_DISK', 'tenant-logos'),
    'max_kilobytes' => (int) env('TENANT_LOGO_MAX_KB', 2048),
    'max_width' => (int) env('TENANT_LOGO_MAX_WIDTH', 2400),
    'max_height' => (int) env('TENANT_LOGO_MAX_HEIGHT', 1200),
];
