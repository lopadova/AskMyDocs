#!/usr/bin/env bash
#
# ingest.sh — carica i 3 dataset fittizi (case study) in AskMyDocs come 3
# progetti separati, così da poter collaudare l'isolamento per progetto
# (i documenti di un'azienda NON devono mai comparire nelle risposte di un'altra).
#
# Cosa fa, per ogni azienda:
#   1. copia docs/case-studies/data/<key>/  ->  <root del disco kb>/<KB_PATH_PREFIX>/case-studies/<key>/
#      (il disco "kb" è la sorgente da cui legge l'ingestione; root e prefix sono
#       risolti dalla CONFIG, non hard-coded)
#   2. lancia  php artisan kb:ingest-folder case-studies/<key> --project=<key> --recursive --sync
#   3. concede a TUTTI gli utenti esistenti la membership sul progetto (tenant-scoped),
#      così il Project Switcher in alto mostra le 3 aziende
#   4. ricostruisce il grafo canonico (kb_nodes / kb_edges) senza bisogno di un worker
#
# Idempotente: rilanciarlo non duplica nulla (l'ingest è upsert su hash, le
# membership usano firstOrCreate). Per ripulire usa  ./teardown.sh
#
# Prerequisiti: disco "kb" su filesystem LOCALE (questo helper copia con cp), DB
# migrato, chiavi AI per gli embeddings configurate (AI_EMBEDDINGS_PROVIDER +
# relativa API key), e `php artisan tinker` disponibile (laravel/tinker è una
# dipendenza dev: serve un `composer install` CON i pacchetti dev). Per dischi
# remoti (es. S3) ingesta caricando i file via Storage::disk('kb')->put() e poi
# lancia kb:ingest-folder.

set -euo pipefail

# --- vai alla root del progetto (due livelli sopra questo script) -----------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
cd "${ROOT}"

KB_SUBDIR="case-studies"

# --- guard: tinker è una dipendenza dev (composer install con dev) -----------
# Senza questo check, un'installazione --no-dev fallirebbe più avanti con
# messaggi fuorvianti (config del disco non risolta, membership saltate).
TINKER_OK="$(php artisan list --raw 2>/dev/null | grep -c '^tinker' || true)"
if [ "${TINKER_OK:-0}" = "0" ]; then
  echo "!! 'php artisan tinker' non disponibile (laravel/tinker è una dipendenza dev)."
  echo "   Esegui 'composer install' (con i pacchetti dev) oppure 'composer require laravel/tinker'."
  exit 1
fi

# --- risolvi disco kb (driver/root/prefix) dalla CONFIG, non hard-coded ------
# tab-separato così un eventuale spazio nella root non rompe il parsing.
IFS=$'\t' read -r KB_DRIVER KB_ROOT KB_PREFIX <<<"$(php artisan tinker --execute='
  echo config("filesystems.disks.kb.driver")."\t".config("filesystems.disks.kb.root")."\t".trim((string) config("kb.sources.path_prefix"), "/");
' 2>/dev/null | tail -n1)"

if [ "${KB_DRIVER}" != "local" ]; then
  echo "!! Il disco 'kb' non è local (driver='${KB_DRIVER:-?}'): questo helper copia i file solo su filesystem locale."
  echo "   Per dischi remoti (es. S3) carica i file via Storage::disk('kb')->put() e poi lancia kb:ingest-folder."
  exit 1
fi
if [ -z "${KB_ROOT}" ]; then
  echo "!! Root del disco 'kb' non risolta dalla config. Interrompo."
  exit 1
fi

# base assoluta sul disco kb, rispettando KB_PATH_PREFIX
KB_BASE="${KB_ROOT}/${KB_PREFIX:+${KB_PREFIX}/}${KB_SUBDIR}"

# Le aziende: <cartella sorgente> == <project key>, derivate da data/ così la
# lista vive in UN solo posto (aggiungere un dataset = aggiungere una cartella).
PROJECTS=()
shopt -s nullglob
for d in "${SCRIPT_DIR}/data"/*/; do
  PROJECTS+=("$(basename "${d}")")
done
shopt -u nullglob
if [ "${#PROJECTS[@]}" -eq 0 ]; then
  echo "!! nessun dataset in ${SCRIPT_DIR}/data — niente da ingerire."
  exit 1
fi
# Stessa lista per i blocchi tinker (CSV via env: niente escaping fragile).
KEYS_CSV="$(IFS=,; echo "${PROJECTS[*]}")"

echo "==> AskMyDocs — ingest dei 3 dataset di case study"
echo "    root progetto: ${ROOT}"
echo "    disco kb     : driver=${KB_DRIVER} root=${KB_ROOT} prefix='${KB_PREFIX}'"
echo

for KEY in "${PROJECTS[@]}"; do
  SRC="${SCRIPT_DIR}/data/${KEY}"
  DST="${KB_BASE}/${KEY}"

  if [ ! -d "${SRC}" ]; then
    echo "!! sorgente mancante: ${SRC} — salto ${KEY}"
    continue
  fi

  # Raccogli .md e .markdown (R18: il pipeline accetta entrambe le estensioni).
  shopt -s nullglob
  MDS=( "${SRC}"/*.md "${SRC}"/*.markdown )
  shopt -u nullglob
  if [ "${#MDS[@]}" -eq 0 ]; then
    echo "!! nessun documento .md/.markdown in ${SRC} — salto ${KEY}"
    continue
  fi

  echo "==> [${KEY}] copia ${#MDS[@]} documenti sul disco kb -> ${DST}"
  mkdir -p "${DST}"
  cp -f "${MDS[@]}" "${DST}/"   # niente swallow: con set -e un cp fallito ferma lo script

  echo "==> [${KEY}] ingest (sync) nel progetto '${KEY}'"
  php artisan kb:ingest-folder "${KB_SUBDIR}/${KEY}" \
    --project="${KEY}" \
    --recursive \
    --sync

  echo
done

echo "==> Concedo la membership dei progetti a tutti gli utenti (tenant corrente)"
USER_COUNT="$(php artisan tinker --execute='echo \App\Models\User::count();' | tail -n1 | tr -d '[:space:]')"
if [ "${USER_COUNT:-0}" = "0" ]; then
  echo "!! Nessun utente nel database: nessuna membership creata."
  echo "   Crea/seeda gli utenti e RILANCIA ./ingest.sh (è idempotente): senza"
  echo "   membership il Project Switcher NON mostrerà le aziende dei case study."
else
  CASE_STUDY_KEYS="${KEYS_CSV}" php artisan tinker --execute='
    $keys = array_filter(array_map("trim", explode(",", (string) getenv("CASE_STUDY_KEYS"))));
    $tenant = app(\App\Support\TenantContext::class)->current();
    $userIds = \App\Models\User::query()->pluck("id");
    foreach ($userIds as $uid) {
      foreach ($keys as $k) {
        \App\Models\ProjectMembership::firstOrCreate(
          ["tenant_id" => $tenant, "user_id" => $uid, "project_key" => $k],
          ["role" => "member", "scope_allowlist" => null]
        );
      }
    }
    echo count($userIds)." utenti x ".count($keys)." progetti: membership ok (tenant=$tenant)\n";
  '
fi

echo "==> Ricostruisco il grafo canonico (kb_nodes / kb_edges)"
# --sync esegue gli indexer INLINE (nessun worker necessario): senza il flag il
# comando troncherebbe il grafo e ACCODEREBBE i job, lasciandolo vuoto finché la
# coda non viene drenata. Il rebuild è idempotente e fa da rete di sicurezza:
# ri-indicizza anche i doc già processati dall'ingest --sync (costo accettabile
# per ~33 documenti). È un no-op se non ci sono documenti canonici.
for KEY in "${PROJECTS[@]}"; do
  php artisan kb:rebuild-graph --project="${KEY}" --sync
done

# Se la coda è su redis/database, processa gli eventuali job residui.
# Non silenziamo l'esito: se qualcosa fallisce, lo mostriamo + hint diagnostico.
echo "==> Processo gli eventuali job in coda"
if ! php artisan queue:work --stop-when-empty --tries=1; then
  echo "!! queue:work è fallito: il dataset potrebbe essere indicizzato solo parzialmente."
  echo "   Ispeziona i job falliti con: php artisan queue:failed"
  exit 1
fi

echo
echo "==> FATTO. Aprire la SPA, fare login, e dal Project Switcher (in alto)"
echo "    scegliere una delle 3 aziende:"
echo "      - rotta-logistics        (Rotta Sicura Logistics — logistica)"
echo "      - prometeo-antincendio   (Prometeo Sicurezza Antincendio — vigili del fuoco)"
echo "      - passolibero-calzature  (PassoLibero Calzature — scarpe)"
echo
echo "    Per i test di isolamento vedere docs/case-studies/README.md"
