from pathlib import Path
from decimal import Decimal
import json
import subprocess
from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT

BASE = Path(__file__).resolve().parents[1]
DATE = '12 novembre 2026'
TIERS = ['Start', 'Business', 'Premium']
SEATS = [2, 4, 6]
TRAINING = [640, 1280, 1920]
PRICES = {
    'AskMyDocs': [4900, 8900, 14900],
    'Portale GDPR': [2900, 4900, 7900],
    'Oraculi': [3400, 6400, 10900],
    'WearFrame': [3900, 6900, 11900],
}
RENEWALS = {
    'AskMyDocs': [1800, 3600, 6600],
    'Portale GDPR': [900, 1500, 2700],
    'Oraculi': [1200, 2400, 4800],
    'WearFrame': [1500, 3000, 6000],
}

def euro(n):
    return f'{n:,.0f}'.replace(',', '.') + ' €'

def para(text, small=False, bold=False):
    return {'kind': 'p', 'text': text, 'small': small, 'bold': bold}

def h(text):
    return {'kind': 'h', 'text': text}

def table(headers, rows, widths=None):
    return {'kind': 'table', 'headers': headers, 'rows': rows, 'widths': widths}

def page(key, title, blocks, client=True):
    return {'key': key, 'title': title, 'blocks': blocks, 'client': client}

def cite(text, url):
    return {'kind': 'link', 'text': text, 'url': url}

SOURCES = [
    ('M01', 'Chatbase', 'https://www.chatbase.co/pricing', 'Hobby 40 USD/mese, Basic 150, Business 500; con annuale equivalenti 32, 120, 400. Crediti e membri del workspace non equivalgono agli utenti finali.'),
    ('M02', 'Dify Cloud', 'https://dify.ai/pricing/dify-cloud', 'Professional 59 USD/mese o 590 USD/anno, 500 documenti e 5 GB; Team 159 USD/mese. Crediti modello e quota documentale distinti.'),
    ('M03', 'Compliance GDPR', 'https://compliancegdpr.it/software-privacy/', 'Standard 270 €/anno, Premium 510 €/anno, IVA esclusa; una azienda; configurazione autonoma o assistenza separata.'),
    ('M04', 'PrivacyLab GDPR', 'https://www.privacylab.it/IT/14/Come-Funziona/', 'Base 430 €/anno oltre IVA, una partita IVA; moduli aggiuntivi separati.'),
    ('M05', 'MoodleCloud', 'https://www.moodlecloud.com/product-specifications/', 'Pagina in EUR: 150 €/anno per 50 utenti, 240 per 100, 440 per 200. Il fornitore specifica addebito in AUD: importi EUR indicativi e variabili.'),
    ('M06', 'TalentLMS', 'https://www.talentlms.com/prices', 'Configurazione iniziale mostrata: Core 119 USD/mese per 1-40 utenti; Grow 229 per 1-70. Pagina con selettore annuale/mensile; verificare ciclo al checkout, non assumere lo stesso prezzo per ogni soglia.'),
    ('M07', 'Pebblely', 'https://pebblely.com/pricing/', 'Basic 19 USD/mese per 200 immagini/mese; Pro 39 per 500, fatturazione mensile. È software da usare in autonomia.'),
    ('M08', 'Photoroom API', 'https://www.photoroom.com/api/pricing', 'FAQ: chiamata Basic 0,02 USD/immagine e Plus 0,10 USD/immagine. API e abbonamento app sono separati; non è il prezzo di una fotografia rifinita.'),
    ('M09', 'Kiwa Idea', 'https://www.kiwa.com/it/it/servizi/formazione/ai-per-il-business-fondamenti-operativi/', 'Corso online 8 ore, 380 € standard oppure 300 € clienti Kiwa, oltre IVA, per partecipante.'),
    ('M10', 'SpaccioAI', 'https://spaccio.ai/corsi/base/', 'Corso base in aula 390 € a persona, 7 ore effettive in una giornata; 10 posti. Regime IVA non chiarito nella pagina: non normalizzato al netto.'),
    ('M11', 'Villa Modena Coworking', 'https://villamodena.org/servizi-costi-coworking/', 'Sala formazione fino a 12 persone e docente, 150 €/giornata o 90 €/mezza giornata, oltre IVA. È un riferimento di costo, non una sala già prenotata.'),
    ('M12', 'Commissione europea', 'https://digital-strategy.ec.europa.eu/en/faqs/ai-literacy-questions-answers', 'Alfabetizzazione AI collegata a persone, contesto e rischio; nessun corso unico o certificato che attribuisca automaticamente conformità.'),
    ('M13', 'WearFrame sito da identificare con certezza', 'https://wearframe.net/', 'Il runtime locale identifica WearFrame.net; il sito pubblico non ha restituito testo leggibile. I prezzi del dominio wearframe.com non sono attribuiti al nostro prodotto, non essendo stata accertata la stessa identità commerciale.'),
]

PAGES = []
PAGES.append(page('sintesi', 'Pacchetti software con formazione AI inclusa', [
    para('Proposta per Marco e Lorenzo. Il prezzo consigliato comprende avviamento, software gestito, consumi entro soglia, cloud per dodici mesi e formazione obbligatoria. La modalità di riferimento è il corso condiviso in coworking; il laboratorio specifico sul prodotto resta riservato alla singola azienda.'),
    para('Listino proposto al 12 novembre 2026. Tutti gli importi sono al netto IVA. Le cifre sono raccomandazioni commerciali motivate da listini pubblici e da un modello di costo; non sono prezzi già approvati o disponibilità a pagare misurata su clienti.'),
    table(['Prodotto', 'Start\nanno 1', 'Business\nanno 1', 'Premium\nanno 1'], [[k] + [euro(x) for x in v] for k, v in PRICES.items()], [2.0, 1.1, 1.1, 1.1]),
    table(['Rinnovo annuale dal secondo anno', 'Start', 'Business', 'Premium'], [[k] + [euro(x) for x in v] for k, v in RENEWALS.items()], [2.0, 1.1, 1.1, 1.1]),
    h('La proposta da privilegiare'),
    para('Vendere Business quando il caso d’uso coinvolge più persone o reparti. Per un primo archivio delimitato è adeguato AskMyDocs Start da 4.900 €. Per formazione continua aggiungere Oraculi Start a 2.500 €, portando l’abbinamento a 7.400 €. Il pacchetto completo dei quattro prodotti Business è proposto a 21.900 €, con rinnovo a 9.900 €/anno.'),
    para('Il corso base comprende 8 ore con docente per 2 persone nello Start, 4 nel Business e 6 nel Premium. Il laboratorio dedicato aggiunge rispettivamente 2, 4 o 6 ore per prodotto. Tixora è escluso. Il pacchetto completo riunisce quattro servizi distinti e non promette una piattaforma già integrata.'),
    para('Le sezioni sui costi e sul mercato sono interne. Per i clienti è disponibile un catalogo separato con prezzi, quantità, condizioni e preventivi tipo.', small=True),
], client=False))

PAGES.append(page('mercato_documenti', 'Mercato di ricerca documentale e gestione GDPR', [
    para('Il campione comprende listini ufficiali di alternative acquistabili in autonomia. Non è una media statistica del mercato né un confronto a parità di servizio. I prezzi USD restano in USD; non applico un cambio arbitrario. Sono esclusi dal confronto promozioni non verificabili e listini Enterprise solo su richiesta.'),
    table(['Alternativa', 'Prezzo pubblico osservato', 'Che cosa confrontare'], [
        ['Chatbase [M01]', '40 / 150 / 500 USD al mese; annuale 32 / 120 / 400 al mese', 'Assistente su fonti con quote di crediti. I membri del workspace non sono tutti gli utilizzatori della chat.'],
        ['Dify Cloud [M02]', 'Professional 59 USD/mese oppure 590 USD/anno', '500 documenti, 5 GB e 3 membri nel piano Professional. Servono configurazione della conoscenza e gestione dei modelli.'],
        ['Compliance GDPR [M03]', '270 o 510 €/anno + IVA per azienda', 'Gestionale GDPR con soglie diverse. Il supporto consulenziale è richiedibile separatamente.'],
        ['PrivacyLab [M04]', 'Base 430 €/anno + IVA per una partita IVA', 'Gestione adempimenti; e-learning e altri moduli sono aggiuntivi.'],
    ], [1.15, 1.65, 2.5]),
    h('Come si difende il prezzo di AskMyDocs'),
    para('Il cliente paga la scelta del primo archivio, la sua preparazione, i permessi, il caricamento iniziale e la prova delle risposte con fonti. La fascia proposta di 4.900-14.900 € nel primo anno è una nostra stima per un servizio assistito. Non deriva dal moltiplicare il costo del software concorrente. Per un singolo utente già autonomo, un prodotto generico può essere più conveniente.'),
    h('Come si difende il prezzo del Portale GDPR'),
    para('I canoni software di poche centinaia di euro impongono trasparenza. I 2.900-7.900 € proposti comprendono soprattutto organizzazione iniziale, avviamento dei registri, formazione e servizio gestito. La validazione legale resta al referente o consulente del cliente. Non si vende una certificazione di conformità né un incarico DPO incluso.'),
    para('Le fasce consigliate valgono per PMI con un referente disponibile e materiali esistenti. Bonificare archivi disordinati, ricostruire tutti i trattamenti o scrivere procedure da zero cambia il progetto e richiede un perimetro prima dell’ordine.'),
    cite('Fonti primarie M01 Chatbase', SOURCES[0][2]), cite('M02 Dify', SOURCES[1][2]), cite('M03 Compliance GDPR', SOURCES[2][2]), cite('M04 PrivacyLab', SOURCES[3][2]),
], client=False))

PAGES.append(page('mercato_formazione_visual', 'Mercato di formazione e contenuti visuali', [
    table(['Alternativa', 'Prezzo pubblico osservato', 'Lettura per il nostro posizionamento'], [
        ['MoodleCloud [M05]', 'Circa 150 €/anno per 50 utenti; 240 per 100; addebito in AUD', 'Riferimento per LMS ospitato. Il tutor dialogico e la preparazione dei corsi vanno confrontati separatamente.'],
        ['TalentLMS [M06]', 'Core 119 USD/mese; Grow 229 nella configurazione iniziale', 'Il prezzo cambia con utenti e ciclo. È un riferimento per formazione aziendale; non prova equivalenza con Oraculi.'],
        ['Pebblely [M07]', '19 USD/mese per 200 immagini; 39 per 500', 'Le immagini da sole hanno un prezzo basso. Servizio, preparazione dei prodotti e metodo di lavoro devono dare valore aggiunto.'],
        ['Photoroom API [M08]', '0,02 USD Basic; 0,10 USD Plus per chiamata', 'Costo tecnico di singola operazione, distinto da un contenuto approvato per il catalogo.'],
    ], [1.15, 1.65, 2.5]),
    h('Oraculi come percorso di adozione'),
    para('La fascia proposta è 3.400-10.900 € per il primo anno: materiali del cliente trasformati in primi corsi, tutor testuale, gruppi e formazione ai referenti. La vendita iniziale più credibile riguarda onboarding e procedure interne delimitate. Migrare un LMS, garantire SCORM o offrire certificazioni riconosciute richiede un’offerta diversa.'),
    h('WearFrame deve vendere un processo produttivo'),
    para('La fascia proposta è 3.900-11.900 €. Per soli sfondi o pochi scatti, gli strumenti da usare in autonomia costano molto meno. WearFrame è sensato quando servono flussi ripetibili, preparazione dei riferimenti, più operatori e verifica della fedeltà del prodotto. Il Premium equivale a 1,19 € per generazione standard nel primo anno e 0,60 € al rinnovo, usando tutta la quota di 10.000.'),
    para('Identità del prodotto: il runtime locale indica WearFrame.net [M13]. Il sito non ha restituito testo leggibile nella ricerca. I prezzi retail del dominio wearframe.com non vengono attribuiti al nostro prodotto, perché l’identità commerciale comune non è accertata. Il confronto economico usa quindi Pebblely e Photoroom; l’appendice aggiunge costi tecnici e dati Cloud del nostro WearFrame.'),
    cite('M05 MoodleCloud', SOURCES[4][2]), cite('M06 TalentLMS', SOURCES[5][2]), cite('M07 Pebblely', SOURCES[6][2]), cite('M08 Photoroom', SOURCES[7][2]), cite('M13 WearFrame', SOURCES[12][2]),
], client=False))

PAGES.append(page('formazione', 'Il corso base obbligatorio e il laboratorio aziendale', [
    para('Ogni acquisto include il corso Comprendere e usare l’intelligenza artificiale in azienda. Otto ore effettive con docente, divise in due incontri da quattro ore in coworking oppure quattro incontri da due ore online. In sede del cliente si può svolgere una giornata da otto ore didattiche, con pause aggiuntive.'),
    table(['Modulo', 'Durata', 'Risultato didattico'], [
        ['Come funziona l’AI generativa', '2 ore', 'Capire modelli, token, contesto, generazione probabilistica e differenza tra conoscenza del modello e ricerca su documenti.'],
        ['Richieste e verifica delle risposte', '2 ore', 'Impostare obiettivo, contesto ed esempi; riconoscere errori, citazioni mancanti e limiti dei risultati.'],
        ['Dati e responsabilità nel lavoro', '2 ore', 'Scegliere quali dati usare, anonimizzare esempi, assegnare revisione umana e conoscere i rischi del caso concreto.'],
        ['Esercitazioni e verifica finale', '2 ore', 'Svolgere un compito assistito, controllarne il risultato e preparare una breve regola d’uso aziendale.'],
    ], [1.6, .6, 3.1]),
    h('Che cosa rimane all’azienda'),
    para('Dispensa, esempi di richieste, checklist di verifica, modello di regole interne, registro delle presenze e attestato di partecipazione. È prevista la frequenza completa, con un recupero online entro 90 giorni. Il test finale serve a individuare gli argomenti da ripassare e non certifica una conformità normativa.'),
    para('Per i partecipanti inclusi: accesso per 90 giorni al solo corso AI su Oraculi e 100 risposte del tutor testuale a persona, separate dai consumi del prodotto acquistato. La produzione e revisione del corso e i relativi accessi sono inclusi. Questo accesso didattico non abilita a creare corsi aziendali propri: per quello serve Oraculi come prodotto o estensione.'),
    h('Il laboratorio sui dati della singola azienda'),
    para('Start: 2 ore; Business: 4 ore; Premium: 6 ore. Il docente lavora online con i referenti dell’azienda sul prodotto acquistato. Dati riservati e procedure specifiche si trattano qui, senza le altre aziende. Il laboratorio si svolge entro 90 giorni dall’attivazione; se richiesto in sede si applica soltanto la trasferta definita nelle condizioni.'),
    para('I posti del corso base sono 2 / 4 / 6; le licenze software possono essere di più. Gli altri utilizzatori ricevono la guida e le istruzioni del referente. Per ruoli che usano direttamente l’AI va pianificata formazione adeguata: i posti aggiuntivi hanno prezzo esplicito. Il corso base è una scelta obbligatoria dell’offerta e non un obbligo legale universale di otto ore.'),
]))

variant_rows = []
for product, prices in PRICES.items():
    for i, price_value in enumerate(prices):
        variant_rows.append([product + ' ' + TIERS[i], euro(price_value - 80 * SEATS[i]), euro(price_value), euro(price_value - TRAINING[i] + 1400), euro(price_value - TRAINING[i] + 2050)])
PAGES.append(page('modalita', 'Prezzi completi per modalità del corso base', [
    para('Primo anno, IVA esclusa. Ogni cifra include software, avviamento, dodici mesi di cloud, quote d’uso, corso base di 8 ore e laboratorio online dedicato. Cambia il modo di erogare il corso base, non il contenuto del pacchetto.'),
    table(['Pacchetto', 'Online\ncondiviso', 'Coworking\ncondiviso', 'Online\ndedicato', 'In sede\nlocale'], variant_rows, [1.9, .85, .85, .85, .85]),
    para('Il corso condiviso vale 240 € a persona online oppure 320 € in coworking, per 2 / 4 / 6 posti. Il dedicato costa 1.400 € online per un gruppo fino a 8 persone, oppure 1.800 € in sede più 250 € di trasferta locale, totale 2.050 €. I prezzi sopra sostituiscono la quota formativa inclusa, evitando di addebitarla due volte.'),
    para('Nel Premium, il dedicato online costa meno di sei posti in coworking: è l’effetto della tariffa a gruppo. Il rinnovo software non cambia con la modalità del corso iniziale. Le ore di laboratorio prodotto sono comprese e restano riservate all’azienda.'),
    para('Trasferta locale: sede entro 50 km dalla base del docente, una visita e al massimo 2 ore di viaggio complessive. Oltre questa soglia il totale va fissato prima dell’ordine: riferimento 0,50 €/km A/R, 60 €/ora di viaggio ed eventuali spese documentate. Pranzi e trasferimenti dei partecipanti al coworking restano a loro carico.'),
    para('Organizzazione proposta: 8-12 partecipanti, normalmente 3-6 aziende, e due mezze giornate nello stesso ciclo. Inizialmente una nuova edizione ogni quindici giorni; passare a frequenza settimanale quando le adesioni lo giustificano. La soglia minima è un obiettivo interno e non autorizza attese indefinite o costi extra al cliente.'),
]))

PAGES.append(page('ask', 'AskMyDocs per un archivio aziendale interrogabile', [
    para('Primo caso d’uso: cercare risposte e riferimenti in fatture PDF, bollette, preventivi o procedure di un archivio delimitato. Sono comprese gestione documentale, indicizzazione, ricerca e chat con fonti. I piani condividono queste funzioni; cambiano capacità e lavoro iniziale.'),
    table(['Contenuto', 'Start', 'Business', 'Premium'], [
        ['Primo anno con coworking', '4.900 €', '8.900 €', '14.900 €'],
        ['Rinnovo annuale', '1.800 €', '3.600 €', '6.600 €'],
        ['Utenti nominativi / archivi', '5 / 1', '15 / 3', '40 / 6'],
        ['Documenti presenti', '1.000', '5.000', '15.000'],
        ['Pagine presenti e indicizzabili per anno', '10.000', '50.000', '150.000'],
        ['Storage documenti e derivati', '5 GB', '20 GB', '60 GB'],
        ['Risposte AI standard per anno', '2.000', '8.000', '24.000'],
        ['OCR pagine per anno, dentro la quota pagine', '500', '2.000', '5.000'],
        ['Importazione iniziale assistita', '100 file / 1.000 pagine', '500 file / 5.000 pagine', '1.500 file / 15.000 pagine'],
        ['Corso AI posti / laboratorio', '2 / 2 ore', '4 / 4 ore', '6 / 6 ore'],
        ['Assistenza operativa per anno', '4 ore', '8 ore', '16 ore'],
    ], [2.45, .95, .95, .95]),
    h('Avviamento compreso'),
    para('Configurazione dell’ambiente e dei ruoli, scelta del corpus, importazione assistita entro la soglia, prova di 20 domande concordate e consegna delle istruzioni. Il resto della quota si può caricare in autonomia. Un file: massimo 20 MB e 200 pagine; formati standard PDF, DOCX, TXT e Markdown. Un aggiornamento che richiede una nuova elaborazione consuma nuovamente pagine di indicizzazione.'),
    para('Una risposta standard usa il profilo testuale configurato, ricerca sul corpus e massimo 12.000 token di contesto e 1.000 di uscita. Analisi estese, report e operazioni con più passaggi richiedono unità maggiori preventivate. Le quote non garantiscono un numero di conversazioni: ogni nuova risposta è un utilizzo.'),
    para('Risultato di consegna: archivio iniziale interrogabile con riscontro delle fonti e referenti formati. Esclusi integrazione ERP, registrazione contabile, XML delle fatture, riconciliazioni fiscali e analisi numeriche certificate. Non presentare la chat come un sistema che garantisce calcoli o scadenze esatti.'),
]))

PAGES.append(page('gdpr', 'Portale GDPR per registri e responsabilità aziendali', [
    para('Un’unica organizzazione e partita IVA. Sono comprese anagrafiche, ruoli, registro dei trattamenti, documenti e versioni, informative e nomine, gestione operativa delle evidenze e delle attività disponibili nel modulo GDPR. Start è adeguato a una piccola realtà con un referente e procedure già identificabili.'),
    table(['Contenuto', 'Start', 'Business', 'Premium'], [
        ['Primo anno con coworking', '2.900 €', '4.900 €', '7.900 €'],
        ['Rinnovo annuale', '900 €', '1.500 €', '2.700 €'],
        ['Persone censite e accessi nominativi totali', '20', '60', '150'],
        ['Operatori con gestione dei registri, nei totali', '2', '5', '10'],
        ['Sedi operative della stessa organizzazione', '1', '3', '8'],
        ['Capacità registro trattamenti', '10', '30', '80'],
        ['Prime schede inserite insieme al cliente', '5', '10', '20'],
        ['Documenti iniziali caricati e organizzati', '10', '30', '60'],
        ['Storage complessivo', '2 GB', '5 GB', '15 GB'],
        ['Corso AI posti / laboratorio', '2 / 2 ore', '4 / 4 ore', '6 / 6 ore'],
        ['Assistenza operativa per anno', '4 ore', '8 ore', '12 ore'],
    ], [2.45, .95, .95, .95]),
    h('Il lavoro iniziale compreso'),
    para('Attivazione, configurazione dei ruoli, inserimento delle persone da un file pulito, impostazione delle sedi e compilazione guidata delle prime schede sulla base delle informazioni del cliente. I documenti sono forniti o validati dal cliente o dal suo consulente. Il laboratorio insegna a mantenere registri e responsabilità nel tempo.'),
    para('Gli utenti ordinari accedono soltanto alle attività assegnate; gli operatori sono un sottoinsieme del totale. Un dipendente censito non diventa automaticamente un operatore con accesso a tutti i registri. Un file standard non supera 20 MB. Società differenti richiedono organizzazioni e offerte distinte.'),
    para('Risultato di consegna: ambiente configurato con primo registro, documenti ordinati e referenti in grado di aggiornare il lavoro. Il prezzo non comprende audit legale completo, redazione integrale di informative, incarico DPO, DPIA specialistica o gestione legale di un incidente.'),
    para('Il modulo AI Act e la Suite normativa sono prodotti separati e non diventano inclusi passando al Premium. L’offerta riguarda il modulo GDPR e la formazione AI generale. Le funzioni generative o i canali di notifica non collaudati restano fuori dalla promessa commerciale.'),
]))

PAGES.append(page('oraculi', 'Oraculi per corsi interni e tutor sui materiali aziendali', [
    para('Il cliente fornisce manuali, procedure e dispense. Oraculi li organizza in corsi con lezioni, tutor testuale e verifiche, affiancando il referente della formazione. Sono compresi gruppi, inviti, edizioni e strumenti di avanzamento disponibili; le bozze generate si pubblicano dopo revisione umana.'),
    table(['Contenuto', 'Start', 'Business', 'Premium'], [
        ['Primo anno con coworking', '3.400 €', '6.400 €', '10.900 €'],
        ['Rinnovo annuale', '1.200 €', '2.400 €', '4.800 €'],
        ['Utenti nominativi totali', '20', '60', '150'],
        ['Referenti autori, compresi negli utenti', '2', '4', '8'],
        ['Corsi aziendali attivi', '3', '10', '25'],
        ['Primi corsi predisposti insieme', '1', '3', '6'],
        ['Pagine fonte per ciascun corso assistito', '40', '80', '120'],
        ['Pagine fonte elaborabili per anno', '500', '2.000', '6.000'],
        ['Risposte tutor testuale per anno', '3.000', '12.000', '36.000'],
        ['Storage complessivo', '3 GB', '10 GB', '30 GB'],
        ['Corso AI posti / laboratorio', '2 / 2 ore', '4 / 4 ore', '6 / 6 ore'],
        ['Assistenza operativa per anno', '4 ore', '8 ore', '12 ore'],
    ], [2.45, .95, .95, .95]),
    para('I corsi assistiti sono compresi nel numero di corsi attivi. Il cliente prepara fonti leggibili e autorizzate, indica l’obiettivo e approva lezioni e domande. La preparazione comprende struttura e revisione operativa con il referente, senza riscrivere interi manuali o produrre un catalogo editoriale da zero. Un file fonte non supera 20 MB e 200 pagine.'),
    para('La risposta tutor standard è testuale, con massimo 12.000 token di contesto e 1.000 di uscita. Le generazioni delle lezioni entro le quote pagine sono incluse separatamente dalle risposte del tutor. Il corso AI generale non consuma il numero di corsi aziendali e dispone delle sue 100 risposte per partecipante.'),
    para('Risultato di consegna: almeno un percorso interno approvato dal referente e utilizzabile da una prima classe. La voce dal vivo è esclusa dal listino standard finché provider, budget e attivazione non sono collaudati. Non sono promessi interoperabilità SCORM, un LMS completo, titoli riconosciuti o idoneità legali prodotte dall’AI.'),
]))

PAGES.append(page('wear', 'WearFrame per immagini di prodotto e flussi visuali', [
    para('Per e-commerce, marchi, distributori e piccoli studi che partono da fotografie reali dei prodotti e vogliono preparare varianti visuali con un metodo ripetibile. Inclusi ambiente, ruoli, raccolta media e due flussi standard concordati tra immagini di prodotto e ambientazioni o modelli, secondo disponibilità effettiva.'),
    table(['Contenuto', 'Start', 'Business', 'Premium'], [
        ['Primo anno con coworking', '3.900 €', '6.900 €', '11.900 €'],
        ['Rinnovo annuale', '1.500 €', '3.000 €', '6.000 €'],
        ['Operatori nominativi', '2', '5', '10'],
        ['Generazioni immagine standard per anno', '500', '2.500', '10.000'],
        ['Prodotti campione configurati insieme', '5', '15', '30'],
        ['Preset visivi iniziali', '2', '4', '8'],
        ['Storage media complessivo', '10 GB', '30 GB', '100 GB'],
        ['Corso AI posti / laboratorio', '2 / 2 ore', '4 / 4 ore', '6 / 6 ore'],
        ['Assistenza operativa per anno', '4 ore', '8 ore', '12 ore'],
    ], [2.45, .95, .95, .95]),
    h('Come si contano immagini e rigenerazioni'),
    para('Una generazione standard è un output immagine completato dal profilo concordato, fino a 2.048 px sul lato lungo, senza video o modelli premium. Quattro varianti sono quattro generazioni. Un nuovo tentativo creativo o una rigenerazione richiesta consuma quota; un errore tecnico senza output utilizzabile non viene addebitato. Download e copie non consumano generazioni.'),
    para('Il profilo standard e il prezzo delle operazioni che usano più generazioni vengono consegnati all’avvio. La quota conta tentativi completati, non fotografie approvate o SKU finiti. Se servono in media due tentativi per approvare un’immagine, 500 generazioni corrispondono a circa 250 immagini approvate: è un esempio di calcolo, non un rendimento garantito.'),
    h('Avviamento e risultato consegnato'),
    para('Configurazione dell’ambiente, selezione dei riferimenti e dei preset, impostazione dei campioni e verifica guidata di venti generazioni, comprese nella quota. Il cliente riceve una procedura per controllare marchi, colori, dettagli, forma e proporzioni prima della pubblicazione. Le immagini di ingresso devono essere nitide, nei formati e limiti concordati, massimo 10 MB ciascuna.'),
    para('Video esclusi dal prezzo standard: hanno durata, modello e costi diversi e vanno quotati a parte. Anche ritocco professionale manuale, shooting, licenze di immagini esterne, connettori e-commerce e garanzie di conversione commerciale sono esclusi. Le risorse AI ordinarie sono già comprese: non è richiesto al cliente un abbonamento personale aggiuntivo per lavorare entro le quote.'),
]))

PAGES.append(page('aggregazioni', 'Pacchetto completo ed estensioni con Oraculi', [
    para('Il pacchetto completo PMI Business comprende tutti e quattro i prodotti Business per dodici mesi. Prezzo consigliato con corso in coworking: 21.900 € + IVA. Rinnovo annuale dal secondo anno: 9.900 € + IVA. È adatto quando esistono davvero esigenze documentali, formative, organizzative e visuali nella stessa azienda.'),
    table(['Servizio compreso', 'Capacità principale'], [
        ['AskMyDocs Business', '15 utenti, 5.000 documenti, 50.000 pagine, 8.000 risposte standard/anno'],
        ['Portale GDPR Business', '60 persone/accessi, 5 operatori, 30 trattamenti, 3 sedi della stessa partita IVA'],
        ['Oraculi Business', '60 utenti, 10 corsi, 3 corsi assistiti, 12.000 risposte tutor/anno'],
        ['WearFrame Business', '5 operatori, 2.500 generazioni standard/anno'],
        ['Formazione e coordinamento', '8 ore base per 4 referenti, 16 ore di laboratori prodotto riservati e 4 ore di coordinamento iniziale'],
    ], [1.75, 3.55]),
    para('Le quantità di ciascun prodotto restano quelle della sua scheda e non sono intercambiabili. Si tratta di servizi coordinati: SSO, sincronizzazione utenti e collegamenti automatici tra archivi e corsi non sono inclusi. La formazione comune viene frequentata una sola volta dagli stessi quattro referenti.'),
    table(['Modalità del corso base nel pacchetto completo', 'Totale anno 1'], [
        ['Online condiviso', '21.580 €'], ['Coworking condiviso', '21.900 €'], ['Online dedicato fino a 8 persone', '22.020 €'], ['In sede locale fino a 8 persone', '22.670 €'],
    ], [3.8, 1.5]),
    h('Oraculi Start come estensione di un prodotto'),
    para('Estensione proposta: +2.500 € nel primo anno e +1.200 €/anno al rinnovo. Include lo stesso Oraculi Start autonomo, con 20 utenti e un primo corso interno assistito. Il corso AI comune non si ripete e non si aggiungono altri posti live. La configurazione si svolge insieme all’avviamento del prodotto principale.'),
    table(['Abbinamento con corso in coworking', 'Anno 1', 'Rinnovo/anno'], [
        ['AskMyDocs Start + Oraculi Start', '7.400 €', '3.000 €'],
        ['Portale GDPR Start + Oraculi Start', '5.400 €', '2.100 €'],
        ['WearFrame Start + Oraculi Start', '6.400 €', '2.700 €'],
    ], [3.1, 1.1, 1.1]),
]))

PAGES.append(page('preventivi', 'Preventivi tipo per il primo anno', [
    para('Schemi economici da intestare al cliente dopo il colloquio sul perimetro. Importi netti IVA. Queste ripartizioni espongono servizi già inclusi: non sono componenti indispensabili da acquistare in aggiunta. Prezzi riferiti al corso condiviso in coworking.'),
    table(['Voce', 'AskMyDocs\nStart', 'GDPR\nStart', 'Oraculi\nStart', 'WearFrame\nStart'], [
        ['Software gestito cloud e quote per 12 mesi', '1.800 €', '900 €', '1.200 €', '1.500 €'],
        ['Attivazione e configurazione del caso iniziale', '2.180 €', '1.080 €', '1.280 €', '1.480 €'],
        ['Laboratorio aziendale dedicato 2 ore', '280 €', '280 €', '280 €', '280 €'],
        ['Corso AI 8 ore per 2 persone', '640 €', '640 €', '640 €', '640 €'],
        ['Totale primo anno', '4.900 €', '2.900 €', '3.400 €', '3.900 €'],
    ], [2.1, .8, .8, .8, .8]),
    h('Preventivo completo PMI Business'),
    table(['Voce', 'Importo'], [
        ['Quattro servizi Business con cloud e quote per 12 mesi', '10.500 €'],
        ['Configurazione coordinata dei quattro prodotti, incluso coordinamento iniziale', '7.880 €'],
        ['Quattro laboratori riservati da 4 ore ciascuno', '2.240 €'],
        ['Corso AI comune da 8 ore per 4 referenti', '1.280 €'],
        ['Totale primo anno', '21.900 €'],
        ['Rinnovo annuale dal secondo anno', '9.900 €'],
    ], [4.15, 1.15]),
    para('Esempio di pagamenti proposto: 40% all’ordine, 40% alla consegna operativa dei servizi e 20% dopo formazione e verifica finale. Sul pacchetto completo: 8.760 € + 8.760 € + 4.380 €, oltre IVA. Durata software di dodici mesi dalla consegna operativa del rispettivo servizio; la formazione si completa entro 90 giorni.'),
    para('Campi da completare prima dell’emissione: ragione sociale e partita IVA del cliente e del fornitore, referente, prodotto e piano, modalità formativa, sede e trasferta, data di avvio, caso iniziale, dati disponibili e calendario. La scheda del piano e le condizioni comuni costituiscono il perimetro del preventivo.'),
]))

PAGES.append(page('condizioni', 'Avviamento cloud e condizioni del servizio', [
    h('Ambiente e dodici mesi di servizio'),
    para('Configurazione proposta: applicazione Laravel gestita, database compatibile, storage privato e backup, su infrastruttura condivisa con separazione logica degli accessi, senza server fisico dedicato per cliente. Per AskMyDocs e Oraculi occorre un servizio PostgreSQL con capacità vettoriali; code e servizi AI vengono predisposti dal fornitore. Nessun account cloud a pagamento separato è necessario al cliente per l’uso compreso.'),
    para('Provider e regione effettivi, subfornitori AI, tempi di conservazione e condizioni sul trattamento dati vanno dichiarati nell’ordine e nell’accordo applicabile prima di caricare dati reali. Il prezzo comprende infrastruttura, manutenzione ordinaria e consumi entro le soglie; non implica residenza esclusiva in UE o assenza di trasferimenti non verificati. Quota storage comprensiva di fonti e derivati, backup gestiti separatamente.'),
    h('Backup e assistenza'),
    para('Servizio proposto: backup giornaliero con 14 giorni di conservazione e prova di ripristino all’avvio. Assistenza da lunedì a venerdì, 9-18, esclusi festivi; presa in carico ordinaria entro due giorni lavorativi. Le ore del piano riguardano aiuto operativo e consulenza. Correzioni dei difetti del servizio compreso non erodono quel monte ore. Non è incluso un presidio 24 ore o un tempo garantito di risoluzione.'),
    h('Quote rinnovi e superamenti'),
    para('Le quantità AI e di elaborazione sono annuali e condivise dall’azienda, con ripristino al rinnovo. I limiti di archivio e utenti sono capacità contemporanee. Quote inutilizzate non rimborsabili e non cumulabili oltre l’anno. Al raggiungimento del limite si sospendono soltanto le nuove operazioni a consumo: consultazione ed esportazione dei contenuti disponibili restano accessibili durante il contratto.'),
    para('Nessun extra addebitato automaticamente: il referente riceve un riepilogo mensile e un avviso all’80% e al 100%, tramite strumenti effettivamente disponibili oppure controllo del servizio gestito. Ogni ampliamento richiede un ordine. Le quote sono commerciali proposte, da configurare e collaudare; questa analisi non certifica un contatore già operativo per tutti i prodotti.'),
    para('Proposta valida fino al 12 dicembre 2026. Rinnovo confermato dal cliente, senza presumere un rinnovo tacito. Importi proposti fissi per la prima annualità e per il primo rinnovo indicato. In uscita: esportazione dei documenti e dei dati nei formati supportati entro 30 giorni dalla scadenza; successiva cancellazione secondo accordo e ciclo dei backup. Non è una licenza perpetua né un servizio di conservazione legale.'),
]))

PAGES.append(page('extra', 'Estensioni e passaggio al piano superiore', [
    table(['Estensione ordinabile', 'Prezzo netto IVA', 'Regola'], [
        ['Corso base posto aggiuntivo', '240 € online / 320 € coworking', '8 ore, materiali e accesso didattico per 90 giorni. Una nuova edizione se non resta posto.'],
        ['Laboratorio o formazione ulteriore', '140 €/ora, minimo 2 ore', 'Intervento dedicato con obiettivo concordato. Trasferta separata se in presenza.'],
        ['Spostare in sede un laboratorio già incluso', '+250 € per visita locale', 'Le ore già comprese non si ricomprano. Per viaggi oltre la soglia locale serve un totale preventivo.'],
        ['AskMyDocs 1.000 risposte standard', '120 €', 'Durata fino alla scadenza annuale. Operazioni avanzate con quotazione preventiva.'],
        ['AskMyDocs 5.000 pagine di indicizzazione', '250 €', 'Solo elaborazione aggiuntiva; capacità archivio e storage restano quelli del piano.'],
        ['AskMyDocs OCR 1.000 pagine', '150 €', 'Dentro il limite pagine o insieme a un’estensione; non comprende correzione manuale.'],
        ['Oraculi 5.000 risposte tutor testuale', '240 €', 'Stesso profilo standard del piano. Non include voce o corsi scritti da un autore.'],
        ['Oraculi ulteriore corso assistito', '450 €', 'Fino a 40 pagine fonte pulite, entro corsi e capacità disponibili.'],
        ['WearFrame 500 / 2.000 / 5.000 generazioni', '200 € / 700 € / 1.500 €', 'Solo profilo standard. Nessuna equivalenza con video. Scadenza alla fine dell’annualità.'],
        ['Storage aggiuntivo 20 GB', '120 €/anno', 'Pro rata per i mesi residui; backup ordinari inclusi. Non aumenta pagine o generazioni.'],
        ['Portale GDPR altre 20 persone', '180 €/anno', 'Fino a 2 ulteriori operatori nel totale, stessa partita IVA. Nessuna consulenza legale aggiunta.'],
        ['Intervento tecnico o integrazione', '95 €/ora', 'Solo dopo analisi, perimetro e massimale approvati. Nessuna integrazione promessa a questo solo prezzo.'],
    ], [1.75, 1.25, 2.3]),
    para('Per superare utenti, archivi o corsi non coperti da un extra si sceglie il piano successivo o si definisce un ampliamento. Il cambio di piano aggiunge la differenza dei canoni per i mesi residui e il solo lavoro di configurazione aggiuntivo, a preventivo con tetto di spesa. Non si ripaga la formazione comune già fruita; eventuali nuovi partecipanti restano da includere.'),
    para('I video richiedono un preventivo con modello, secondi, risoluzione, numero di tentativi e costo massimo. Evitare formule come 500 immagini o video senza conversioni definite. I crediti di un prodotto non si trasferiscono agli altri.'),
]))

PAGES.append(page('bandi', 'Consegna del progetto e voci per i bandi', [
    h('Un avvio con tempi e responsabilità definiti'),
    para('Settimana 1: conferma del caso d’uso, materiali, ruoli, modalità del corso e trattamento dei dati. Settimane 2-3: configurazione, caricamento assistito e prima prova operativa. Entro il giorno 30: avvio del corso base. Entro 90 giorni: laboratorio e verifica finale. Per il pacchetto completo prevedere 6-8 settimane di configurazione con attività scaglionate, mantenendo i 90 giorni per la formazione.'),
    para('Il cliente nomina un referente, fornisce materiali utilizzabili e autorizzati e rende disponibili i partecipanti. In aula servono una sala tranquilla, uno schermo, connessione e un computer per persona. Il corso non si svolge come assistenza individuale alle scrivanie. Le date effettive e gli eventuali tempi dipendenti dai materiali si fissano nell’ordine.'),
    h('Criteri di consegna comprensibili al cliente'),
    para('AskMyDocs: corpus campione caricato, venti domande provate con apertura delle fonti e gestione esplicita delle risposte non coperte. GDPR: ruoli configurati, prime schede concordate e documenti accessibili ai corretti referenti. Oraculi: primo corso rivisto, pubblicato e aperto a una classe con tutor testuale provato. WearFrame: preset e campioni configurati, venti generazioni eseguite e checklist di fedeltà applicata.'),
    para('Per tutti: account attivi, accessi verificati, quote e superamenti spiegati, prima esportazione, prova di ripristino e registro della formazione. Questi controlli vanno eseguiti nella consegna reale; la sola esistenza del codice non li sostituisce.'),
    h('Come descrivere le voci del preventivo'),
    para('Distinguere software e servizio cloud per dodici mesi, configurazione e avviamento, formazione base con durata e partecipanti, laboratori dedicati con ore e prodotti. Esempio per il pacchetto completo: 10.500 € software e cloud, 7.880 € configurazione, 2.240 € laboratori, 1.280 € formazione base. Totale 21.900 €, IVA esclusa.'),
    para('Non è stato indicato né analizzato uno specifico bando: queste voci non sono una dichiarazione di ammissibilità. Prima dell’ordine verificare sul bando vigente spese ammesse, requisiti del fornitore e dell’ente formatore, periodo dei canoni, data consentita per l’avvio, modalità formativa, documentazione e pagamenti. Gli extra o i rinnovi non diventano finanziabili automaticamente.'),
    para('La formazione resta obbligatoria nel pacchetto anche se il bando non la copre: in quel caso la quota rimane a carico del cliente ed è esposta separatamente. Nessun finanziamento o contributo è dato per acquisito.'),
]))

COST_INPUTS = {
    'AskMyDocs': {'hours': [18,32,52], 'cloud': [300,600,1200], 'ai': [120,480,1440], 'support': [4,8,16]},
    'Portale GDPR': {'hours': [12,22,36], 'cloud': [180,300,600], 'ai': [0,0,0], 'support': [4,8,12]},
    'Oraculi': {'hours': [12,24,40], 'cloud': [240,480,720], 'ai': [90,360,1080], 'support': [4,8,12]},
    'WearFrame': {'hours': [12,20,30], 'cloud': [240,480,720], 'ai': [100,500,2000], 'support': [4,8,12]},
}
cost_rows=[]
COSTS={}
for product, data in COST_INPUTS.items():
    COSTS[product]=[]
    for i in range(3):
        training_cost=Decimal('132.5')*SEATS[i]
        direct=(Decimal(data['hours'][i]*55+data['cloud'][i]+data['ai'][i]+data['support'][i]*45)+training_cost)*Decimal('1.10')
        price_value=PRICES[product][i]
        margin=(Decimal(price_value)*Decimal('.90')-direct)/price_value*100
        renewal_cost=Decimal(data['cloud'][i]+data['ai'][i]+data['support'][i]*45)*Decimal('1.10')
        renewal_margin=(Decimal(RENEWALS[product][i])*Decimal('.90')-renewal_cost)/RENEWALS[product][i]*100
        COSTS[product].append({'direct_cost': float(direct), 'contribution_pct': float(margin), 'renewal_cost': float(renewal_cost), 'renewal_contribution_pct': float(renewal_margin)})
        cost_rows.append([product+' '+TIERS[i], str(data['hours'][i]), euro(float(direct)), f'{float(margin):.1f}%', f'{float(renewal_margin):.1f}%'])
PAGES.append(page('economia', 'Costi e margini stimati per uso interno', [
    para('Simulazione, non consuntivo contabile. Costo tecnico caricato ipotizzato 55 €/ora, assistenza 45 €/ora; cloud e AI sono stanziamenti annuali da validare con fatture e benchmark. Le ore tecniche comprendono configurazione e laboratorio prodotto. Il costo diretto include una riserva del 10% e la quota del corso.'),
    para('L’approfondimento sui registri locali e sul Cloud è riportato nelle ultime quattro pagine. Conferma che i margini seguenti dipendono dal profilo AI, dai tentativi automatici e dalla ripartizione dell’infrastruttura; non sono margini già misurati sulla clientela.'),
    table(['Pacchetto', 'Ore\ntecniche', 'Costo diretto\nstimato', 'Margine\nanno 1', 'Margine\nrinnovo'], cost_rows, [1.85, .65, 1.1, .85, .85]),
    para('Margine di contribuzione = (prezzo netto - costo diretto - ipotesi provvigione commerciale 10% del prezzo netto) / prezzo netto. La provvigione è un’ipotesi di calcolo, da concordare con Lorenzo; non è un compenso già pattuito. Sono esclusi sviluppo del prodotto, struttura generale, imposte e capitale: non è utile netto.'),
    para('Ipotesi corso coworking: 560 € docente per 8 ore, 220 € preparazione, 200 € aula e 80 € materiali/accessi, totale 1.060 € per edizione. Con 8 partecipanti il costo attribuito è 132,50 € a persona. Una giornata di sala da 150 € + IVA è pubblicamente disponibile nel campione [M11]; 200 € è un budget prudenziale, non un contratto.'),
    para('AI e cloud annui stanziati per Start / Business / Premium: AskMyDocs 120/480/1.440 € e 300/600/1.200 €; GDPR 0/0/0 € e 180/300/600 €; Oraculi 90/360/1.080 € e 240/480/720 €; WearFrame 100/500/2.000 € e 240/480/720 €. Sono allocazioni in infrastruttura condivisa, non preventivi di quattro server dedicati.'),
    para('Il bundle Business ha costo diretto stimato 10.648 €: somma 13.365 €, meno 1.749 € di formazione duplicata e 1.210 € di configurazione condivisa, più 242 € di coordinamento. Con 2.190 € di ipotetica provvigione, la contribuzione è 9.062 €, pari al 41,4%. Al rinnovo la stima è 38,4%.'),
], client=False))

PAGES.append(page('strategia', 'Scelte commerciali e verifica della domanda', [
    h('Tenere una fascia intermedia sostenibile'),
    para('AskMyDocs: 4.500-5.500 € per un singolo archivio, 8.000-10.000 € per più reparti e 13.000-17.000 € per un avvio più ampio. GDPR: 2.500-3.500 €, 4.500-6.000 € e 7.000-9.000 €. Oraculi: 3.000-4.000 €, 5.500-7.500 € e 9.000-12.000 €. WearFrame: 3.500-4.500 €, 6.000-8.000 € e 10.000-13.000 €. Sono intervalli di proposta assistita costruiti sui nostri costi e sul campione; non mediane osservate.'),
    h('Scegliere clienti per cui il progetto ha senso'),
    para('AskMyDocs: ufficio con documenti ricorrenti, domande ripetute e referente dell’archivio. GDPR: azienda con più persone e bisogno di mantenere registri e documenti. Oraculi: personale da inserire o aggiornare su procedure proprie. WearFrame: cataloghi ricorrenti e operatori che devono produrre e controllare varianti visuali. Il cliente che vuole un singolo strumento economico senza avviamento può scegliere le alternative autonome del campione.'),
    h('Riduzioni basate su lavoro realmente evitato'),
    para('La somma dei quattro Business è 27.100 €. Togliendo tre duplicazioni del corso da 1.280 € si arriva a 23.260 €. Il coordinamento e la configurazione condivisi consentono altri 1.360 € di riduzione netta: totale 21.900 €, risparmio 5.200 € pari al 19,2%. Il rinnovo aggregato passa da 10.500 a 9.900 €, riduzione di 600 €.'),
    para('L’estensione Oraculi Start da 2.500 € deriva da 3.400 € meno 640 € di formazione già acquistata e 260 € di avviamento condiviso. Queste riduzioni si applicano a un ordine coordinato; non sono sconti cumulabili su acquisti isolati. Sconto discrezionale massimo suggerito 5%, soltanto dopo ricalcolo di una contribuzione minima del 35% nel primo anno e del 30% al rinnovo.'),
    h('Verificare il valore senza inventare ritorni'),
    para('A titolo di scenario, 4.900 € di AskMyDocs Start equivalgono a 140 ore annue di lavoro valorizzate 35 €/ora, circa 12 ore al mese nel gruppo. Non significa che il software le farà risparmiare. Nei primi tre clienti misurare tempo di ricerca iniziale e finale, uso dei referenti, copertura delle domande e consumo reale. Per WearFrame contare tentativi per immagine approvata e tempo di revisione.'),
    para('Prima di cristallizzare il listino raccogliere 5-10 colloqui con aziende compatibili e confrontare ordini, obiezioni e costi effettivi. Per partire scegliere due pacchetti pilota commerciali completi e a prezzo pieno coerente, senza vendere roadmap. La domanda e il tasso di chiusura restano da verificare.'),
], client=False))

PAGES.append(page('benchmark_formazione', 'Riferimenti per il prezzo della formazione', [
    table(['Fonte ufficiale', 'Durata e prezzo', 'Come leggere il confronto'], [
        ['Kiwa Idea [M09]', '8 ore online, 380 € standard o 300 € clienti, + IVA a persona', 'Riferimento diretto per un corso business di una giornata.'],
        ['SpaccioAI [M10]', '7 ore effettive in aula, 390 € a persona', 'Include materiali e attestato; pagina senza chiarimento IVA, quindi prezzo non normalizzato.'],
        ['Villa Modena [M11]', 'Sala 12 persone + docente, 150 €/giorno o 90 €/mezza giornata, + IVA', 'Riferimento per costo aula; non prova disponibilità nella città del cliente.'],
    ], [1.25, 1.9, 2.15]),
    para('La nostra proposta di 240 € a persona online e 320 € in coworking si colloca sotto i due riferimenti formativi selezionati. È sostenibile con classi condivise e prepara all’utilizzo di un software già acquistato. Il prezzo è una scelta commerciale: il campione è troppo piccolo per definirlo media di mercato.'),
    para('La tariffa dedicata di 1.400 € online o 1.800 € in sede, oltre trasferta, è costruita sul costo docente, sulla preparazione del gruppo e sulla mancata condivisione dell’aula con altre aziende. Non è presentata come tariffa media di settore. Per una classe numerosa il dedicato può costare meno della somma dei singoli posti.'),
    h('Calendario pratico e riempimento delle classi'),
    para('Aprire due finestre al mese, ciascuna con due mezze giornate entro la stessa settimana. Invitare 3-6 aziende e puntare a 8-12 persone. Si può offrire una sede del fornitore attrezzata allo stesso prezzo del coworking. Per i piccoli gruppi mantenere il format condiviso; il lavoro specifico dell’azienda passa ai laboratori riservati.'),
    para('Con 8 iscritti la quota coworking genera 2.560 € di ricavi formativi per un costo simulato di 1.060 €, prima delle spese generali e commerciali. Con soli 2 iscritti i ricavi scendono a 640 €: non si può presumere lo stesso margine. Se il gruppo non si forma entro 30 giorni, concordare un dedicato online senza sovrapprezzo, con accredito della differenza aula, oppure mantenere l’aula sostenendo il maggiore costo. Nessun rinvio indefinito.'),
    para('La Commissione europea collega l’alfabetizzazione AI alle persone, ai rischi e al contesto d’uso [M12]. Il registro del corso e le regole aziendali sono evidenze utili; un attestato non attribuisce automaticamente conformità e otto ore non sono una durata legale universale.'),
    cite('M09 Kiwa', SOURCES[8][2]), cite('M10 SpaccioAI', SOURCES[9][2]), cite('M11 Villa Modena', SOURCES[10][2]), cite('M12 Commissione europea', SOURCES[11][2]),
], client=False))

PAGES.append(page('fonti_a', 'Fonti pubbliche dei confronti software', [
    para('Verifica web effettuata il 12 novembre 2026. I listini sono dinamici e vanno riconfermati prima di emettere un’offerta che dipenda dal prezzo di un terzo. Le nostre proposte restano in euro, IVA esclusa, e non rivendono gli abbonamenti elencati.'),
    *[item for source in SOURCES[:8] for item in [h(source[0]+' '+source[1]), para(source[3]), cite(source[1], source[2])]],
], client=False))
PAGES.append(page('fonti_b', 'Fonti didattiche e limiti della verifica', [
    *[item for source in SOURCES[8:] for item in [h(source[0]+' '+source[1]), para(source[3]), cite(source[1], source[2])]],
    h('Come sono state classificate le evidenze'),
    para('I prezzi dei concorrenti sono osservazioni da fonti ufficiali. Prezzi dei pacchetti, quantità, rinnovi e calendario sono proposte. Alle letture di codice e inventari si aggiungono l’audit aggregato dei database locali e il riepilogo Cloud descritti nell’appendice. Non sono stati eseguiti nuovi test di carico, flussi autenticati in produzione, chiamate AI a pagamento o collaudi di ripristino.'),
    para('L’allegato di Lorenzo del 12 novembre è la fonte dei requisiti commerciali. La richiesta attuale sostituisce il perimetro iniziale: Oraculi è incluso, Tixora escluso e la formazione AI di base obbligatoria si aggiunge al laboratorio prodotto. Non sono stati verificati bandi specifici, finanziamenti, domanda effettiva o accordi provvigionali.'),
], client=False))

PAGES.append(page('verifica', 'Base tecnica e verifiche prima della vendita', [
    para('Ricognizione in sola lettura al 12 novembre 2026. I repository presentano lavoro preesistente; i materiali nuovi sono isolati nel marketing di AskMyDocsDev. Nessun software, listino pubblico o configurazione di produzione è stato cambiato.'),
    table(['Prodotto e commit letto', 'Base utilizzata e conseguenza commerciale'], [
        ['AskMyDocs\n7e5de280b63b', 'Upload staging e ingestione, sintesi con fonti e inventario marketing del 29 ottobre. Si propone un corpus delimitato, senza promettere automazioni fiscali.'],
        ['Oraculi\n019bf1df184e', 'README, catalogo funzionalità del 28 ottobre e validazione dei corsi. Sono documentati materiali propri, corsi, tutor testuale e classi; la voce resta condizionata.'],
        ['Portale GDPR\n3181076854d3', 'AGENTS, inventario prodotto del 30 ottobre e servizi/UI GDPR. Sono separati GDPR, AI Act e Suite; Copilot generativo non dimostrato come servizio reale disponibile.'],
        ['WearFrame\n571ebf0c42e1', 'Controller Product Studio, azione di generazione, media e video. Le immagini sono il perimetro standard; i video non vengono equiparati alle immagini.'],
    ], [1.6, 3.7]),
    h('Quello che va confermato prima dell’ordine vincolante'),
    para('1. Validare un campione reale per prodotto con provider configurati: qualità, tempi, costo medio e costo massimo per operazione. Le quantità proposte non sono limiti di carico già certificati. Il costo AI di WearFrame è simulato a 0,20 € per generazione standard; se il flusso richiede più chiamate, la differenza va incorporata nel profilo prima della vendita.'),
    para('2. Configurare i limiti per cliente e verificarne conteggio, errore tecnico, blocco e ampliamento, anche con un contatore operativo gestito dal fornitore. Confermare quote AI, pagine, file e spazio; accertare che le funzioni necessarie siano effettivamente abilitate nella versione consegnata.'),
    para('3. Fissare provider, regione, backup e trattamento dei dati e verificare l’ambiente del cliente. Per GDPR accertare i flussi del modulo acquistato senza estendere la promessa a funzioni Suite. Per Oraculi predisporre e revisionare il corso AI comune; l’accesso incluso è un servizio da allestire, non la prova che il corso sia già pubblicato.'),
    para('4. Confermare disponibilità docente e aula, sede di partenza per le trasferte, responsabilità sui contenuti legali, tempi di consegna e soggetto venditore. Confermare il sito e il listino ufficiale di WearFrame senza attribuire offerte di domini dall’identità non accertata. L’analisi è pronta per decidere il catalogo; non attribuisce collaudi che non sono stati eseguiti.'),
], client=False))

PAGES.append(page('audit_programmi', 'Verifica dei costi nei programmi effettivi', [
    para('Approfondimento eseguito il 12 novembre dopo la prima formulazione delle fasce. Ho letto i punti che generano e registrano spesa, interrogato soltanto aggregati dei database locali e consultato in sola lettura gli ambienti e il riepilogo Laravel Cloud accessibili. I dati locali non sono fatture e non provano la configurazione dei clienti in produzione.'),
    table(['Prodotto', 'Meccanismo osservato', 'Conseguenza sul pacchetto'], [
        ['AskMyDocs', 'Planner, retrieval, sintesi, grounding e possibili riparazioni; il budget applicativo distingue chiamate logiche e fisiche. Il default locale è OpenRouter con GPT-4o mini.', 'Una domanda può richiedere più chiamate. Bisogna dimensionare l’intero turno, oltre a ingestione, OCR, embedding e compilazioni della conoscenza.'],
        ['Oraculi', 'Generazione lezioni e materiali, tutor, quiz e riflessione asincrona dopo la sessione; se necessario può seguire una ripianificazione.', 'Il costo del dialogo non include da solo tutta la formazione. La voce e i modelli di fascia alta restano separati; le operazioni in background vanno conteggiate.'],
        ['WearFrame', 'Router tra provider, eventuale conversione del prompt, generazione, rimozione sfondo e controllo anatomico. In locale il controllo è attivo con massimo 2 ulteriori tentativi.', 'Un output finale può causare fino a 3 generazioni in quel controllo. Vanno aggiunti analisi, riferimenti, risoluzione e operazioni accessorie.'],
        ['Portale GDPR', 'Modulo organizzativo con registri, documenti e flussi umani; il binding Copilot esaminato seleziona fake o unavailable.', 'Non giustificare il canone con un assistente generativo non disponibile. Le spese principali dell’offerta sono configurazione, cloud, supporto e formazione.'],
    ], [1.0, 2.35, 1.95]),
    h('Quali spese restano ipotesi'),
    para('Le tariffe interne di 55 €/ora tecnico, 45 €/ora assistenza, il costo docente e la provvigione del 10% non provengono dalla contabilità di Marco. Sono parametri di simulazione. Anche le ore di configurazione sono stime di progetto, non tempi consuntivati: una prima installazione può richiedere più lavoro delle successive.'),
    para('Le quote Cloud per cliente presuppongono servizio condiviso. Non si possono usare come prezzo di una installazione isolata con risorse dedicate. Per ogni offerta distinguere costo fisso della piattaforma, numero di clienti che lo sostengono, consumo marginale e quota di assistenza.'),
], client=False))

PAGES.append(page('audit_registri', 'Che cosa dimostrano i registri dei consumi', [
    para('Finestra delle query locali: dal 14 agosto 2026 alla data della verifica. Sono stati letti soltanto modelli configurati, somme di token e costi, conteggi e stati di valorizzazione, senza estrarre contenuti di documenti, conversazioni o dati personali.'),
    h('AskMyDocs ha token registrati senza costo utilizzabile'),
    para('Nel database locale 293.845 registrazioni delle quattro combinazioni principali hanno cost_method computed e costo totale zero, pur contenendo token. I soli due gruppi maggiori sono 195.733 chiamate embedding e 98.002 chiamate GPT-4o mini. Possono esserci fixture, importazioni o tariffe mancanti: non sono una misura di gratuità né un consuntivo affidabile.'),
    para('Il raggruppamento disponibile per trace_id restituisce una chiamata per trace e quasi tutti i purpose_tag sono null. Non lo uso per sostenere che ogni domanda costi una sola chiamata. Occorre collegare i consumi al turno o alla run prima di calcolare il costo completo per risposta.'),
    h('Oraculi mescola provider e modalità di addebito'),
    para('Si osservano 232 chiamate chatgpt_plan con token stimati e costo unitario zero; gli override locali indirizzano varie generazioni su quella modalità. Il ledger contiene anche righe unpriced. Questi casi non autorizzano a promettere generazioni gratuite o illimitate: vanno considerati quota di abbonamento, disponibilità, limiti e configurazione effettiva del servizio al cliente.'),
    para('Nel medesimo ambiente 25 chiamate attribuite a GPT-6 Astra sommano 10,751480 USD, circa 0,430059 USD per chiamata; 67 chiamate Qwen riportano in media 0,000821 USD. Sono gruppi con compiti diversi, non un confronto controllato a parità di risultato e non un costo medio per lezione. Mostrano però che la scelta del modello può cambiare radicalmente la spesa.'),
    h('WearFrame non offre un campione locale sufficiente'),
    para('Nel database wearframe_base non risultano media generati né aggregati di richieste nella finestra esaminata. Sono presenti modelli attivi e cost_per_call configurati, ma quel campo è una tariffa salvata, non una prova di spesa. Ad esempio un costo fisso di 0,039 USD su un modello image non deve essere assunto equivalente al suo costo attuale in ogni risoluzione e provider.'),
    para('Conclusione operativa: i registri esistenti aiutano a trovare le dipendenze di costo, ma non certificano i margini del listino. Non sono state lanciate generazioni a pagamento per fabbricare un campione e non è stato modificato il metering.'),
], client=False))

PAGES.append(page('audit_cloud', 'Costi Cloud osservati e ripartizione per cliente', [
    para('Fonte privata: Laravel Cloud CLI, usage sul periodo previous, intervallo esplicitamente restituito 10 ottobre-9 novembre 2026. Riepilogo aggiornato dal provider al 12 novembre, ore 12:35 UTC. Importi in USD; non applico un cambio presunto e non li confondo con un preventivo in euro.'),
    table(['Componente', 'WearFrame', 'Oraculi'], [
        ['Totale applicazione riportato dal provider', '40,73 USD', '3,91 USD'],
        ['Database collegato verificato tramite schema', '9,97 USD', '19,94 USD'],
        ['Cache collegata', '5,00 USD', 'Nessuna cache collegata nel campo letto'],
        ['Subtotale dei componenti sopra', '55,70 USD', '23,85 USD'],
    ], [2.9, 1.2, 1.2]),
    para('WearFrame ha ambienti main e beta: il totale applicazione comprende la voce del provider per l’app, non è una fattura per singolo cliente. Il database MySQL e la cache sono stati associati tramite gli identificativi di ambiente e schema. Oraculi espone un ambiente denominato stage con ibernazione attiva e database Serverless PostgreSQL: un ambiente poco usato non dimostra il costo di una produzione sempre attiva.'),
    para('Sono subtotali, non costo aziendale completo: restano da attribuire eventuali storage, servizi esterni, piano della piattaforma, traffico, email, backup non compresi, monitoraggio e provider AI. Le risorse condivise non vanno sommate una seconda volta per ogni ambiente o cliente. Non ho acquisito fatture fiscali o dati di pagamento.'),
    para('AskMyDocs e Portale GDPR non sono comparsi nell’elenco delle applicazioni accessibili alla sessione Cloud corrente. Non significa che non siano ospitati altrove o in un altro account. Per questi due prodotti il costo Cloud effettivo resta non verificato e gli stanziamenti del modello economico restano ipotesi.'),
    h('Formula da usare prima di promettere il rinnovo'),
    para('Quota per cliente = costi fissi della piattaforma / clienti attivi che la sostengono + risorse incrementali del cliente + provider AI + assistenza + riserva. Esempio soltanto illustrativo: il subtotale WearFrame di 55,70 USD, se invariato e condiviso da 5 clienti, pesa 11,14 USD per cliente e periodo prima delle altre voci; con 1 cliente pesa 55,70 USD.'),
    para('Il listino pubblico Laravel Cloud parte da un piano più consumi e distingue compute, database, code e cache. Non basta leggere il canone minimo per stimare la spesa di questi programmi. L’importo osservato resta legato al carico e al periodo effettivi.'),
    cite('Fonte pubblica C01 Laravel Cloud', 'https://laravel.com/cloud/pricing'),
], client=False))

stress_rows=[]
for unit in [Decimal('.20'),Decimal('.35'),Decimal('.60')]:
    ai=unit*10000
    cost=(Decimal(1650+720+540+795)+ai)*Decimal('1.10')
    renewal=(Decimal(720+540)+ai)*Decimal('1.10')
    stress_rows.append([f'{float(unit):.2f} €',euro(float(ai)),f'{float((Decimal(11900)*Decimal(".9")-cost)/11900*100):.1f}%',f'{float((Decimal(6000)*Decimal(".9")-renewal)/6000*100):.1f}%'])
PAGES.append(page('audit_scenari', 'Scenari di costo e condizioni per confermare i prezzi', [
    para('I prezzi proposti sono utilizzabili come fascia commerciale di servizi assistiti. Il profilo tecnico va fissato prima di rendere vincolanti quantità e rinnovi. La prova decisiva è il costo completo del risultato, includendo tentativi automatici, operazioni di contesto e commissioni del canale AI.'),
    table(['WearFrame Premium\ncosto medio completo', 'AI per 10.000\noutput standard', 'Margine\nanno 1', 'Margine\nrinnovo'], stress_rows, [1.8, 1.35, 1.05, 1.1]),
    para('Scenari ipotetici in euro, non conversioni dei prezzi USD e non medie osservate. Mantengono prezzi 11.900 / 6.000 €, le altre ipotesi della tabella costi e provvigione simulata del 10%. Se il costo completo fosse 0,35 € per output, per conservare 35% di contribuzione iniziale e 30% al rinnovo occorrerebbero circa 14.500 / 8.900 €, oppure una quota inferiore. Il Premium da 10.000 non va venduto con ogni modello e flusso indiscriminatamente.'),
    para('Esempio tecnico [C02-C03]: BFL FLUX.2 Pro tariffa output e riferimenti per megapixel; Google indica per Gemini 3.1 Flash Image 0,067 USD per output 1K e 0,101 per 2K, oltre input. Sono listini diretti: non provano il costo della rotta OpenRouter o dei modelli preview salvati nel database. Il numero di riferimenti e le rigenerazioni modificano il totale.'),
    para('AskMyDocs [C04]: con il riferimento GPT-4o mini di 0,15 / 0,60 USD per milione di token, una chiamata da 12.000 input e 1.000 output vale 0,0024 USD. Quattro chiamate uguali valgono 0,0096, quaranta 0,096. Sono simulazioni, non il numero di chiamate misurato per domanda; OCR, embedding e altri modelli restano da aggiungere.'),
    para('Oraculi: i 36.000 utilizzi Premium con budget 1.080 € equivalgono a 0,03 € per utilizzo. Un profilo molto più costoso non rientra nella stessa promessa. Il corso comune e i corsi aziendali richiedono inoltre generazione e revisione. Definire separatamente tutor economico, generazioni dei materiali e servizi voce.'),
    para('OpenRouter [C05] applica commissioni di acquisto crediti del 5,5% sul piano Standard e 8% sul Business. Il costo della sola inferenza registrata non include automaticamente questa voce. Le tariffe del personale, la provvigione commerciale e l’efficienza delle classi restano da sostituire con dati interni reali.'),
    cite('C02 BFL prezzi', 'https://bfl.ai/pricing?category=flux.2'),
    cite('C03 Google prezzi', 'https://aistudio.google.com/docs/pricing'),
    cite('C04 OpenAI modello e prezzi', 'https://developers.openai.com/api/docs/models/gpt-4o-mini'),
    cite('C05 OpenRouter prezzi', 'https://openrouter.ai/pricing'),
], client=False))

SOURCES.extend([
    ('C01','Laravel Cloud prezzi','https://laravel.com/cloud/pricing','Piani di piattaforma più consumo; componenti compute, database, code e cache. I dati privati di utilizzo sono documentati separatamente nell’audit.'),
    ('C02','Black Forest Labs prezzi','https://bfl.ai/pricing?category=flux.2','FLUX.2 Pro: primo MP 0,03 USD, successivi 0,015, riferimenti 0,015/MP; il costo dipende da risoluzione e riferimenti.'),
    ('C03','Google AI Studio prezzi','https://aistudio.google.com/docs/pricing','Gemini 3.1 Flash Image: output 1K 0,067 USD e 2K 0,101 USD, oltre input. Non attribuito automaticamente alle rotte OpenRouter o ai preview.'),
    ('C04','OpenAI GPT 4o mini','https://developers.openai.com/api/docs/models/gpt-4o-mini','Riferimento 0,15 USD input e 0,60 USD output per milione di token, usato soltanto per una simulazione dichiarata.'),
    ('C05','OpenRouter prezzi','https://openrouter.ai/pricing','Commissione acquisto crediti Standard 5,5%, Business 8%; distinta dal costo inferenza del singolo modello.'),
])

def hyperlink(p, label, url):
    part = p.part
    rid = part.relate_to(url, 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink', is_external=True)
    el = OxmlElement('w:hyperlink'); el.set(qn('r:id'), rid)
    run = OxmlElement('w:r'); props = OxmlElement('w:rPr')
    color = OxmlElement('w:color'); color.set(qn('w:val'), '1B4B62'); props.append(color)
    size = OxmlElement('w:sz'); size.set(qn('w:val'), '18'); props.append(size)
    run.append(props); t = OxmlElement('w:t'); t.text = label + '  ' + url; run.append(t); el.append(run); p._p.append(el)

def set_repeat_table_header(row):
    trpr = row._tr.get_or_add_trPr()
    repeat = OxmlElement('w:tblHeader'); repeat.set(qn('w:val'), 'true'); trpr.append(repeat)

def make_doc(pages, path, label):
    doc=Document(); sec=doc.sections[0]
    sec.page_width=Inches(8.5); sec.page_height=Inches(11)
    sec.top_margin=Inches(.65); sec.bottom_margin=Inches(.65)
    sec.left_margin=Inches(.7); sec.right_margin=Inches(.7)
    sec.header_distance=Inches(.25); sec.footer_distance=Inches(.25)
    styles=doc.styles
    for style in styles:
        for border in list(style.element.findall('.//' + qn('w:pBdr'))):
            border.getparent().remove(border)
    for key in ['Normal','Title','Subtitle','Heading 1','Heading 2','Heading 3']:
        styles[key].font.name='Arial'; styles[key].font.color.rgb=RGBColor(0,0,0)
    styles['Normal'].font.size=Pt(10.8)
    styles['Normal'].paragraph_format.space_after=Pt(7)
    styles['Normal'].paragraph_format.line_spacing=1.08
    styles['Title'].font.size=Pt(25); styles['Title'].font.bold=True
    styles['Title'].paragraph_format.space_after=Pt(13)
    styles['Heading 1'].font.size=Pt(20); styles['Heading 1'].font.bold=True
    styles['Heading 1'].paragraph_format.space_before=Pt(0); styles['Heading 1'].paragraph_format.space_after=Pt(10)
    styles['Heading 2'].font.size=Pt(12); styles['Heading 2'].font.bold=True
    styles['Heading 2'].paragraph_format.space_before=Pt(8); styles['Heading 2'].paragraph_format.space_after=Pt(5)
    hp=sec.header.paragraphs[0]; hp.text=label+'  |  '+DATE
    hp.style=styles['Normal']
    for r in hp.runs:r.font.size=Pt(8); r.font.color.rgb=RGBColor(0,0,0)
    fp=sec.footer.paragraphs[0]; fp.alignment=WD_ALIGN_PARAGRAPH.RIGHT
    fp.add_run('Proposta di listino  |  IVA esclusa  |  ')
    field=OxmlElement('w:fldSimple');field.set(qn('w:instr'),'PAGE');fp._p.append(field)
    for r in fp.runs:r.font.size=Pt(8)
    for number,pdata in enumerate(pages):
        if number:doc.add_page_break()
        title=doc.add_paragraph(pdata['title'],style='Title' if number==0 else 'Heading 1')
        for border in list(title._p.findall('.//' + qn('w:pBdr'))):
            border.getparent().remove(border)
        if number==0:
            sub=doc.add_paragraph(label+'\n'+DATE)
            sub.paragraph_format.space_after=Pt(12)
        for b in pdata['blocks']:
            if b['kind']=='p':
                p=doc.add_paragraph();run=p.add_run(b['text']);run.bold=b.get('bold',False)
                if b.get('small'):run.font.size=Pt(9)
            elif b['kind']=='h':doc.add_paragraph(b['text'],style='Heading 2')
            elif b['kind']=='link':
                p=doc.add_paragraph();p.paragraph_format.space_after=Pt(4);hyperlink(p,b['text'],b['url'])
            elif b['kind']=='table':
                t=doc.add_table(rows=1,cols=len(b['headers']));t.alignment=WD_TABLE_ALIGNMENT.CENTER;t.autofit=False
                widths=b.get('widths') or [5.3/len(b['headers'])]*len(b['headers'])
                scale=7.1/sum(widths)
                for col,w in zip(t.columns,widths):col.width=Inches(w*scale)
                set_repeat_table_header(t.rows[0])
                for i,value in enumerate(b['headers']):t.rows[0].cells[i].text=value
                for row in b['rows']:
                    cells=t.add_row().cells
                    for i,value in enumerate(row):cells[i].text=str(value)
                pr=t._tbl.tblPr
                borders=OxmlElement('w:tblBorders')
                for border in ['top','left','bottom','right','insideH','insideV']:
                    v=OxmlElement('w:'+border);v.set(qn('w:val'),'single');v.set(qn('w:sz'),'4');v.set(qn('w:color'),'D9D9D9');borders.append(v)
                pr.append(borders)
                for ri,row in enumerate(t.rows):
                    trpr=row._tr.get_or_add_trPr();split=OxmlElement('w:cantSplit');trpr.append(split)
                    for ci,cell in enumerate(row.cells):
                        cell.width=Inches(widths[ci]*scale);cell.vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER
                        tcpr=cell._tc.get_or_add_tcPr();shade=OxmlElement('w:shd');shade.set(qn('w:fill'),'25485A' if ri==0 else ('F1F4F6' if ri%2==0 else 'FFFFFF'));tcpr.append(shade)
                        margins=OxmlElement('w:tcMar')
                        for side,n in [('top',75),('bottom',75),('left',90),('right',90)]:
                            m=OxmlElement('w:'+side);m.set(qn('w:w'),str(n));m.set(qn('w:type'),'dxa');margins.append(m)
                        tcpr.append(margins)
                        for p in cell.paragraphs:
                            p.paragraph_format.space_after=Pt(0);p.paragraph_format.space_before=Pt(0);p.paragraph_format.line_spacing=1.03
                            p.alignment=WD_ALIGN_PARAGRAPH.LEFT if ci==0 or len(b['headers'])<=3 else WD_ALIGN_PARAGRAPH.CENTER
                            for run in p.runs:
                                run.font.name='Arial';run.font.size=Pt(9.5);run.bold=ri==0
                                run.font.color.rgb=RGBColor(255,255,255) if ri==0 else RGBColor(0,0,0)
                doc.add_paragraph().paragraph_format.space_after=Pt(0)
    doc.core_properties.title=pages[0]['title'];doc.core_properties.subject='Proposte commerciali con formazione AI obbligatoria'
    doc.core_properties.author='';doc.core_properties.keywords='AskMyDocs, Oraculi, GDPR, WearFrame, prezzi, formazione'
    doc.save(path)

def markdown(pages):
    out=[]
    for p in pages:
        out.append('# '+p['title']+'\n')
        for b in p['blocks']:
            if b['kind']=='p':out.append(b['text']+'\n')
            elif b['kind']=='h':out.append('## '+b['text']+'\n')
            elif b['kind']=='link':out.append(f"[{b['text']}]({b['url']})\n")
            else:
                out.append('| '+' | '.join(x.replace('\n',' ') for x in b['headers'])+' |')
                out.append('| '+' | '.join('---' for x in b['headers'])+' |')
                for row in b['rows']:out.append('| '+' | '.join(str(x).replace('\n',' ') for x in row)+' |')
                out.append('')
        out.append('\n---\n')
    return '\n'.join(out)

def main():
    BASE.mkdir(parents=True,exist_ok=True)
    catalogue_pages=[page('copertina_catalogo','Pacchetti software con formazione AI inclusa',[
        para('Proposta di listino del 12 novembre 2026 per PMI. Ogni pacchetto comprende configurazione, primo caso d’uso, servizio software gestito e cloud per dodici mesi, consumi entro soglia, formazione AI obbligatoria e laboratorio riservato alla singola azienda. Tutti gli importi sono IVA esclusa.'),
        table(['Prodotto','Start anno 1','Business anno 1','Premium anno 1'],[[k]+[euro(v) for v in values] for k,values in PRICES.items()],[2,1.1,1.1,1.1]),
        table(['Rinnovo annuale','Start','Business','Premium'],[[k]+[euro(v) for v in values] for k,values in RENEWALS.items()],[2,1.1,1.1,1.1]),
        para('Prezzi di riferimento con corso condiviso in coworking per 2 / 4 / 6 partecipanti. Sono disponibili corso condiviso online o dedicato online e in sede, con i totali riportati nelle pagine successive. Il laboratorio prodotto aggiunge 2 / 4 / 6 ore dedicate. Gli altri utenti software non sono automaticamente posti nel corso con docente.'),
        para('Pacchetto completo PMI Business: 21.900 € nel primo anno e 9.900 €/anno al rinnovo. Comprende tutti e quattro i Business, 8 ore di corso AI per 4 referenti, 16 ore di laboratori riservati e coordinamento iniziale. I prodotti mantengono i propri accessi e non sono presentati come un unico sistema integrato.'),
        para('Le quote e i servizi qui definiti costituiscono una proposta commerciale da intestare dopo la verifica del caso iniziale, della sede formativa e dei materiali. La configurazione e i collaudi previsti si completano nella consegna del progetto. Tixora è escluso.'),
    ])]+[p for p in PAGES if p['client']]
    make_doc(PAGES,BASE/'Analisi_mercato_e_prezzi_2026-11-12.docx','Analisi interna per Marco e Lorenzo')
    make_doc(catalogue_pages,BASE/'Catalogo_pacchetti_e_preventivi_2026-11-12.docx','Catalogo e preventivi tipo')
    (BASE/'Analisi_mercato_e_prezzi_2026-11-12.md').write_text(markdown(PAGES))
    (BASE/'Catalogo_pacchetti_e_preventivi_2026-11-12.md').write_text(markdown(catalogue_pages))
    data={'date':'2026-11-12','currency':'EUR','tax':'IVA esclusa','tiers':TIERS,'training_seats':SEATS,'training_coworking_value':TRAINING,'first_year':PRICES,'renewals':RENEWALS,'cost_assumptions':COST_INPUTS,'cost_results':COSTS,'bundle_business':{'first_year':21900,'renewal':9900,'standalone_sum':sum(v[1] for v in PRICES.values()),'training_duplicates_removed':3840,'shared_saving':1360,'direct_cost':10648},'sources':[{'id':s[0],'title':s[1],'url':s[2],'checked_at':'2026-11-12','supports':s[3],'kind':'market','limits':'Listino dinamico verificato via web; condizioni e comparabilità descritte nel rapporto. Non è una media statistica o prova di disponibilità a pagare.'} for s in SOURCES]}
    (BASE/'sorgenti'/'prezzi_e_fonti.json').write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({'report_sections':len(PAGES),'catalogue_sections':len(catalogue_pages),'output':str(BASE)},ensure_ascii=False))

if __name__=='__main__':main()
