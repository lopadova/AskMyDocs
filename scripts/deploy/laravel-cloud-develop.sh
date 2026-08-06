#!/usr/bin/env bash

set -Eeuo pipefail

commit_message_file="${DEPLOY_COMMIT_MESSAGE_FILE:-.laravel-cloud-commit-message}"
commit_message="${DEPLOY_COMMIT_MESSAGE:-}"

if [[ -z "${commit_message}" && -s "${commit_message_file}" ]]; then
    commit_message="$(<"${commit_message_file}")"
fi

if [[ -z "${commit_message}" ]] && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    commit_message="$(git log -1 --pretty=%B)"
fi

if [[ -z "${commit_message}" ]]; then
    echo "Cannot inspect the deployed commit message; refusing to guess deployment directives." >&2
    exit 1
fi

normalized_message="$(printf '%s' "${commit_message}" | tr '[:upper:]' '[:lower:]')"
reset_database=false
init_seed=false

if [[ "${normalized_message}" == *"[reset-database]"* ]]; then
    reset_database=true
fi

if [[ "${normalized_message}" == *"[init-seed]"* ]]; then
    init_seed=true
fi

is_truthy() {
    case "$(printf '%s' "${1:-}" | tr '[:upper:]' '[:lower:]')" in
        1|true|yes|on) return 0 ;;
        *) return 1 ;;
    esac
}

resolved_develop_enabled="${DEVELOP_DEPLOY_ENABLED:-}"
resolved_app_environment="${APP_ENV:-}"
resolved_seed_password="${DEVELOP_SEED_PASSWORD:-}"
resolved_seed_password_length="${#resolved_seed_password}"
unset resolved_seed_password

if [[ -z "${resolved_develop_enabled}" ]]; then
    IFS=$'\t' read -r \
        resolved_develop_enabled \
        resolved_app_environment \
        resolved_seed_password_length \
        < <(php scripts/deploy/resolve-laravel-environment.php)
fi

assert_develop_environment() {
    if ! is_truthy "${resolved_develop_enabled:-false}"; then
        echo "Refusing develop deployment directives: DEVELOP_DEPLOY_ENABLED is not true." >&2
        exit 1
    fi

    case "$(printf '%s' "${resolved_app_environment:-}" | tr '[:upper:]' '[:lower:]')" in
        local|development|develop|staging|testing) ;;
        *)
            echo "Refusing develop deployment directives for APP_ENV=${resolved_app_environment:-unset}." >&2
            exit 1
            ;;
    esac
}

if [[ "${reset_database}" == true || "${init_seed}" == true ]]; then
    assert_develop_environment
fi

if [[ "${init_seed}" == true && "${resolved_seed_password_length:-0}" -lt 12 ]]; then
    echo "Refusing [init-seed]: DEVELOP_SEED_PASSWORD is missing or shorter than 12 characters." >&2
    exit 1
fi

if [[ "${reset_database}" == true ]]; then
    echo "Directive [reset-database] found: rebuilding the develop database."
    php artisan migrate:fresh --force --no-interaction
else
    echo "No reset directive found: applying pending migrations."
    php artisan migrate --force --no-interaction
fi

if [[ "${init_seed}" == true ]]; then
    echo "Directive [init-seed] found: loading deterministic develop fixtures."
    php artisan db:seed '--class=Database\Seeders\DevelopSeeder' --force --no-interaction
else
    echo "No develop seed directive found."
fi

echo "Develop deployment database steps completed."
