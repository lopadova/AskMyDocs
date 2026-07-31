#!/usr/bin/env bash

set -Eeuo pipefail

destination="${DEPLOY_COMMIT_MESSAGE_FILE:-.laravel-cloud-commit-message}"

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "Unable to capture the deployment commit message: Git metadata is unavailable." >&2
    exit 1
fi

git log -1 --pretty=%B > "${destination}"

if [[ ! -s "${destination}" ]]; then
    echo "Unable to capture the deployment commit message: the generated file is empty." >&2
    exit 1
fi

echo "Captured deployment commit message for the deploy stage."
