from pathlib import Path
import importlib.util,json,shutil
from docx import Document
from docx.oxml import OxmlElement
from docx.text.paragraph import Paragraph
from docx.shared import Pt

HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[1]
PRIOR=ROOT/'marketing/offerta-pacchetti-rev07-2026-11-12'
spec=importlib.util.spec_from_file_location('previous_offer',PRIOR/'build.py')
previous=importlib.util.module_from_spec(spec);spec.loader.exec_module(previous)
previous.HERE=HERE
previous.build()
for name in ['.gitignore','render_editorial.py']:shutil.copy2(PRIOR/name,HERE/name)
def write(name,obj):(HERE/name).write_text(json.dumps(obj,ensure_ascii=False,indent=2)+'\n')
notice='ESEMPI ILLUSTRATIVI E STIME DI CONSUMO. I nomi non indicano una scelta obbligatoria, una certificazione o un’approvazione normativa. Modello, ambiente e dati ammessi si definiscono prima dell’attivazione.'
privacy=[
('Filtri sui dati personali','AskMyDocs dispone di funzioni configurabili per mascherare o sostituire dati personali nei testi destinati alla ricerca e nelle domande. Prima dell’uso sui dati aziendali si attivano le protezioni pertinenti e si verificano i flussi effettivi. La presenza del filtro non garantisce da sola l’eliminazione di ogni dato sensibile.'),
('Verificare tutto il percorso','La verifica comprende documenti, domande, parti di testo recuperate, indicizzazione, OCR, immagini, log ed eventuali servizi esterni. Un OCR remoto può ricevere il file originale prima del filtraggio del testo: per contenuti riservati si sceglie un percorso locale o un trattamento specificamente autorizzato.'),
('Anche su server aziendale','Esempio: Qwen3-8B installato su server proprio o infrastruttura privata, previa verifica di licenza, capacità e compatibilità. Per mantenere i contenuti nel perimetro concordato devono restarvi anche ricerca, embedding, OCR e servizi accessori. La sola collocazione del modello non rende locale l’intera applicazione.'),
('AI Act e GDPR nel progetto','La configurazione per un uso conforme si valuta sul trattamento concreto: finalità, categoria di dati, ruoli, base giuridica, accordi con i fornitori, sicurezza, conservazione, trasferimenti e obblighi AI Act applicabili. I dati delle categorie particolari richiedono le condizioni del GDPR. Il filtraggio reversibile è pseudonimizzazione, non automaticamente anonimizzazione. La conformità non deriva dal solo nome o dalla sede del modello.'),
]
d=json.loads((HERE/'brochure.json').read_text())
d['document']['edition']='12 NOVEMBRE 2026 / REV 08'
p=next(x for x in d['pages'] if x['id']=='models')
p['title']='Esempi di modelli\nStime e ambiente di utilizzo';p['section']=p['title'].replace('\n',' ')
p['deck']=notice
p['rows'].append(['Server proprio\nEsempio Qwen3-8B','Su dimensionamento','Server e gestione\na preventivo'])
p['table_font']=9.2;p['padding']=7
p['note']='Le fasce API precedenti sono stime, variabili per modello, token, ragionamento e passaggi. Non identificano modelli certificati AI Act. I filtri sui dati personali vanno attivati e collaudati sui percorsi effettivi prima dell’uso; si sceglie il provider o il server proprio in base ai requisiti del trattamento.\n\nServer proprio: il costo comprende infrastruttura e gestione, anche a basso utilizzo. Esempio puramente aritmetico, non un’offerta: un budget ipotetico di 400 € al mese equivale a 0,04 € per richiesta con 10.000 richieste, oppure 0,40 € con 1.000. Prestazioni e capacità vanno misurate; questo budget non è incluso nei canoni S/M/L.\n\nLe stime API assumono 10.000-30.000 token letti e 1.000-3.000 prodotti, ragionamento incluso, sommati su tutti i passaggi, più preparazione e ricerca. Le tariffe Gemini rilevate includono promozioni variabili.'
protection=previous.ep('data-protection','article','Proteggere i dati\nScegliere l’ambiente','Protezione dei dati e requisiti AI Act e GDPR si definiscono insieme alla configurazione, prima del caricamento dei contenuti aziendali.',kicker='DATI PERSONALI E USO RESPONSABILE',columns=[[previous.block(*x) for x in privacy[:2]],[previous.block(*x) for x in privacy[2:]]],callout='Per dati riservati si concordano filtri, dati ammessi e destinazioni di elaborazione, anche su server aziendale. Si verifica la configurazione effettiva: nessuna conformità automatica o garanzia assoluta di anonimizzazione.')
d['pages'].insert(d['pages'].index(p),protection)
refs=next(x for x in d['pages'] if x['id']=='sources')
refs['items'] += [{'title':'AI Act e protezione dati','detail':'Riferimenti normativi per valutare il sistema e il trattamento, non attestazioni di conformità del prodotto. GDPR: regolamento UE 2016/679, in particolare artt. 5, 6, 9, 25, 28, 32 e capo V.','url':'https://eur-lex.europa.eu/eli/reg/2024/1689/oj/eng'},{'title':'Modello su server proprio','detail':'Qwen3-8B, esempio di modello distribuibile su infrastruttura propria. Compatibilità, licenza e configurazione da verificare nel progetto.','url':'https://huggingface.co/Qwen/Qwen3-8B'}]
write('brochure.json',d)
doc=Document(HERE/'AskMyDocs_listino_pacchetti.docx')
target=next(x for x in doc.paragraphs if x.text=='Modelli e stime del credito incluso')
target.text='Esempi di modelli e stime di consumo'
def before(text,style=None):
 el=OxmlElement('w:p');target._p.addprevious(el);p=Paragraph(el,target._parent)
 if style:p.style=style
 p.add_run(text);return p
before('Protezione dei dati e scelta dell’ambiente','Heading 1')
before(notice)
for heading,text in privacy:
 before(heading,'Heading 2');before(text)
before('Riferimenti: AI Act UE 2024/1689; GDPR UE 2016/679, artt. 5, 6, 9, 25, 28, 32 e capo V; EDPB parere 28/2024. La configurazione e l’attivazione dei filtri dell’installazione del cliente devono essere verificate prima dell’uso.')
before('').add_run().add_break(__import__('docx').enum.text.WD_BREAK.PAGE)
for t in doc.tables:
 if t.rows[0].cells[0].text=='Modello':
  cells=t.add_row().cells
  for cell,value in zip(cells,['Server proprio, esempio Qwen3-8B','Su dimensionamento','Server e gestione a preventivo']):cell.text=value
for x in doc.paragraphs:
 if x.text.startswith('Esempi di modelli recenti.'):
  x.text='STIME ILLUSTRATIVE. Modelli e importi sono esempi di budget. Dati ammessi, filtri e ambiente si definiscono prima dell’attivazione.'
doc.core_properties.subject='Stime illustrative dei modelli protezione dati e configurazione AI Act GDPR'
doc.save(HERE/'AskMyDocs_listino_pacchetti.docx')
sources=json.loads((HERE/'fonti.json').read_text())
sources['sources'] += [{'id':'AI-ACT','url':'https://eur-lex.europa.eu/eli/reg/2024/1689/oj/eng','checked_at':'2026-11-12'},{'id':'GDPR','url':'https://eur-lex.europa.eu/eli/reg/2016/679/oj/eng','checked_at':'2026-11-12'},{'id':'QWEN-SELFHOST','url':'https://huggingface.co/Qwen/Qwen3-8B','checked_at':'2026-11-12'},{'id':'PII-CODE','path':'config/kb.php','supports':'Filtri configurabili, default disattivati; stato live non verificato'}]
write('fonti.json',sources)
(HERE/'Nota_privacy_interna.txt').write_text('Non dichiarare conformità AI Act certificata o che nessun dato sensibile esca mai. Filtri default OFF in config/kb.php; ChunkRedactor e ChatInputRedactor dipendono da flag e policy. OCR remoto può precedere filtraggio. Stato live, copertura, qualità e blocco egress non verificati. Pseudonimizzazione reversibile non è anonimizzazione. Esempio server proprio da dimensionare, non prodotto installato o compreso nei canoni. Budget 400 euro soltanto ipotetico, non prezzo verificato. Nessuna modifica al software o alla produzione.\n')
