#!/usr/bin/env bash

set -euo pipefail

# The SDK installer requires uncached config and clears routes. Give the
# migration process its own cache paths and preserve the release caches.
pact_deploy_cache_dir="$(mktemp -d)"
trap 'rmdir "${pact_deploy_cache_dir}"' EXIT

APP_CONFIG_CACHE="${pact_deploy_cache_dir}/config.php" \
APP_ROUTES_CACHE="${pact_deploy_cache_dir}/routes.php" \
    php artisan pact-client:deploy --no-interaction "$@"
