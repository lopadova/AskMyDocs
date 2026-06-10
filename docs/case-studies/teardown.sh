#!/usr/bin/env bash
#
# teardown.sh — rimuove i 3 dataset di case study: documenti + chunk + grafo
# (kb_nodes / kb_edges) + file sul disco "kb", e opzionalmente le ProjectMembership.
#
# Tutta la logica distruttiva passa dal servizio DocumentDeleter::delete($doc, true),
# che cascata su chunk + kb_nodes + kb_edges e rimuove il file tramite il disco "kb"
# CONFIGURATO (locale o S3) — niente path hard-coded. Ogni query è scopata sul
# TENANT corrente (R30): non tocca documenti/membership di altri tenant che
# condividono la stessa project_key. La cancellazione è chunked (R3).
#
# Le ProjectMembership dei 3 progetti restano per default (sono inerti: senza
# documenti non danno accesso ad alcun dato). Passa --memberships per rimuoverle.
#
# Uso:
#   ./teardown.sh                # hard delete dei documenti dei 3 progetti (tenant corrente)
#   ./teardown.sh --memberships  # rimuove anche le ProjectMembership dei 3 progetti
#
# GUARDRAIL: lo script è BLOCCATO fuori da APP_ENV=local|testing. Per forzare
# (sconsigliato, p.es. su uno staging usa-e-getta) esegui con  ALLOW_NONLOCAL=1 .

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
cd "${ROOT}"

DROP_MEMBERSHIPS=0
[ "${1:-}" = "--memberships" ] && DROP_MEMBERSHIPS=1

# --- Guardrail: solo ambienti usa-e-getta -----------------------------------
APP_ENV_NOW="$(php artisan tinker --execute='echo app()->environment();' 2>/dev/null | tail -n1 | tr -d '[:space:]')"
if [ "${APP_ENV_NOW}" != "local" ] && [ "${APP_ENV_NOW}" != "testing" ]; then
  if [ "${ALLOW_NONLOCAL:-0}" != "1" ]; then
    echo "!! APP_ENV='${APP_ENV_NOW}' non è local/testing: teardown distruttivo BLOCCATO."
    echo "   Per forzare (sconsigliato): ALLOW_NONLOCAL=1 $0 ${1:-}"
    exit 1
  fi
  echo "** ATTENZIONE: teardown distruttivo con APP_ENV='${APP_ENV_NOW}' (ALLOW_NONLOCAL=1)."
fi

echo "==> AskMyDocs — teardown dei 3 dataset di case study (tenant corrente)"

# Cancellazione documenti+grafo+file, tenant-scoped e chunked, via DocumentDeleter.
php artisan tinker --execute='
  $keys = ["rotta-logistics","prometeo-antincendio","passolibero-calzature"];
  $tenant  = app(\App\Support\TenantContext::class)->current();
  $deleter = app(\App\Services\Kb\DocumentDeleter::class);
  $prefix  = trim((string) config("kb.sources.path_prefix", ""), "/");

  foreach ($keys as $key) {
    $count = 0;
    \App\Models\KnowledgeDocument::withTrashed()
      ->forTenant($tenant)
      ->where("project_key", $key)
      ->chunkById(100, function ($docs) use ($deleter, &$count) {
        foreach ($docs as $d) { $deleter->delete($d, true); $count++; }
      });
    // Rimuove la directory (ormai vuota) sul disco kb configurato, rispettando KB_PATH_PREFIX.
    $dir = ($prefix === "" ? "" : $prefix."/")."case-studies/".$key;
    \Illuminate\Support\Facades\Storage::disk("kb")->deleteDirectory($dir);
    echo "$key: $count documenti rimossi (tenant=$tenant)\n";
  }
'

if [ "${DROP_MEMBERSHIPS}" = "1" ]; then
  echo "==> Rimuovo le ProjectMembership dei 3 progetti (tenant corrente)"
  php artisan tinker --execute='
    $tenant = app(\App\Support\TenantContext::class)->current();
    $n = \App\Models\ProjectMembership::forTenant($tenant)
      ->whereIn("project_key", ["rotta-logistics","prometeo-antincendio","passolibero-calzature"])
      ->delete();
    echo "membership rimosse: $n (tenant=$tenant)\n";
  '
fi

echo "==> Teardown completato."
