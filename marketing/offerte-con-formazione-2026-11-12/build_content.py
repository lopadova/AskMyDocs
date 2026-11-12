from pathlib import Path
import json, hashlib

ROOT=Path(__file__).resolve().parents[2]
HERE=Path(__file__).resolve().parent
PRICING=ROOT/'marketing/offerte-portafoglio-2026-11-12/revisione02/calcolo_prezzi_revisione02.json'
P=json.loads(PRICING.read_text())
prices=P['first_year_total']['AskMyDocs'];renew=P['renewals']['AskMyDocs']
def euro(x):return f'{x:,.0f}'.replace(',','.')+' €'
def block(heading,text):return {'heading':heading,'text':text}
def art(name,caption='',height=105):return {'path':'public/editorial/'+name+'-day.webp','fit':'cover','focus':[.5,.5],'caption':caption,'height':height}
def page(id,layout,title,deck='',**kwargs):
    return {'id':id,'layout':layout,'section':kwargs.pop('section',title.replace('\n',' ')),
      'title':title,'deck':deck,'source_ids':['AMD-FACTS','AMD-PRICING'],
      'feature_ids':kwargs.pop('feature_ids',[]),**kwargs}
fontbase='marketing/offerte-con-formazione-2026-11-12/assets/fonts/'
theme={'palette_source':'resources/views/landing.blade.php; public/landing/styles.css e theme.css, variante Humanistic light. Griglia della presentazione prodotto approvata.',
 'palette':{'paper':'#F4F2ED','cream':'#EEECE5','ink':'#19241F','primary':'#416147','accent':'#735C34','muted':'#4D5B53','line':'#D3D8CA','pale':'#E4E6DC'},
 'fonts':{'heading':{'regular':fontbase+'InstrumentSerif-Regular.ttf','bold':fontbase+'InstrumentSerif-Regular.ttf','italic':fontbase+'InstrumentSerif-Italic.ttf','bold_italic':fontbase+'InstrumentSerif-Italic.ttf'},
  'body':{'regular':fontbase+'Geist-400-normal.ttf','bold':fontbase+'Geist-700-normal.ttf','italic':fontbase+'Geist-400-italic.ttf','bold_italic':fontbase+'Geist-700-italic.ttf'}},
 'logo':{'path':'public/brand/askmydocs-wordmark-light.png','fit':'contain'},
 'images':{'jpeg_quality':90,'max_dpi':180},'folio_format':'{page:02d} / {total:02d}'}
pages=[
page('copertina','cover','Dai documenti\na un uso consapevole',
 'Un primo archivio interrogabile, persone preparate\ne un percorso di consulenza che accompagna l’azienda.',
 kicker='CONSULENZA E FORMAZIONE',display_name='AskMyDocs',image=art('knowledge'),
 band_title='SOFTWARE GESTITO CON CORSO AI SEMPRE INCLUSO',band_subtitle='PACCHETTI COMMERCIALI / 12 NOVEMBRE 2026',feature_ids=['F-001','F-005']),
page('essenziali','article','Le risposte partono\ndalle vostre fonti',
 'AskMyDocs aiuta a consultare un archivio aziendale con domande in linguaggio naturale. Il progetto mette insieme strumento, metodo e persone.',
 kicker='LE COSE ESSENZIALI',feature_ids=['F-001','F-005','F-036'],columns=[
 [block('Quando le informazioni ci sono già','Una condizione del preventivo, una voce della bolletta, un passaggio della procedura: spesso la risposta esiste, ma ritrovarla richiede attraversare file, versioni e cartelle. Partiamo da un insieme delimitato di documenti e dalle domande che tornano davvero.'),
  block('Un archivio che si può interrogare','Caricamento, indicizzazione e ricerca preparano i contenuti. La chat aiuta a formulare una risposta e a ritrovare i riferimenti. Il referente apre le fonti e valuta se il risultato risponde alla domanda.')],
 [block('A chi proponiamo il percorso','PMI con un ufficio amministrativo, commerciale o operativo che usa documenti ricorrenti. Non serve arrivare con una squadra di esperti AI: serve un referente che conosca il lavoro e possa scegliere i materiali iniziali.'),
  block('Il valore dell’accompagnamento','Aiutiamo a scegliere il primo caso, preparare l’archivio, impostare gli accessi e provare le risposte. Il corso fornisce le basi; il laboratorio le porta sui documenti e sulle responsabilità della vostra azienda.')]],
 callout='L’obiettivo è rendere il team capace di porre domande, verificare le fonti e riconoscere quando serve una persona.'),
page('caso','case','Una domanda ricorrente\nun archivio preciso',
 'Scenario illustrativo: un ufficio deve ritrovare condizioni e riferimenti in fatture, bollette e preventivi già disponibili.',
 kicker='UN PRIMO CASO DI LAVORO',image=art('archive','Illustrazione del prodotto. Lo scenario è esemplificativo, non una testimonianza.',145),feature_ids=['F-001','F-005','F-006'],columns=[
 [block('La situazione','Un collega cerca il documento che contiene una certa condizione o la spiegazione di una voce. Prima di configurare lo strumento scegliamo quali documenti siano pertinenti e quali informazioni vadano tenute fuori.'),
  block('Il percorso','Prepariamo un campione, lo carichiamo e concordiamo venti domande. Nel laboratorio il referente segue il percorso dalla richiesta al documento originale e annota le risposte non sufficientemente coperte.')],
 [block('Il risultato da verificare','Un archivio iniziale interrogabile e un metodo condiviso per controllare i riferimenti. La verifica può anche mostrare che manca una versione aggiornata o che una domanda richiede dati esterni al corpus.'),
  block('Il confine del progetto','La ricerca documentale non equivale a registrazione contabile, riconciliazione fiscale o calcolo certificato. OCR e AI possono sbagliare: numeri, scadenze e decisioni vengono verificati dal referente.')]]),
page('percorso','journey','Il primo progetto\nsi costruisce insieme',
 'Una partenza circoscritta permette di imparare sul lavoro reale. La consulenza iniziale è compresa nel piano.',
 kicker='DAL BISOGNO ALL’AVVIO',feature_ids=['F-001','F-005','F-036'],steps=[
 {'heading':'Scegliere il lavoro da migliorare','text':'Individuiamo un ufficio, una famiglia di documenti, un referente e le domande che ricorrono. Il perimetro iniziale viene scritto nell’ordine.'},
 {'heading':'Preparare fonti e accessi','text':'Configuriamo l’ambiente, ruoli e primo archivio. Il cliente consegna materiali leggibili, aggiornati e autorizzati; il caricamento assistito segue le quantità del piano.'},
 {'heading':'Comprendere l’intelligenza artificiale','text':'I referenti frequentano otto ore di corso base: funzionamento, richieste efficaci, errori, dati e verifica umana. Le aziende possono condividere la stessa edizione.'},
 {'heading':'Provare il prodotto nel proprio contesto','text':'Nel laboratorio riservato si lavora sulle fonti aziendali. La consegna comprende venti domande concordate, controllo dei riferimenti e istruzioni per il lavoro successivo.'}],
 callout='Avvio obiettivo entro dieci giorni lavorativi dai materiali disponibili; consegna entro venti dall’avvio. Corso e laboratorio entro 90 giorni, con calendario concordato.'),
page('corso','article','Capire l’AI\nprima di affidarle il lavoro',
 'Il corso è parte obbligatoria di ogni pacchetto. Spiega il metodo con cui usare il prodotto e decidere che cosa verificare.',
 kicker='OTTO ORE DI FORMAZIONE DI BASE',columns=[
 [block('Come funziona una risposta','Due ore per comprendere modelli generativi, contesto, token e differenza tra conoscenza del modello e ricerca sui documenti. La capacità di produrre una frase plausibile non dimostra che il contenuto sia corretto.'),
  block('Chiedere e verificare','Due ore su obiettivo, contesto, esempi e controllo dei risultati. Si impara ad aprire le fonti, riconoscere una citazione debole e riformulare una domanda troppo ampia.')],
 [block('Dati e responsabilità','Due ore su quali dati utilizzare, come preparare esempi e chi deve controllare il risultato. L’azienda inizia a definire regole d’uso adeguate alle attività effettive.'),
  block('Esercitarsi nel proprio ruolo','Due ore di esercitazioni e verifica finale. Restano dispensa, esempi, checklist, registro presenze e attestato di partecipazione. Il laboratorio riservato aggiunge 2 / 4 / 6 ore secondo il piano.')]],
 callout={'text':'Inclusi 2 / 4 / 6 posti con docente e accesso per 90 giorni al corso AI su Oraculi, con 100 risposte tutor per persona. Gli altri utenti software non sono automaticamente posti nel corso.', 'height':70}),
page('piani','table','Tre dimensioni\nper iniziare con metodo',
 'Primo anno con corso condiviso in coworking. Importi IVA esclusa: software, avviamento, cloud e formazione sono compresi.',
 kicker='PACCHETTI E CAPACITÀ',headers=['CONTENUTO','START','BUSINESS','PREMIUM'],column_weights=[2.1,1,1,1],table_font=8.8,padding=6,
 rows=[['Totale primo anno']+[euro(x) for x in prices],['Rinnovo annuale']+[euro(x) for x in renew],
 ['Utenti nominativi / archivi','5 / 1','15 / 3','40 / 6'],['Documenti presenti','1.000','5.000','15.000'],
 ['Pagine presenti e indicizzabili per anno','10.000','50.000','150.000'],['Storage fonti e derivati','5 GB','20 GB','60 GB'],
 ['Risposte AI standard per anno','2.000','8.000','16.000'],['OCR annuo dentro la quota pagine','500 pagine','2.000 pagine','5.000 pagine'],
 ['Importazione assistita iniziale','100 file / 1.000 pagine','500 file / 5.000 pagine','1.500 file / 15.000 pagine'],
 ['Corso base / posti','8 ore / 2','8 ore / 4','8 ore / 6'],['Laboratorio riservato','2 ore','4 ore','6 ore'],
 ['Assistenza operativa annua','4 ore','8 ore','16 ore']],
 note='Il corso è incluso in tutti i piani. Il rinnovo mantiene servizio e quote annuali, senza ripetere il corso iniziale. Un nuovo caricamento che richiede rielaborazione consuma pagine. Massimo 20 MB e 200 pagine per file; PDF, DOCX, TXT e Markdown. Analisi avanzate, modelli specialistici e integrazioni hanno un preventivo distinto.'),
page('preventivi','table','La formazione cambia sede\nil pacchetto resta completo',
 'Scegliete l’organizzazione più adatta al gruppo. Ogni totale mantiene software, cloud per un anno, avviamento e laboratorio online riservato.',
 kicker='PREVENTIVI PER MODALITÀ',headers=['PIANO','ONLINE CONDIVISO','COWORKING','ONLINE DEDICATO','IN SEDE LOCALE'],column_weights=[1,1.15,1.15,1.15,1.15],table_font=9,padding=7,
 rows=[[tier,euro(prices[i]-80*[2,4,6][i]),euro(prices[i]),euro(prices[i]-[640,1280,1920][i]+1400),euro(prices[i]-[640,1280,1920][i]+2050)] for i,tier in enumerate(['Start','Business','Premium'])],
 note='Esempio Start con coworking: 2.300 € software e cloud per dodici mesi, 1.980 € attivazione, 280 € laboratorio di due ore, 640 € corso di otto ore per due persone. Totale 5.200 €, IVA esclusa.\n\nIl dedicato accoglie fino a otto persone. In sede locale comprende una visita entro 50 km dalla base del docente e massimo due ore di viaggio A/R. Oltre questa soglia il totale si concorda prima dell’ordine. Il laboratorio già incluso si può svolgere in sede con la sola trasferta aggiuntiva, 250 € per visita locale.\n\nEdizioni coworking inizialmente quindicinali, da 8 a 12 persone di più aziende, in due mezze giornate. Online condiviso in quattro incontri di due ore. Data e sala si fissano all’ordine; serve un ambiente tranquillo con schermo, connessione e un computer a persona.'),
page('condizioni','article','Il perimetro protegge\nil valore del progetto',
 'Quantità, responsabilità e assistenza vengono definite prima dell’avvio, così il primo archivio può funzionare senza acquisti indispensabili successivi.',
 kicker='SERVIZIO E CONDIZIONI',feature_ids=['F-001','F-005','F-006','F-036'],columns=[
 [block('Dodici mesi di servizio gestito','Sono inclusi ambiente applicativo, database, storage, manutenzione ordinaria e backup giornaliero con conservazione di 14 giorni e prova di ripristino all’avvio. L’infrastruttura è condivisa con separazione degli accessi, senza server dedicato per cliente.'),
  block('Assistenza e responsabilità','Assistenza nei feriali, 9–18, con presa in carico ordinaria entro due giorni lavorativi. Le ore del piano coprono aiuto operativo; la correzione dei difetti compresi nel servizio non le consuma. Il cliente nomina un referente e verifica i risultati.')],
 [block('Consumi comprensibili','Le quote sono annuali. Ogni nuova risposta consuma un utilizzo; il profilo standard comprende ricerca e citazioni, con contesto fino a 12.000 token e uscita finale fino a 1.000. Nessun extra viene addebitato automaticamente.'),
  block('Estendere quando serve','Mille risposte standard aggiuntive: 250 €. Cinquemila pagine di indicizzazione: 250 €. Mille pagine OCR: 150 €, dentro la capacità documentale. Venti GB aggiuntivi: 120 €/anno. Integrazioni e casi più ampi vengono delimitati e preventivati.')]],
 callout={'text':'Proposta valida fino al 12 dicembre 2026. Pagamenti 40% all’ordine, 40% alla consegna operativa e 20% a formazione completata. L’ammissibilità a un bando dipende dal testo vigente e non è data per acquisita.', 'height':77}),
page('chiusura','closing','Partiamo da un archivio\ne da venti domande',
 'Scegliete i documenti che usate più spesso e i referenti che possono valutarli. Costruiamo il primo caso, formiamo il gruppo e verifichiamo insieme la consegna.\naskmydocs.surfacesrl.com',
 kicker='IL PROSSIMO PASSO',image=art('community',height=260),
 quote='Software, consulenza e formazione lavorano insieme: l’obiettivo è portare l’azienda a un primo uso dell’AI che sappia comprendere e controllare.')
]
document={'document':{'title':'AskMyDocs consulenza formazione e pacchetti','brand_name':'AskMyDocs','author':'','edition':'12 NOVEMBRE 2026','header':'ASKMYDOCS / CONSULENZA E FORMAZIONE','footer_note':'Proposta commerciale · IVA esclusa · Immagini illustrative','subject':'Percorso di adozione AI e offerte con corso obbligatorio','source_ids':['AMD-FACTS','AMD-PRICING','AMD-BRAND']},'theme':theme,'pages':pages}
HERE.mkdir(exist_ok=True)
(HERE/'contenuti.json').write_text(json.dumps(document,ensure_ascii=False,indent=2)+'\n')
brand={'product':'AskMyDocs','landing':'https://askmydocs.surfacesrl.com','live_web':'Non restituisce testo al web tool; identità verificata da sorgenti e asset locali','palette':theme['palette'],'fonts':theme['fonts'],'font_note':'Instrument Serif non ha peso bold: viene usato il regular, senza simulazione. Geist istanziato da WOFF2 OFL locale.','sources':['resources/views/landing.blade.php','public/landing/styles.css','public/landing/theme.css','marketing/immagini/linea-editoriale-askmydocs.json'],'reference':'marketing/pdf/askmydocs-presentazione-prodotto.pdf','adaptation':'Nove pagine focalizzate su offerta e adozione, con ritmo del riferimento e font correnti del sito.'}
(HERE/'brand.json').write_text(json.dumps(brand,ensure_ascii=False,indent=2)+'\n')
assets=[]
for n in ['knowledge','archive','community']:
    f=ROOT/'public/editorial'/f'{n}-day.webp'
    assets.append({'path':str(f.relative_to(ROOT)),'sha256':hashlib.sha256(f.read_bytes()).hexdigest(),'origin':'Asset editoriale esistente del prodotto','rights':'Riuso interno ai materiali del prodotto; licenza autonoma della libreria non verificata','role':'Illustrazione, non screenshot o fotografia di cliente'})
(HERE/'asset_registry.json').write_text(json.dumps({'assets':assets,'logo':'public/brand/askmydocs-wordmark-light.png','font_licenses':['assets/fonts/InstrumentSerif-OFL.txt','assets/fonts/Geist-OFL.txt']},ensure_ascii=False,indent=2)+'\n')
(HERE/'pricing_snapshot.json').write_text(json.dumps({'first_year':prices,'renewal':renew,'source':str(PRICING.relative_to(ROOT)),'source_sha256':hashlib.sha256(PRICING.read_bytes()).hexdigest()},indent=2)+'\n')
print('Contenuti AskMyDocs: '+str(len(pages))+' pagine')
