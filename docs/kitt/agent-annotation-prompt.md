# KITT — Prompt per annotare la DOM con un agente AI

Questo documento contiene un **prompt pronto da incollare** in un agente di
coding (Claude Code, GitHub Copilot, Cursor, …) per fargli annotare la DOM del
tuo sito con il contratto `data-kitt-*` che il widget KITT legge. Più la pagina
è annotata, meno KITT "improvvisa" sull'outline euristico, e più affidabile
diventa il supporto a **compilazione form** e **navigazione**.

- **Pagina di riferimento "fatta bene"**: [`example-annotated-page.html`](./example-annotated-page.html)
- **Contratto letto dal widget**: `frontend/src/widget/dom/snapshot.ts`
- **Catalogo tool / verb**: `app/Services/Widget/WidgetToolCatalog.php`

---

## Come usarlo

1. Apri il file (o i componenti) della pagina che vuoi rendere KITT-friendly.
2. Incolla il prompt qui sotto nell'agente, allegando il markup target.
3. Rivedi il diff: l'agente deve **solo aggiungere attributi**, mai cambiare
   struttura, classi, comportamento o testo.
4. Verifica con la checklist finale.

> Nota: l'annotazione manuale e quella automatica (regole CSS nel *manifest* di
> skill, lato AskMyDocs) **convivono**. Le regole automatiche non sovrascrivono
> mai un `data-kitt-*` già presente. Usa il manuale per i casi delicati
> (campi sensibili, wizard, azioni distruttive) e lascia le regole generiche
> al manifest.

---

## ✂️ PROMPT — copia da qui

````
RUOLO
Sei un assistente che annota la DOM di una pagina web con il contratto
`data-kitt-*` del widget KITT di AskMyDocs. KITT è un assistente AI embeddabile
che legge uno "snapshot" della pagina e può compilare form e navigare per conto
dell'utente. Le tue annotazioni sono ciò che KITT vede: senza di esse tira a
indovinare sull'outline grezzo (heading/bottoni/input non etichettati).

REGOLA ASSOLUTA
Aggiungi SOLO attributi `data-kitt-*` (più, dove serve, un `id`/`for` per legare
label e input). NON cambiare struttura, tag, classi CSS, testo visibile,
handler JS o logica. L'output è un diff minimale, additivo, idempotente.

VOCABOLARIO (esattamente questi attributi — KITT non ne legge altri)

1. REGION — sezioni / step di un wizard
   <section data-kitt-region="<id-stabile>"
            data-kitt-active="true"          (solo sulla region attualmente visibile)
            data-kitt-help="A cosa serve questa sezione.">
   • Sposta `data-kitt-active="true"` sulla region visibile quando l'utente
     cambia step (se la pagina è un wizard JS, aggiorna lì lo stato).

2. FIELD — campi di input
   <div data-kitt-field="<nome-stabile>"     (snake_case, stabile nel tempo)
        data-kitt-required                    (se obbligatorio)
        data-kitt-sensitive                   (se è PII/segreto: KITT NON ne legge il valore)
        data-kitt-help="Come va compilato.">
     <label for="x">Etichetta</label>         (lega SEMPRE label↔input via for/id)
     <input id="x" name="x" data-kitt-input>  (marca l'input reale dentro il wrapper)
   </div>
   • `data-kitt-field` va sul WRAPPER; `data-kitt-input` sull'<input>/<select>/<textarea> reale.
   • Se il campo È direttamente l'input, puoi mettere `data-kitt-field` sull'input stesso.
   • Select con opzioni nel DOM: lascia le <option>, KITT le legge da solo.
   • Combobox/autocomplete con opzioni caricate via rete: aggiungi
     `data-kitt-options-source="<url-o-marker>"` sul wrapper e `role="combobox"`
     sull'input → KITT le cerca con i tool dedicati invece di inventarle.

3. ACTION — bottoni / link azionabili
   <button data-kitt-action="<verb-stabile>"  (es. "submit", "next", "back", "delete")
           data-kitt-help="Cosa fa questa azione."
           data-kitt-reason-disabled="Perché è disabilitata, se lo è.">
   • Il `verb` è un'IDENTITÀ STABILE: non cambiarlo se cambi testo/lingua/CSS.
   • Su bottoni che possono essere disabilitati, aggiungi SEMPRE
     `data-kitt-reason-disabled`: così KITT spiega all'utente perché non procede
     invece di sbatterci contro.

4. MESSAGE — banner che KITT deve leggere (errori di validazione, avvisi)
   <div data-kitt-message="error">…</div>     (livelli: "error" | "warning" | "info")
   • Solo i messaggi VISIBILI finiscono nello snapshot. KITT li usa per reagire
     (es. "vedo un errore sull'IBAN, lo correggo").

5. LOCALE — selettore lingua (se la pagina è multilingua)
   <button data-kitt-locale="it" data-kitt-active="true">IT</button>
   <button data-kitt-locale="en">EN</button>
   • KITT può cambiare lingua con il tool `set_locale` (solo tra i locale presenti).

6. SKIP — sottoalberi da ignorare (banner promo, debug, widget di terzi)
   <aside data-kitt-skip> … </aside>
   • Tutto ciò che è dentro `data-kitt-skip` è invisibile a KITT.

LINEE GUIDA DI ANNOTAZIONE
• Sicurezza prima di tutto: marca `data-kitt-sensitive` OGNI campo con
  password, IBAN, numero di carta, codice fiscale/SSN, token, dati sanitari.
• `data-kitt-help` è ad alto valore: scrivi istruzioni di compilazione concrete
  (formato, esempi, vincoli). È ciò che permette a KITT di compilare BENE.
• Nomi (`data-kitt-field`, `data-kitt-action`) stabili e descrittivi: sono una
  API. Niente indici volatili o id auto-generati che cambiano ad ogni build.
• Non annotare elementi puramente decorativi. Annota ciò su cui un utente
  agirebbe: campi, bottoni, link di navigazione, step, banner d'errore.
• Idempotenza: se un `data-kitt-*` esiste già, lascialo com'è.

COSA NON FARE
• Non inventare attributi `data-kitt-*` fuori da questo elenco.
• Non esporre valori sensibili in `data-kitt-help` o altrove.
• Non cambiare il `name`/`id` esistente se è già stabile (rompe il backend del sito).
• Non rimuovere o riordinare nodi. Solo aggiunte di attributi.

OUTPUT
Restituisci il markup annotato (o un diff additivo). In coda, elenca in forma
tabellare i field e le action create con: nome/verb, required?, sensitive?,
così posso verificarli a colpo d'occhio.

MARKUP DA ANNOTARE
<<< incolla qui l'HTML / il componente / il template Blade-React-Vue da annotare >>>
````

## ✂️ Fine prompt

---

## Variante per framework a componenti (React / Vue / Blade)

Se annoti **componenti** invece di HTML statico, aggiungi questa nota in coda al
prompt:

```
CONTESTO COMPONENTI
Questo è un componente {React|Vue|Blade}. Applica gli attributi `data-kitt-*`
come props/attributi nel template. Se il componente è riutilizzato in contesti
diversi, espone `data-kitt-field`/`data-kitt-action` come PROP configurabile
(default sensato) anziché hard-coded, così ogni istanza dichiara la propria
identità. Per i wizard, collega `data-kitt-active` allo stato dello step già
esistente nel componente — non duplicare lo stato.
```

---

## Checklist di verifica (post-annotazione)

Esegui questi controlli sul diff prodotto dall'agente:

- [ ] **Solo aggiunte**: il diff non tocca struttura, testo, classi o logica.
- [ ] **Label legate**: ogni `data-kitt-field` ha una `<label for>` o un
      `aria-label` → la label compare nello snapshot.
- [ ] **Required marcati**: ogni campo obbligatorio ha `data-kitt-required`.
- [ ] **Sensibili protetti**: password / IBAN / carta / CF / token hanno
      `data-kitt-sensitive` (il valore non deve mai uscire dal browser).
- [ ] **Verb stabili**: i `data-kitt-action` sono slug stabili, non testo
      localizzato né id auto-generati.
- [ ] **reason-disabled**: i bottoni disabilitabili spiegano il perché.
- [ ] **active coerente**: in un wizard, `data-kitt-active="true"` segue lo step
      visibile (aggiornato dallo stato JS, non statico).
- [ ] **Skip applicato**: banner/debug/terze parti sono sotto `data-kitt-skip`.
- [ ] **Messaggi d'errore** annotati con `data-kitt-message="error"`.

Quando la checklist è verde, KITT passa dal "improvviso" al "so esattamente cosa
fare" su quella pagina. Confronta sempre il risultato con
[`example-annotated-page.html`](./example-annotated-page.html) come golden
reference.
