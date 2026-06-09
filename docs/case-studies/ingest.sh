#!/usr/bin/env bash
#
# ingest.sh — carica i 3 dataset fittizi (case study) in AskMyDocs come 3
# progetti separati, così da poter collaudare l'isolamento per progetto
# (i documenti di un'azienda NON devono mai comparire nelle risposte di un'altra).
#
# Cosa fa, per ogni azienda:
#   1. copia docs/case-studies/data/<key>/  ->  storage/app/kb/case-studies/<key>/
#      (il disco "kb" è la sorgente da cui legge l'ingestione)
#   2. lancia  php artisan kb:ingest-folder case-studies/<key> --project=<key> --recursive --sync
#   3. concede a TUTTI gli utenti esistenti la membership sul progetto (così il
#      Project Switcher in alto mostra le 3 aziende)
#   4. ricostruisce il grafo canonico (kb_nodes / kb_edges) senza bisogno di un worker
#
# Idempotente: rilanciarlo non duplica nulla (l'ingest è upsert su hash, le
# membership usano firstOrCreate). Per ripulire usa  ./teardown.sh
#
# Prerequisiti: DB migrato, chiavi AI per gli embeddings configurate
# (AI_EMBEDDINGS_PROVIDER + relativa API key), eseguito dalla root del progetto.

set -euo pipefail

# --- vai alla root del progetto (due livelli sopra questo script) -----------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
cd "${ROOT}"

DISK_ROOT="${ROOT}/storage/app/kb"
KB_SUBDIR="case-studies"

# Le 3 aziende: <cartella sorgente> == <project key>
PROJECTS=(
  "rotta-logistics"
  "prometeo-antincendio"
  "passolibero-calzature"
)

echo "==> AskMyDocs — ingest dei 3 dataset di case study"
echo "    root: ${ROOT}"
echo

for KEY in "${PROJECTS[@]}"; do
  SRC="${SCRIPT_DIR}/data/${KEY}"
  DST="${DISK_ROOT}/${KB_SUBDIR}/${KEY}"

  if [ ! -d "${SRC}" ]; then
    echo "!! sorgente mancante: ${SRC} — salto ${KEY}"
    continue
  fi

  echo "==> [${KEY}] copia documenti sul disco kb"
  mkdir -p "${DST}"
  # copia solo i .md (niente file di servizio)
  cp -f "${SRC}"/*.md "${DST}/" 2>/dev/null || true
  COUNT=$(find "${DST}" -maxdepth 1 -name '*.md' | wc -l | tr -d ' ')
  echo "    ${COUNT} file in ${DST}"

  echo "==> [${KEY}] ingest (sync) nel progetto '${KEY}'"
  php artisan kb:ingest-folder "${KB_SUBDIR}/${KEY}" \
    --project="${KEY}" \
    --recursive \
    --sync

  echo
done

echo "==> Concedo la membership dei 3 progetti a tutti gli utenti (Project Switcher)"
php artisan tinker --execute='
  $keys = ["rotta-logistics","prometeo-antincendio","passolibero-calzature"];
  foreach (\App\Models\User::all() as $u) {
    foreach ($keys as $k) {
      \App\Models\ProjectMembership::firstOrCreate(
        ["user_id" => $u->id, "project_key" => $k],
        ["role" => "member", "scope_allowlist" => null]
      );
    }
  }
  echo "membership ok\n";
'

echo "==> Ricostruisco il grafo canonico (kb_nodes / kb_edges)"
# Senza un queue worker, il CanonicalIndexerJob potrebbe non essere ancora
# girato: rebuild-graph ripopola i nodi/archi dai documenti canonici in modo
# sincrono. È un no-op se non ci sono documenti canonici.
for KEY in "${PROJECTS[@]}"; do
  php artisan kb:rebuild-graph --project="${KEY}" || true
done

# Se la coda è su redis/database, svuota gli eventuali job residui
php artisan queue:work --stop-when-empty --tries=1 >/dev/null 2>&1 || true

echo
echo "==> FATTO. Aprire la SPA, fare login, e dal Project Switcher (in alto)"
echo "    scegliere una delle 3 aziende:"
echo "      - rotta-logistics        (Rotta Sicura Logistics — logistica)"
echo "      - prometeo-antincendio   (Prometeo Sicurezza Antincendio — vigili del fuoco)"
echo "      - passolibero-calzature  (PassoLibero Calzature — scarpe)"
echo
echo "    Per i test di isolamento vedere docs/case-studies/README.md"
