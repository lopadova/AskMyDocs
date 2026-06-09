#!/usr/bin/env bash
#
# teardown.sh — rimuove i 3 dataset di case study (documenti + chunk + grafo)
# e i file markdown copiati sul disco kb. Lascia intatte le membership utente
# (innocue) salvo che venga passato --memberships.
#
# Uso:
#   ./teardown.sh                # cancella documenti dei 3 progetti (hard delete) + file su disco
#   ./teardown.sh --memberships  # cancella anche le ProjectMembership dei 3 progetti

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
cd "${ROOT}"

PROJECTS=( "rotta-logistics" "prometeo-antincendio" "passolibero-calzature" )
DROP_MEMBERSHIPS=0
[ "${1:-}" = "--memberships" ] && DROP_MEMBERSHIPS=1

echo "==> AskMyDocs — teardown dei 3 dataset di case study"

for KEY in "${PROJECTS[@]}"; do
  echo "==> [${KEY}] hard delete dei documenti del progetto"
  # DocumentDeleter::forceDelete cascata su chunk + kb_nodes + kb_edges
  php artisan tinker --execute="
    \$docs = \App\Models\KnowledgeDocument::withTrashed()->where('project_key','${KEY}')->get();
    \$deleter = app(\App\Services\Kb\DocumentDeleter::class);
    foreach (\$docs as \$d) { \$deleter->forceDelete(\$d); }
    echo '${KEY}: '.\$docs->count().\" documenti rimossi\n\";
  " || echo "   (nessun documento o servizio non disponibile per ${KEY})"

  echo "==> [${KEY}] rimuovo i file dal disco kb"
  rm -rf "${ROOT}/storage/app/kb/case-studies/${KEY}"
done

if [ "${DROP_MEMBERSHIPS}" = "1" ]; then
  echo "==> Rimuovo le ProjectMembership dei 3 progetti"
  php artisan tinker --execute='
    $n = \App\Models\ProjectMembership::whereIn("project_key",
      ["rotta-logistics","prometeo-antincendio","passolibero-calzature"])->delete();
    echo "membership rimosse: $n\n";
  '
fi

echo "==> Teardown completato."
