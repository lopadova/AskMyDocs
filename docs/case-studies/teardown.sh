#!/usr/bin/env bash
#
# teardown.sh — rimuove un target case-study ESPLICITO, tenant + progetto.
# Senza --dataset-version fa hard delete di documenti, chunk, grafo e file del
# progetto; con --dataset-version fa solo soft delete delle email generate di
# quella versione.
#
# Tutta la logica distruttiva passa dal servizio DocumentDeleter::delete($doc, true),
# che cascata su chunk + kb_nodes + kb_edges e rimuove il file tramite il disco "kb"
# CONFIGURATO (locale o S3) — niente path hard-coded. Ogni query è scopata sul
# TENANT corrente (R30): non tocca documenti/membership di altri tenant che
# condividono la stessa project_key. La cancellazione è chunked (R3).
#
# Le ProjectMembership restano per default. Passa --memberships solo nel teardown
# completo; è incompatibile con un rollback email per dataset version.
#
# Uso:
#   ./teardown.sh --tenant=rotta-logistics --project=rotta-logistics
#   ./teardown.sh --tenant=rotta-logistics --project=rotta-logistics --memberships
#   ./teardown.sh --tenant=rotta-logistics --project=rotta-logistics \
#     --dataset-version=case-study-email-v2-g1-large-s20260723-catalogv1-snapa48c0f4751b501df
#
# GUARDRAIL: lo script è BLOCCATO fuori da APP_ENV=local|testing. Per forzare
# (sconsigliato, p.es. su uno staging usa-e-getta) esegui con  ALLOW_NONLOCAL=1 .

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
cd "${ROOT}"

DROP_MEMBERSHIPS=0
TENANT_ID=""
PROJECT_KEY=""
DATASET_VERSION=""

for ARG in "$@"; do
  case "${ARG}" in
    --tenant=*) TENANT_ID="${ARG#--tenant=}" ;;
    --project=*) PROJECT_KEY="${ARG#--project=}" ;;
    --dataset-version=*) DATASET_VERSION="${ARG#--dataset-version=}" ;;
    --memberships) DROP_MEMBERSHIPS=1 ;;
    *)
      echo "!! opzione sconosciuta: ${ARG}"
      exit 2
      ;;
  esac
done

if [[ ! "${TENANT_ID}" =~ ^[a-z0-9][a-z0-9-]*$ ]]; then
  echo "!! --tenant=<slug> è obbligatorio e deve essere esplicito."
  exit 2
fi
if [[ ! "${PROJECT_KEY}" =~ ^[a-z0-9][a-z0-9-]*$ ]]; then
  echo "!! --project=<project_key> è obbligatorio e deve essere esplicito."
  exit 2
fi
if [ -n "${DATASET_VERSION}" ] && [[ ! "${DATASET_VERSION}" =~ ^[a-z0-9-]+$ ]]; then
  echo "!! --dataset-version contiene caratteri non validi."
  exit 2
fi
if [ -n "${DATASET_VERSION}" ] && [ "${DROP_MEMBERSHIPS}" = "1" ]; then
  echo "!! --memberships non è ammesso nel rollback di una dataset version."
  exit 2
fi

# --- guard: tinker è una dipendenza dev (composer install con dev) -----------
# Senza questo check, un'installazione --no-dev produrrebbe APP_ENV vuoto e un
# messaggio "BLOCCATO" fuorviante invece della vera causa.
TINKER_OK="$(php artisan list --raw 2>/dev/null | grep -c '^tinker' || true)"
if [ "${TINKER_OK:-0}" = "0" ]; then
  echo "!! 'php artisan tinker' non disponibile (laravel/tinker è una dipendenza dev)."
  echo "   Esegui 'composer install' (con i pacchetti dev) oppure 'composer require laravel/tinker'."
  exit 1
fi

# La cartella sorgente è la allowlist dei project_key case-study.
if [ ! -d "${SCRIPT_DIR}/data/${PROJECT_KEY}" ]; then
  echo "!! project_key non presente nei case study: ${PROJECT_KEY}"
  exit 1
fi

# --- Guardrail: solo ambienti usa-e-getta -----------------------------------
APP_ENV_NOW="$(php artisan tinker --execute='echo app()->environment();' 2>/dev/null | tail -n1 | tr -d '[:space:]')"
if [ "${APP_ENV_NOW}" != "local" ] && [ "${APP_ENV_NOW}" != "testing" ]; then
  if [ "${ALLOW_NONLOCAL:-0}" != "1" ]; then
    echo "!! APP_ENV='${APP_ENV_NOW}' non è local/testing: teardown distruttivo BLOCCATO."
    echo "   Per forzare (sconsigliato): ALLOW_NONLOCAL=1 $0 $*"
    exit 1
  fi
  echo "** ATTENZIONE: teardown distruttivo con APP_ENV='${APP_ENV_NOW}' (ALLOW_NONLOCAL=1)."
fi

if [ -n "${DATASET_VERSION}" ]; then
  echo "==> Rollback email dataset '${DATASET_VERSION}' (tenant=${TENANT_ID}, project=${PROJECT_KEY})"
else
  echo "==> Teardown completo case study (tenant=${TENANT_ID}, project=${PROJECT_KEY})"
fi

# Cancellazione tenant/project-scoped e chunked via DocumentDeleter.
CASE_STUDY_TENANT="${TENANT_ID}" \
CASE_STUDY_PROJECT="${PROJECT_KEY}" \
CASE_STUDY_DATASET_VERSION="${DATASET_VERSION}" \
php artisan tinker --execute='
  $tenant  = (string) getenv("CASE_STUDY_TENANT");
  $project = (string) getenv("CASE_STUDY_PROJECT");
  $version = (string) getenv("CASE_STUDY_DATASET_VERSION");
  $deleter = app(\App\Services\Kb\DocumentDeleter::class);
  $prefix  = trim((string) config("kb.sources.path_prefix", ""), "/");

  $count = 0;
  $query = \App\Models\KnowledgeDocument::query()
    ->forTenant($tenant)
    ->where("project_key", $project);

  if ($version !== "") {
    $query
      ->where("metadata->generated_fixture", true)
      ->where("metadata->dataset_version", $version);
  } else {
    $query->withTrashed();
  }

  $query->chunkById(100, function ($docs) use ($deleter, $version, &$count) {
    foreach ($docs as $document) {
      $deleter->delete($document, force: $version === "");
      $count++;
    }
  });

  if ($version === "") {
    // Il path è cancellato solo nel teardown completo di questo project_key.
    $dir = ($prefix === "" ? "" : $prefix."/")."case-studies/".$project;
    if (\Illuminate\Support\Facades\Storage::disk("kb")->deleteDirectory($dir) === false) {
      throw new \RuntimeException("Rimozione della directory fallita sul disco kb: ".$dir);
    }
  }
  echo "$project: $count documenti ".($version === "" ? "rimossi" : "soft-deleted")
    ." (tenant=$tenant".($version === "" ? "" : ", dataset=$version").")\n";
'

if [ "${DROP_MEMBERSHIPS}" = "1" ]; then
  echo "==> Rimuovo le ProjectMembership del target"
  CASE_STUDY_TENANT="${TENANT_ID}" \
  CASE_STUDY_PROJECT="${PROJECT_KEY}" \
  php artisan tinker --execute='
    $tenant = (string) getenv("CASE_STUDY_TENANT");
    $project = (string) getenv("CASE_STUDY_PROJECT");
    $n = \App\Models\ProjectMembership::forTenant($tenant)
      ->where("project_key", $project)
      ->delete();
    echo "membership rimosse: $n (tenant=$tenant, project=$project)\n";
  '
fi

echo "==> Teardown completato."
