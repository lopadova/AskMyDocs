ASKMYDOCS / DIARIO DI PRODOTTO / OTTOBRE 2026

Consegna locale: report editoriale in italiano, 23 pagine PDF, versione HTML
autonoma con registro filtrabile, CSV e JSON di evidenza.

Periodo: 1-29 ottobre 2026. Non comprende il 30-31 ottobre.
Baseline app: cd1e917f7b37aed43ad33881e7d8146f81748700.
Snapshot app: 8003ca793c0218ec13451b449fa02a874de762a0.
Il riferimento remoto origin/develop è stato verificato via API GitHub.

SORGENTI EDITORIALI
- contenuti.py: narrazione, fonti, matrice dei pacchetti e rami separati.
- build_report.py: generazione PDF/HTML e controllo dei percorsi citati.
- collect_audit.py: raccolta read-only della cronologia e del lockfile.
- marketing/immagini/ottobre-2026/direzione.json: progetto visivo e asset.

OUTPUT LOCALI
- marketing/pdf/askmydocs-ottobre-2026.pdf
- marketing/pdf/askmydocs-ottobre-2026.html
- marketing/fonti/ottobre-2026/audit.json
- marketing/fonti/ottobre-2026/registro-commit.csv
- marketing/fonti/ottobre-2026/fonti.json
- marketing/fonti/ottobre-2026/layout-verifica.json
- marketing/fonti/ottobre-2026/consegna.json

RIGENERARE LA STESSA EDIZIONE
Usare il runtime Python del bundle Codex con ReportLab e Pillow, dalla root
della repository, mantenendo presente audit.json della consegna:

python3 marketing/pdf/sorgenti/ottobre-2026/build_report.py

Il rendering usa i font macOS Georgia/Arial e gli asset originali del prodotto.
Non legge .env, database applicativi, token, mailbox o dati cliente. Non chiama
provider e non modifica branch, versioni del prodotto o ambienti esterni.

RACCOLTA DELLE FONTI
Le query GitHub sono state eseguite con il connettore in sola lettura sui rami
predefiniti dei 34 repository di pacchetto; le risposte compatte sono conservate
in audit.json. Per rifare la sola raccolta locale riusando quello snapshot:

python3 marketing/pdf/sorgenti/ottobre-2026/collect_audit.py \
  --remote-cache marketing/fonti/ottobre-2026/audit.json

La raccolta locale osserva gli HEAD correnti dei pacchetti. Per ricostruire
esattamente la consegna originale, conservare audit.json prima di una nuova
raccolta. Il report cita gli SHA, non i contenuti locali di vendor.

VERIFICHE DELLA CONSEGNA
- Tutte le 23 pagine renderizzate e controllate visivamente.
- Percorsi citati verificati nei commit; riferimenti remoti verificati via API.
- 39 fonti con revisioni complete; 248 commit core integrati.
- Registro di 372 righe, inclusi snapshot locali/remoti e 46 commit core esterni.
  Questi perimetri si sovrappongono: le righe non sono un conteggio di commit unici.
- HTML provato in Chromium a 1440 e 390 pixel: ricerca, filtro di perimetro,
  immagini, ancore, assenza di overflow orizzontale e di errori JavaScript.
- Nessuna suite applicativa è stata rieseguita: i test descritti nel report
  provengono dai registri storici citati e non vengono sommati.

I file derivati PDF, HTML, CSV e JSON di audit restano locali ed esclusi dal
commit, secondo l'accordo sui file generati. Il commit riguarda sorgenti e
direzione editoriale. Nessun push, PR o pubblicazione fa parte della consegna.
