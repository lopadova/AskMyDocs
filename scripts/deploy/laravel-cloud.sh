#!/usr/bin/env bash

set -euo pipefail

bash scripts/deploy/pact-client.sh --prepare-only
php artisan migrate --force --no-interaction
bash scripts/deploy/pact-client.sh
