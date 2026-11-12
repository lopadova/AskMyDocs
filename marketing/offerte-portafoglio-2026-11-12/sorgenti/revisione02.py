"""Revisione economica riproducibile; riusa soltanto l'impaginazione e le schede verificate."""
from pathlib import Path
from decimal import Decimal as D
from copy import deepcopy
import json
import genera_documenti as old

OUT = Path(__file__).resolve().parents[1] / 'revisione02'
TIERS = old.TIERS
SEATS = [2, 4, 6]
TRAINING = [640, 1280, 1920]
BASE_PRICES = {'AskMyDocs':[5200,10500,17900], 'Portale GDPR':[3200,5900,9500],
          'Oraculi':[3400,7100,13000], 'WearFrame':[3900,6500,9900]}
WEAR_WALLET = [400,1600,4000]
PRICES=deepcopy(BASE_PRICES)
PRICES['WearFrame']=[a+b for a,b in zip(BASE_PRICES['WearFrame'],WEAR_WALLET)]
RENEWALS = {'AskMyDocs':[2300,5100,9400], 'Portale GDPR':[1200,2200,3600],
            'Oraculi':[1500,3100,6500], 'WearFrame':[1900,2900,4500]}
INPUTS = {
    'AskMyDocs':{'hours':[18,32,52],'cloud':[720,1440,2400],'ai':[240,720,1440],'support':[4,8,16]},
    'Portale GDPR':{'hours':[12,22,36],'cloud':[360,600,960],'ai':[0,0,0],'support':[4,8,12]},
    'Oraculi':{'hours':[12,24,40],'cloud':[360,600,1080],'ai':[120,480,1440],'support':[4,8,12]},
    'WearFrame':{'hours':[12,20,30],'cloud':[720,960,1440],'ai':[0,0,0],'support':[4,8,12]},
}
MAINTENANCE = [110,220,440]  # accantonamento ordinario distinto dall'assistenza al cliente
WEAR_QUOTA = [500,2000,5000]
ASK_QUOTA = [2000,8000,16000]
euro,para,h,table,page,cite = old.euro,old.para,old.h,old.table,old.page,old.cite
RESULTS = {}
for product, v in INPUTS.items():
    RESULTS[product]=[]
    for i in range(3):
        annual=D(v['cloud'][i]+v['ai'][i]+45*v['support'][i]+MAINTENANCE[i])*D('1.10')
        first=annual+(D(55*v['hours'][i])+D('132.5')*SEATS[i])*D('1.10')
        p,r=D(BASE_PRICES[product][i]),D(RENEWALS[product][i])
        RESULTS[product].append({'first_cost':float(first),'annual_cost':float(annual),
          'first_margin_pct':float((p*D('.9')-first)/p*100),
          'renewal_margin_pct':float((r*D('.9')-annual)/r*100)})
        assert (p*D('.9')-first)/p >= D('.35'), (product,i,'first')
        assert (r*D('.9')-annual)/r >= D('.30'), (product,i,'renewal')

BUNDLE_PRICE, BUNDLE_RENEW = 26400, 13200
BUNDLE_COST = sum(D(str(x[1]['first_cost'])) for x in RESULTS.values())-D(1749)-D(1210)+D(242)
BUNDLE_ANNUAL = sum(D(str(x[1]['annual_cost'])) for x in RESULTS.values())
assert sum(v[1] for v in PRICES.values())-3840-1360 == BUNDLE_PRICE
assert (D(BUNDLE_PRICE-WEAR_WALLET[1])*D('.9')-BUNDLE_COST)/(BUNDLE_PRICE-WEAR_WALLET[1]) >= D('.35')
assert (D(BUNDLE_RENEW)*D('.9')-BUNDLE_ANNUAL)/BUNDLE_RENEW >= D('.30')

def price_rows(values):
    return [[p]+[euro(x) for x in v] for p,v in values.items()]

def variants(p,i):
    n=PRICES[p][i]
    return [n-80*SEATS[i],n,n-TRAINING[i]+1400,n-TRAINING[i]+2050]

def split_quote(product,i):
    price,renew=BASE_PRICES[product][i],RENEWALS[product][i]
    lab=140*[2,4,6][i]
    setup=price-renew-lab-TRAINING[i]
    assert setup>=0
    assert renew+setup+lab+TRAINING[i]==price
    return renew,setup,lab,TRAINING[i],PRICES[product][i]

CAT=[page('copertina','Pacchetti software e preventivi per Lorenzo',[
    para('Revisione 02 del 12 novembre 2026. Sostituisce i prezzi precedenti. Ogni acquisto comprende avviamento, software e cloud per dodici mesi, corso AI obbligatorio e laboratorio aziendale. AskMyDocs e Oraculi includono le quote AI delle schede; WearFrame distingue il servizio fisso dal credito iniziale per le immagini. Importi IVA esclusa.'),
    table(['Primo anno con corso in coworking','Start','Business','Premium'],price_rows(PRICES),[2,.95,.95,.95]),
    table(['Rinnovo annuale dal secondo anno','Start','Business','Premium'],price_rows(RENEWALS),[2,.95,.95,.95]),
    para('Corso AI di 8 ore per 2 / 4 / 6 persone, più 2 / 4 / 6 ore di laboratorio sul prodotto. La formazione è già compresa e resta obbligatoria. L’accesso didattico su Oraculi dura 90 giorni; non equivale alla licenza per creare corsi aziendali.'),
    para('WearFrame: i totali iniziali comprendono rispettivamente 400 / 1.600 / 4.000 € di credito immagini. Al rinnovo la tabella indica soltanto il servizio fisso; il credito si acquista secondo utilizzo. Il corso e l’avviamento sono sempre inclusi nel primo acquisto.'),
    para('Completo PMI Business: 26.400 € nel primo anno, inclusi 1.600 € di credito WearFrame; rinnovo fisso 13.200 €/anno, più utilizzo WearFrame. Include quattro prodotti, corso comune per quattro referenti e sedici ore di laboratori aziendali.'),
    para('Le schede seguenti definiscono il perimetro acquistabile. I preventivi riportano separatamente le voci già comprese. Per l’emissione si completano intestazione, calendario, sede e caso iniziale. Tixora è escluso.'),
])]

ORIGINAL={p['key']:p for p in old.PAGES}
CAT.append(deepcopy(ORIGINAL['formazione']))
CAT[-1]['blocks'].append(para('In presenza serve una sala tranquilla dedicata, con schermo, connessione e un computer per partecipante. Le lezioni si svolgono in gruppo; le attività sulle procedure riservate della singola azienda si trattano nel laboratorio dedicato.'))
rows=[]
for product in PRICES:
    for i,tier in enumerate(TIERS):rows.append([product+' '+tier]+[euro(x) for x in variants(product,i)])
CAT.append(page('modalita','Prezzi secondo la modalità del corso',[
    para('Totali del primo anno, IVA esclusa. Il corso base è sempre incluso. Il laboratorio prodotto è riservato all’azienda e si svolge online; se richiesto in sede, si aggiunge la trasferta concordata.'),
    table(['Pacchetto','Online condiviso','Coworking condiviso','Online dedicato','In sede locale'],rows,[1.8,.9,.9,.9,.9]),
    para('Corso condiviso: 240 € a persona online o 320 € in coworking. Corso riservato, fino a otto persone: 1.400 € online oppure 1.800 € in sede, più 250 € per una trasferta locale. Il totale sostituisce la quota formativa inclusa e non la somma una seconda volta.'),
    para('Trasferta locale: sede entro 50 km dalla base del docente, una visita e massimo due ore di viaggio complessive. Oltre tale soglia si fissa il totale prima dell’ordine; riferimento 0,50 €/km A/R, 60 €/ora di viaggio ed eventuali spese documentate. Ulteriori visite locali: 250 € ciascuna.'),
    para('Edizioni condivise da 8 a 12 partecipanti, con calendario inizialmente quindicinale. Due mezze giornate in coworking o quattro incontri di due ore online. La data si concorda all’ordine e la formazione si completa entro 90 giorni. La mancata composizione del gruppo non genera un sovrapprezzo obbligatorio.'),
]))
for product,key in [('AskMyDocs','ask'),('Portale GDPR','gdpr'),('Oraculi','oraculi')]:
    p=deepcopy(ORIGINAL[key])
    t=next(b for b in p['blocks'] if b['kind']=='table')
    t['rows'][0]=['Primo anno con coworking']+[euro(x) for x in PRICES[product]]
    t['rows'][1]=['Rinnovo annuale']+[euro(x) for x in RENEWALS[product]]
    if key=='ask':
        for row in t['rows']:
            if row[0]=='Risposte AI standard per anno':row[3]='16.000'
        for b in p['blocks']:
            if b.get('text','').startswith('Una risposta standard usa'):
                b['text']='Una risposta standard riguarda una domanda sui documenti con ricerca e citazioni, nel profilo testuale efficiente concordato. Contesto fino a 12.000 token e uscita fino a 1.000 token per la risposta finale. I passaggi tecnici di ricerca e verifica sono compresi; ricerche autonome prolungate, modelli specialistici, report estesi e strumenti esterni a pagamento richiedono un preventivo distinto.'
    if key=='oraculi':
        for b in p['blocks']:
            if b.get('text','').startswith('La risposta tutor standard'):
                b['text']+=' Il profilo standard usa un modello testuale efficiente; voce dal vivo, modelli specialistici e ricerche esterne a pagamento richiedono un’offerta distinta.'
    CAT.append(p)

CAT.append(page('wear','WearFrame con quota fissa e utilizzo a consumo',[
    para('Il servizio fisso comprende ambiente gestito, cloud per dodici mesi, avviamento, preset, assistenza, corso AI obbligatorio e laboratorio aziendale. Le immagini si pagano a consumo sul credito prepagato. I passaggi intermedi necessari al risultato sono compresi nel prezzo del flusso selezionato.'),
    table(['Contenuto','Start','Business','Premium'],[
        ['Quota fissa primo anno con coworking']+[euro(x) for x in BASE_PRICES['WearFrame']],
        ['Credito immagini iniziale proposto']+[euro(x) for x in WEAR_WALLET],
        ['Totale del preventivo iniziale']+[euro(x) for x in PRICES['WearFrame']],
        ['Rinnovo fisso annuo senza ricarica']+[euro(x) for x in RENEWALS['WearFrame']],
        ['Operatori nominativi','2','5','10'],
        ['Prodotti campione e preset iniziali','5 / 2','15 / 4','30 / 8'],
        ['Storage media complessivo','10 GB','30 GB','100 GB'],
        ['Corso AI posti e laboratorio','2 posti / 2 ore','4 posti / 4 ore','6 posti / 6 ore'],
        ['Assistenza operativa annua','4 ore','8 ore','12 ore'],
    ],[2.35,1,1,1]),
    table(['Risultato selezionato','Prezzo finale per immagine'],[
        ['Immagine semplice con profilo economico','0,25 €'],
        ['Immagine fotografica di alta qualità con generazione diretta','0,80 €'],
        ['Flusso standard concordato','1,00 €'],
        ['Flusso articolato concordato','2,00 €'],
    ],[3.7,1.6]),
    para('Prima dell’esecuzione si indica il prezzo totale per risultato del profilo o flusso concordato, con modello, risoluzione e riferimenti. Se un flusso produce sette immagini intermedie e una finale, il prezzo dichiarato copre tutto il flusso. Quattro risultati finali richiesti sono quattro utilizzi. Nuove modifiche creative richieste dal cliente costituiscono un nuovo utilizzo; gli errori tecnici senza risultato non erodono il credito.'),
    para('Con il credito iniziale, a 0,80 € per immagine diretta, si ottengono 500 / 2.000 / 5.000 risultati; a 2,00 € per flusso, 200 / 800 / 2.000. Sono equivalenze alternative dello stesso credito, non quote da sommare. Non viene garantita la qualità di ogni output: il referente controlla fedeltà del prodotto, marchi, colori e proporzioni prima della pubblicazione.'),
    para('Ricariche da 100 / 500 / 1.000 €, con pari credito disponibile e senza rinnovo automatico. Il credito iniziale può essere ridotto fino a 100 € o aumentato nell’ordine. Video, shooting, ritocco manuale e connettori e-commerce hanno un preventivo distinto. I preventivi comprendono già quanto necessario per iniziare a lavorare.'),
]))

CAT.append(page('aggregazioni','Pacchetto completo e formazione aziendale con Oraculi',[
    para('Completo PMI Business con corso in coworking: 26.400 € nel primo anno, inclusi 1.600 € di credito WearFrame. Rinnovo fisso: 13.200 €/anno più consumo WearFrame. Quattro servizi distinti, senza accesso unico o integrazioni automatiche.'),
    table(['Servizio','Capacità principale'],[
        ['AskMyDocs Business','15 utenti, 5.000 documenti, 50.000 pagine e 8.000 risposte standard annue'],
        ['Portale GDPR Business','60 persone/accessi, cinque operatori, 30 trattamenti e tre sedi della stessa partita IVA'],
        ['Oraculi Business','60 utenti, dieci corsi, tre corsi assistiti e 12.000 risposte tutor annue'],
        ['WearFrame Business','Cinque operatori e 1.600 € di credito iniziale: 2.000 immagini dirette a 0,80 € oppure 800 risultati da flusso a 2,00 €'],
        ['Formazione e coordinamento','Otto ore base per quattro referenti, sedici ore di laboratori riservati e quattro ore di coordinamento'],
    ],[1.6,3.7]),
    table(['Modalità del corso comune','Totale anno 1'],[
        ['Online condiviso',euro(BUNDLE_PRICE-320)],['Coworking condiviso',euro(BUNDLE_PRICE)],
        ['Online dedicato fino a otto persone',euro(BUNDLE_PRICE-1280+1400)],
        ['In sede locale fino a otto persone',euro(BUNDLE_PRICE-1280+2050)],
    ],[3.8,1.5]),
    h('Oraculi Start come estensione'),
    para('Aggiunta a un altro prodotto nello stesso ordine: 2.500 € nel primo anno e 1.500 €/anno al rinnovo. Comprende Oraculi Start, venti utenti e un primo corso aziendale assistito; il corso AI comune si frequenta una sola volta, senza aggiungere posti live.'),
    table(['Abbinamento Start con coworking','Anno 1','Rinnovo annuo'],[
        ['AskMyDocs più Oraculi',euro(7700),euro(3800)],
        ['Portale GDPR più Oraculi',euro(5700),euro(2700)],
        ['WearFrame più Oraculi',euro(6800),euro(3400)+' più consumo'],
    ],[3.2,1.05,1.05]),
]))

for product in PRICES:
    splits=[split_quote(product,i) for i in range(3)]
    CAT.append(page('preventivo_'+product,'Preventivo '+product,[
        para('Cliente ____________________  Fornitore ____________________\nData __________  Numero __________. Scegliere un piano. Importi IVA esclusa; si applicano la scheda del prodotto e le condizioni comuni di questa revisione.'),
        table(['Voci già comprese','Start','Business','Premium'],[
            [label]+[euro(x[n]) for x in splits]
            for n,label in enumerate(['Software cloud e quote per dodici mesi','Attivazione e configurazione iniziale','Laboratorio riservato di 2 / 4 / 6 ore','Corso AI 8 ore per 2 / 4 / 6 persone','Totale primo anno con coworking'])
        ][:4]+([['Credito iniziale per immagini']+[euro(x) for x in WEAR_WALLET]] if product=='WearFrame' else [])+[
          ['Totale primo anno con coworking']+[euro(x[4]) for x in splits],
          ['Rinnovo annuo'+(' fisso senza ricarica' if product=='WearFrame' else '')]+[euro(x) for x in RENEWALS[product]] ],[2.45,.95,.95,.95]),
        para('Le voci sono già comprese nel totale. La formazione resta inclusa anche senza un contributo pubblico. Il rinnovo comprende il servizio continuativo e non ripete il corso iniziale. '+('Il consumo immagini viene scalato dal credito: 0,25 € profilo economico, 0,80 € immagine fotografica diretta, 1,00–2,00 € per i flussi definiti nella scheda. La ricarica si acquista solo quando serve.' if product=='WearFrame' else 'Sono comprese le quote annuali definite nella scheda del piano.')),
        table(['Alternativa al corso in coworking','Start anno 1','Business anno 1','Premium anno 1'],[
            [label]+[euro(variants(product,i)[index]) for i in range(3)]
            for label,index in [('Online condiviso',0),('Online dedicato fino a otto persone',2),('In sede con trasferta locale',3)]
        ],[2.45,.95,.95,.95]),
        para('Piano scelto __________  Modalità corso __________  Referenti __________. Caso iniziale e materiali disponibili ____________________________________. Sede formativa e calendario ____________________________________.'),
        para('Pagamenti: 40% all’ordine, 40% alla consegna operativa, 20% a formazione e verifica completate. Dodici mesi di servizio dalla consegna operativa. Corso e laboratorio entro 90 giorni. Validità del preventivo fino al 12 dicembre 2026.'),
        para('Tempi obiettivo: avvio entro dieci giorni lavorativi dalla disponibilità dei materiali, consegna operativa entro venti giorni lavorativi dall’avvio. Il calendario definitivo si concorda all’ordine. Nessun extra viene addebitato senza un ordine di ampliamento.'),
    ]))

CAT.append(page('preventivo_completo','Preventivo completo PMI Business',[
    para('Cliente ____________________  Fornitore ____________________  Numero __________. Quattro piani Business e relative capacità, con corso comune in coworking. Importi IVA esclusa.'),
    table(['Voce compresa','Importo'],[
        ['Quattro servizi software cloud per dodici mesi',euro(13300)],
        ['Configurazione e avviamento coordinati',euro(7980)],
        ['Quattro laboratori riservati da quattro ore',euro(2240)],
        ['Corso AI di otto ore per quattro referenti',euro(1280)],
        ['Credito WearFrame iniziale',euro(1600)],
        ['Totale primo anno',euro(BUNDLE_PRICE)],['Rinnovo fisso più utilizzo WearFrame',euro(BUNDLE_RENEW)],
    ],[4,1.3]),
    para('Pagamenti del primo anno: 10.560 € all’ordine, 10.560 € alla consegna operativa, 5.280 € a corso e verifica completati, oltre IVA. Durata di ciascun servizio: dodici mesi dalla consegna operativa. Al rinnovo, altri 1.600 € di consumo WearFrame porterebbero il totale a 14.800 €.'),
    para('Online condiviso: 26.080 €. Online dedicato fino a otto persone: 26.520 €. In sede fino a otto persone, con una trasferta locale: 27.170 €. Tutti questi totali mantengono 1.600 € di credito WearFrame. Il laboratorio prodotto resta online; ulteriori visite vengono quotate prima dell’ordine.'),
    para('Sono compresi i quattro laboratori, quattro ore di coordinamento e la configurazione prevista dalle schede. Il corso comune si frequenta una sola volta. Sono esclusi SSO, connettori fra i prodotti e ampliamenti non espressamente elencati.'),
    para('Referente ____________________  Modalità formativa ____________________. Caso iniziale per ciascun prodotto, dati disponibili e date vengono allegati all’ordine. Consegna operativa obiettivo entro trenta giorni lavorativi dall’avvio e corso entro 90 giorni, con calendario concordato.'),
    para('Validità fino al 12 dicembre 2026. Il prezzo espone separatamente canoni, configurazione e formazione; non attribuisce automaticamente ammissibilità a un bando. Schede e condizioni comuni della revisione 02 completano il preventivo.'),
]))

CAT.append(deepcopy(ORIGINAL['condizioni']))
CAT[-1]['blocks'].append(para('Per WearFrame il consumo è separato dal rinnovo fisso. Credito iniziale e ricariche si usano entro la scadenza annuale del servizio; residui non cumulabili né rimborsabili. Il prezzo del risultato comprende il flusso concordato, senza un secondo addebito per le sue immagini intermedie.'))
extra=deepcopy(ORIGINAL['extra'])
for row in extra['blocks'][0]['rows']:
    if row[0].startswith('AskMyDocs 1.000 risposte'):
        row[1]='250 €'
    if row[0].startswith('Oraculi 5.000 risposte'):
        row[1]='400 €'
    if row[0].startswith('Portale GDPR altre 20 persone'):
        row[1]='360 €/anno'
        row[2]='Stessa partita IVA; operatori, storage e supporto restano quelli del piano. Oltre un’estensione si rivaluta il piano.'
    if row[0].startswith('WearFrame 500'):
        row[0]='WearFrame ricarica credito'
        row[1]='100 € / 500 € / 1.000 €'
        row[2]='Pari credito disponibile. Immagine diretta 0,80 €, flussi definiti 1,00–2,00 € per risultato. Nessun addebito automatico.'
CAT.append(extra)
CAT.append(page('bandi','Voci utilizzabili nella proposta per un bando',[
    para('I preventivi distinguono software e servizio cloud per dodici mesi, configurazione, laboratori riservati e corso base AI con durata e numero di partecipanti. Le ripartizioni sono valori commerciali dei servizi inclusi e non costi interni del fornitore.'),
    para('Non è stato identificato uno specifico bando. Prima dell’avvio vanno controllati testo vigente, spese ammesse, requisiti del fornitore e dell’ente formatore, durata ammissibile dei canoni, date delle spese, documentazione delle presenze e modalità dei pagamenti. Nessun contributo è considerato già concesso.'),
    para('La formazione AI resta obbligatoria nel pacchetto anche se il bando non la finanzia: la quota viene sostenuta dal cliente. Il corso comprende dispensa, esercitazioni, registro presenze e attestato di partecipazione; non conferisce una certificazione automatica di conformità.'),
    h('Risultati da descrivere nel progetto'),
    para('AskMyDocs: primo archivio interrogabile, con prova delle fonti su venti domande. GDPR: ruoli, primo registro e documenti concordati. Oraculi: primo corso rivisto e utilizzabile da una classe. WearFrame: preset, campioni e venti output di collaudo con controllo della fedeltà del prodotto.'),
    para('Per tutti: account attivi, formazione completata, quote spiegate, esportazione e prova di ripristino. I controlli si eseguono nella consegna effettiva del servizio. Le funzionalità disponibili e il servizio assistito non sostituiscono le responsabilità del referente aziendale o del consulente legale.'),
]))

costrows=[]
for p in PRICES:
    for i,tier in enumerate(TIERS):
        r=RESULTS[p][i]
        costrows.append([p+' '+tier,euro(r['first_cost']),f"{r['first_margin_pct']:.1f}%",euro(r['annual_cost']),f"{r['renewal_margin_pct']:.1f}%"])

REPORT=[page('sintesi','Revisione dei costi e dei pacchetti commerciali',[
    para('Nota interna per Marco e Lorenzo, revisione 02 del 12 novembre 2026. WearFrame distingue servizio fisso, corso obbligatorio e utilizzo. Seguendo l’indicazione operativa di Marco, l’immagine fotografica diretta costa al cliente 0,80 €; i flussi sono prezzati sul risultato finale, includendo le immagini intermedie.'),
    table(['Primo anno con coworking','Start','Business','Premium'],price_rows(PRICES),[2,.95,.95,.95]),
    table(['Rinnovo annuale','Start','Business','Premium'],price_rows(RENEWALS),[2,.95,.95,.95]),
    para('I consumi Cloud dei quattro prodotti sono stati letti: Surface via browser per AskMyDocs e GDPR, organizzazione personale via API per WearFrame e Oraculi. Sono separati produzione e sviluppo dove possibile. Le fatture e i costi AI non sono interamente riconciliati per prodotto: i budget commerciali restano distinti dai consuntivi osservati.'),
    para('Il servizio fisso ha contribuzione simulata almeno del 35% nel primo anno e del 30% al rinnovo. Per WearFrame il consumo ha un margine distinto e inferiore: 5% nel caso prudenziale da 0,60 € di costo e 0,80 € al cliente. Sono inclusi provvigione ipotetica al 10%, riserva al 10% e sul credito un costo di incasso ipotizzato al 2,5%. Non è utile netto.'),
    para('Il catalogo separato contiene preventivi compilabili con voci economiche, quantità, modalità del corso e condizioni. Le cifre sostituiscono la revisione precedente; quella versione si conserva come storico.'),
],False),
page('costi_osservati','Costi Cloud effettivamente osservati',[
    para('Periodo del provider dal 10 ottobre al 9 novembre 2026, rilevazione API aggiornata al 12 novembre alle 12:35 UTC. Non è il mese di calendario. Valuta originale USD, importi prima delle imposte; non converto i consuntivi con un cambio inventato.'),
    table(['Voce','WearFrame','Oraculi'],[
        ['Applicazione', '40,73 USD','3,91 USD'],['Cluster database collegato','9,97 USD','19,94 USD'],
        ['Cache collegata','5,00 USD','Non collegata nello snapshot'],['Subtotale delle voci identificate','55,70 USD','23,85 USD'],
    ],[2.8,1.25,1.25]),
    para('WearFrame comprende main e beta; il database collegato è un cluster condivisibile con altre applicazioni. Oraculi è uno stage con ibernazione. Questi subtotali descrivono risorse osservate e non il costo incrementale di un nuovo cliente o un benchmark a pieno carico.'),
    para('La fattura LAR-D623-202611-216896, emessa il 12 novembre, è di 213,66 USD complessivi per l’organizzazione personale. I suoi identificativi riscontrano il compute WearFrame di main: 28,00 + 5,75 + 0,10 = 33,85 USD, e il compute Oraculi: 1,83 + 1,38 + 0,70 = 3,91 USD. Il totale della fattura non viene attribuito a un singolo prodotto; non è una fattura Surface.'),
    para('Il subtotale esclude allocazione del piano piattaforma, storage non collegato con certezza, provider AI, servizi esterni, assistenza e imposte. Il listino ufficiale prevede anche un piano dell’organizzazione: la sua ripartizione richiede una regola gestionale, non una moltiplicazione per ogni cliente.'),
    para('La cache WearFrame da 250 MB passa da 5 a 10 USD/mese il 30 novembre 2026. Il fornitore conferma la dismissione di Upstash sul Cloud il 31 agosto 2027. La migrazione verso Valkey è un’attività da pianificare; nessuna modifica infrastrutturale è stata eseguita in questa analisi.'),
    cite('Listino e scadenze ufficiali Laravel Cloud','https://laravel.com/cloud/docs/pricing'),
],False),
page('ask_gdpr','Costi effettivi di AskMyDocs e del Portale GDPR',[
    para('Fonte privata: pagina Usage di Surface, periodo chiuso dal 9 ottobre all’8 novembre 2026, letta il 12 novembre. Il periodo differisce da quello dell’organizzazione personale. Sono costi d’uso mostrati dal provider, prima di piano, crediti e imposte; la fattura Surface non era disponibile nelle viste di questo accesso.'),
    table(['Voce nel periodo','AskMyDocs','Portale GDPR'],[
        ['Applicazione tutti gli ambienti','39,35 USD','14,00 USD'],
        ['Di cui applicazione produzione','17,88 USD','7,00 USD'],
        ['Database collegati','24,12 USD','16,67 USD'],
        ['Cache collegate','8,04 USD','12,00 USD'],
        ['Storage e richieste bucket','1,57 USD','0,00 USD'],
        ['Totale tutti gli ambienti e risorse identificate','73,08 USD','42,67 USD'],
    ],[2.9,1.2,1.2]),
    para('AskMyDocs: il database askmydocs è lo stesso cluster per main e develop, con schemi distinti. La sua spesa non è separabile con questa vista. Attribuendo per prudenza tutto il database al main, produzione più cache main e bucket main vale 48,66 USD: 17,88 + 24,12 + 6,00 + 0,66. È un limite prudenziale di allocazione, non un nuovo costo misurato per cliente.'),
    para('GDPR: produzione costa 21,33 USD, cioè applicazione 7,00 + database 8,33 + cache 6,00; bucket zero nel periodo. Staging costa 21,34 USD. I due database e le due cache sono distinti. La produzione usa MySQL 8.4, Valkey 250 MB e bucket privato associato; non servono ipotesi di consumo generativo del Copilot per quotare il gestionale.'),
    para('Per AskMyDocs la sola chiave OpenRouter configurata nell’ambiente locale riporta 81,3603 USD cumulativi e 0,1508 USD nel mese corrente al 12 novembre. Sono valori del provider, ma non dimostrano l’identità con la chiave di produzione o l’uso esclusivo del prodotto. I registri locali a costo zero non sostituiscono una misura del costo completo per risposta.'),
    para('I budget annui cloud di AskMyDocs diventano 720 / 1.440 / 2.400 €, quelli GDPR 360 / 600 / 960 €. Sono stanziamenti di servizio condiviso comprensivi di quota piano e backup, da confrontare con i consumi; non fatture per cliente. Crescita dei carichi, servizi AI e lavoro umano rimangono voci distinte.'),
    cite('Consuntivo Cloud Surface periodo chiuso','https://cloud.laravel.com/org/surface/usage?period=1'),
],False),
page('wear_costo','WearFrame dal costo tecnico al prezzo per risultato',[
    para('Indicazione operativa fornita da Marco: un risultato semplice può costare 0,15 €, un’immagine fotografica diretta di alta qualità fino a 0,60 €, un risultato da flusso articolato fino a 1,50 €. Sono i costi del risultato finale comprensivo dei passaggi intermedi, non della singola chiamata. Li uso come input commerciali del titolare, senza presentarli come una media ricavata dalle fatture.'),
    table(['Profilo','Costo massimo assunto','Prezzo cliente','Contribuzione consumo'],[
        ['Economico semplice','0,15 €','0,25 €','21,5%'],
        ['Fotografico diretto','0,60 €','0,80 €','5,0%'],
        ['Flusso entro 0,75 €','0,75 €','1,00 €','5,0%'],
        ['Flusso entro 1,50 €','1,50 €','2,00 €','5,0%'],
    ],[1.7,1.2,1.2,1.2]),
    para('Formula prudenziale: contribuzione del consumo = prezzo × 0,875 − costo × 1,10. Sottraggo provvigione ipotetica del 10%, incasso ipotizzato al 2,5% e riserva del 10% sul costo tecnico. A 0,70 € di vendita con 0,60 € di costo il risultato è −0,0475 €, cioè −6,8%: per questo scelgo 0,80 € nella fascia richiesta da Marco.'),
    para('Il margine del consumo è intenzionalmente contenuto: la quota fissa finanzia cloud, supporto, manutenzione, avviamento e corso. Se si riconoscesse a Lorenzo una provvigione diversa sul credito, il margine cambierebbe. Il 10% qui non è un accordo commerciale già assunto.'),
    para('Quote fisse WearFrame primo anno: 3.900 / 6.500 / 9.900 €. Rinnovi fissi: 1.900 / 2.900 / 4.500 €. Il credito iniziale proposto è 400 / 1.600 / 4.000 €, con pari valore spendibile: totali d’avvio 4.300 / 8.100 / 13.900 €. I preventivi lo espongono in una riga autonoma e lo possono ridurre fino a 100 €.'),
    para('Un flusso che crea sette immagini intermedie e una finale si quota sul suo costo totale. I fallimenti tecnici e i retry non previsti restano a carico del servizio; prima dell’attivazione vanno verificati profili, contatori e addebiti. Il codice consente più tentativi e può registrare alcuni costi solo alla fine: il listino non prova che il conteggio commerciale del risultato finale sia già configurato.'),
    cite('Google listini tecnici di riferimento','https://aistudio.google.com/docs/pricing#gemini-3.1-flash-image'),
    cite('OpenRouter commissioni acquisto crediti','https://openrouter.ai/pricing'),
],False),
page('margini','Costi e contribuzione dei dodici pacchetti',[
    para('Modello di costo, non contabilità. Tecnico a 55 €/ora, supporto a 45 €/ora; manutenzione ordinaria annua accantonata per 110 / 220 / 440 €. La contribuzione sottrae anche una provvigione ipotetica del 10% del ricavo netto. Ogni costo diretto comprende una riserva del 10%.'),
    table(['Pacchetto','Costo servizio anno 1','Margine servizio anno 1','Costo rinnovo fisso','Margine rinnovo fisso'],costrows,[1.8,.9,.8,.9,.8]),
    para('Costo del servizio anno 1 = 1,10 × (ore tecniche × 55 + cloud + AI + ore supporto × 45 + manutenzione + 132,50 × posti corso). Il laboratorio prodotto è nelle ore tecniche. Costo rinnovo = 1,10 × (cloud + AI + supporto + manutenzione). Margine = (prezzo × 0,90 − costo diretto) / prezzo.'),
    para('Per WearFrame la tabella riguarda solo la quota fissa; il credito immagini ha costo e ricavo separati. Se tutto il credito iniziale viene usato per fotografie dirette al costo massimo indicato, la contribuzione complessiva del primo anno è 32,6 / 30,2 / 27,0%. Sono esclusi sviluppo evolutivo, struttura generale, imposte e capitale: non è utile netto.'),
    para('Il rinnovo mantiene cloud, supporto e manutenzione; le quote AI di AskMyDocs e Oraculi sono incluse, l’utilizzo WearFrame è separato. Il corso iniziale di otto ore è compreso nell’acquisto; una nuova edizione per altri partecipanti si quota con gli extra del catalogo.'),
],False),
page('budget','Allocazioni annuali e prezzi minimi sostenibili',[
    table(['Prodotto','Cloud Start Business Premium','AI Start Business Premium','Ore iniziali'],[
        [p,' / '.join(euro(x) for x in v['cloud']),' / '.join(euro(x) for x in v['ai']),' / '.join(str(x) for x in v['hours'])]
        for p,v in INPUTS.items()
    ],[1.2,1.5,1.5,1.1]),
    para('Il cloud comprende una quota gestionale di piano, backup, storage e servizi ordinari entro il perimetro, su risorse condivise. Non è il prezzo di un nuovo server dedicato. WearFrame Start riserva 720 €/anno per l’infrastruttura, superiore all’annualizzazione aritmetica del subtotale osservato di 668,40 USD, ma il confronto usa una parità di budget e non un cambio di mercato.'),
    para('AskMyDocs Premium scende da 24.000 a 16.000 risposte standard annue. Il budget AI comprende i passaggi tecnici dell’intera risposta e indicizzazione/OCR entro le quote; non si presume che una risposta corrisponda a una sola chiamata. I profili avanzati vanno quotati separatamente. Oraculi deve usare un profilo efficiente anche per riflessione e preparazione dei corsi.'),
    para('Oraculi locale presenta chiamate stimate a costo zero tramite abbonamento e chiamate con costi molto differenti: il campione Astra è circa 0,43 USD per chiamata, altri compiti costano molto meno. Non è una misura per risposta tutor o lezione. Le quote non possono basarsi sulla gratuità di un abbonamento personale o sull’uso senza limiti del modello più costoso.'),
    para('Prezzo minimo con obiettivi del modello: costo diretto anno 1 / 0,55 e costo diretto rinnovo / 0,60. Il listino arrotonda sopra questi valori. Sconti ulteriori richiedono di ricalcolare il margine; non è sostenibile applicare automaticamente un altro 5% a tutti i pacchetti.'),
    para('Il file di calcolo simula un aumento del 25% di cloud, AI e manutenzione. Nel servizio fisso WearFrame Premium la contribuzione scende al 30,7% nel primo anno e al 19,4% al rinnovo. I consumi si controllano separatamente sul costo completo del risultato, comprese generazioni intermedie e retry.'),
],False),
page('formazione_economia','Economia del corso e delle offerte aggregate',[
    para('Costo ipotizzato del coworking per edizione: 560 € docente, 220 € preparazione, 200 € sala, 80 € materiali e accessi, totale 1.060 €. A otto partecipanti sono 132,50 € per persona; questa ripartizione finanzia i 2 / 4 / 6 posti inclusi. La sala non è stata prenotata e le tariffe orarie non sono costi del personale verificati.'),
    para('Per evitare una perdita sulle modalità dedicate, il costo del corso sostituisce quello a persona. Online condiviso: ipotesi 860 € per otto persone. Online dedicato: 860 € per gruppo. In sede locale: 1.030 €, inclusi docente, preparazione, materiali e 170 € di viaggio/costi caricati. Sono ipotesi, da verificare sul docente e la sede reali; non si presume pieno margine del 35% per tutte le varianti.'),
    para('Un solo cliente Start non copre un’intera edizione condivisa con la quota attribuita a due posti. Il fornitore organizza più aziende nello stesso ciclo e assume il rischio dei posti mancanti; il cliente non riceve un sovrapprezzo obbligatorio. Sotto otto iscritti il margine effettivo va ricalcolato prima di confermare il calendario.'),
    table(['Completo Business','Importo'],[
        ['Somma prezzi autonomi primo anno',euro(31600)],
        ['Tre duplicazioni del corso eliminate',euro(3840)],
        ['Riduzione commerciale per avviamento condiviso',euro(1360)],
        ['Prezzo completo primo anno',euro(BUNDLE_PRICE)],
        ['Di cui credito iniziale WearFrame',euro(1600)],
        ['Costo diretto servizi senza consumo WearFrame',euro(float(BUNDLE_COST))],
        ['Contribuzione servizi senza credito',f'{float((D(BUNDLE_PRICE-1600)*D(".9")-BUNDLE_COST)/(BUNDLE_PRICE-1600)*100):.1f}%'],
        ['Rinnovo fisso completo e contribuzione',f'{euro(BUNDLE_RENEW)} / {float((D(BUNDLE_RENEW)*D(".9")-BUNDLE_ANNUAL)/BUNDLE_RENEW*100):.1f}%'],
    ],[3.7,1.6]),
    para('Il costo del completo elimina 1.749 € di corso duplicato e 1.210 € di lavoro condiviso e aggiunge 242 € di coordinamento, comprensivi della riserva. Consumando tutto il credito da 1.600 € in fotografie dirette al costo massimo, la contribuzione totale è 34,2% nel primo anno. Al rinnovo fisso lo sconto è 100 € rispetto ai quattro servizi separati.'),
    para('Oraculi Start aggiunto a 2.500 € deriva da 3.400 € meno 640 € di corso già compreso e 260 € di configurazione condivisa. Il costo incrementale stimato è 1.452 €, eliminando il corso duplicato e due ore tecniche già coperte nell’avvio comune. La contribuzione incrementale è 31,9%; gli abbinamenti Start hanno una contribuzione complessiva di circa 34–35%.'),
],False),
page('mercato','Posizionamento e limiti delle evidenze di mercato',[
    para('I listini ufficiali già verificati il 12 novembre restano il confronto commerciale: Chatbase 40 / 150 / 500 USD al mese; Dify Professional 59 USD/mese; Compliance GDPR 270 / 510 €/anno; PrivacyLab Base 430 €/anno. Sono software da configurare, con servizi e quote differenti dai pacchetti assistiti.'),
    para('Per WearFrame, Pebblely propone 200 immagini a 19 USD/mese e 500 a 39 USD/mese; Photoroom API distingue chiamate Basic e Plus da 0,02 e 0,10 USD. Non dimostrano un costo equivalente per immagini di prodotto con riferimenti, controlli e tentativi. Mostrano però che il cliente autonomo ha alternative economiche.'),
    para('Le quote fisse di 3.900 / 6.500 / 9.900 € di WearFrame si giustificano soltanto per chi beneficia di avviamento, procedure, formazione, supporto e ambiente gestito. Non presentarli come prezzi medi del mercato. La quota fissa remunera il servizio e il corso; il consumo si confronta separatamente, a 0,80 € per immagine fotografica diretta oppure secondo il flusso concordato.'),
    para('Nel campione formazione: Kiwa propone otto ore online a 380 € standard o 300 € per clienti, oltre IVA per persona; SpaccioAI una giornata in aula a 390 € per persona. La quota proposta di 320 € in coworking e 240 € online è una scelta commerciale coerente con il servizio comune, non una media statistica.'),
    cite('Pebblely prezzi','https://pebblely.com/pricing/'),cite('Photoroom API prezzi','https://www.photoroom.com/api/pricing'),
    cite('Kiwa formazione AI','https://www.kiwa.com/it/it/servizi/formazione/ai-per-il-business-fondamenti-operativi/'),
    para('Il dominio wearframe.com non è stato collegato con certezza al nostro prodotto. Non uso il suo listino. Il runtime locale indica WearFrame.net; il sito pubblico non ha fornito testo utile. Resta valida la distinzione fra funzionalità dimostrate dal codice e servizio da collaudare alla consegna.'),
],False),
]

def data():
    stress={}
    variant_results={}
    for p,v in INPUTS.items():
        stress[p]=[];variant_results[p]=[]
        for i in range(3):
            # Crescita di costo applicata prima della riserva, nessun doppio accantonamento.
            extra=D(v['cloud'][i]+v['ai'][i]+MAINTENANCE[i])*D('.25')*D('1.1')
            first=D(str(RESULTS[p][i]['first_cost'])); annual=D(str(RESULTS[p][i]['annual_cost']))
            stress[p].append({'cost_increase_pct':25,
              'first_margin_pct':float((D(BASE_PRICES[p][i])*D('.9')-first-extra)/BASE_PRICES[p][i]*100),
              'renewal_margin_pct':float((D(RENEWALS[p][i])*D('.9')-annual-extra)/RENEWALS[p][i]*100)})
            tc=D('132.5')*SEATS[i]
            costs=[D('107.5')*SEATS[i],tc,D(860),D(1030)]
            vals=[]
            for n,c in zip(variants(p,i),costs):
                dc=first+(c-tc)*D('1.10')
                if p=='WearFrame':dc+=D(WEAR_QUOTA[i])*D('.60')*D('1.10')+D(WEAR_WALLET[i])*D('.025')
                vals.append({'price':n,'direct_cost':float(dc),'margin_pct':float((D(n)*D('.9')-dc)/n*100)})
            variant_results[p].append(vals)
    sources=[{'id':s[0],'title':s[1],'url':s[2],'checked_at':'2026-11-12','supports':s[3],'limits':'Confronto non omogeneo con servizio assistito'} for s in old.SOURCES]
    sources += [
      {'id':'R02-C01','url':'https://laravel.com/cloud/docs/pricing','checked_at':'2026-11-12','supports':'Upstash 250 MB 5 USD, poi 10 dal 30 novembre; dismissione 31 agosto 2027; piano piattaforma separato'},
      {'id':'R02-C02','url':'https://aistudio.google.com/docs/pricing#gemini-3.1-flash-image','checked_at':'2026-11-12','supports':'Output 2K 0,101 USD, input e ragionamento distinti'},
      {'id':'R02-C03','url':'https://openrouter.ai/pricing','checked_at':'2026-11-12','supports':'Commissioni acquisto crediti Standard 5,5%, Business 8%; non costo completo per output'},
      {'id':'R02-P01','path':'sorgenti/audit_costi_2026-11-12.json','checked_at':'2026-11-12','supports':'Consumi Cloud osservati e limiti dei registri locali'},
      {'id':'R02-P02','private_email_id':'1a1cd3327c7415c3','invoice':'LAR-D623-202611-216896','checked_at':'2026-11-12','supports':'Fattura personale 213,66 USD e riscontro compute tramite ID; estrazione parziale, non attribuzione intera fattura'},
      {'id':'R02-P03','private_email_id':'1a1c0cdeef8a2f87','checked_at':'2026-11-12','supports':'Surface ospita Ask My Docs main e gdpr-facile production; nessun costo nel riepilogo'},
      {'id':'R02-P04','path':'docs/handoff/agent-cloud-followup-queue.md','checked_at':'2026-11-12','supports':'Worker develop AskMyDocs 8,84–220,92 USD di configurazione al 29 ottobre, non fattura produzione'},
    ]
    profiles=[]
    for label,cost,price in [('economico','.15','.25'),('foto_diretta','.60','.80'),('flusso_base','.75','1.00'),('flusso_complesso','1.50','2.00')]:
        c,pv=D(cost),D(price)
        contribution=pv*D('.875')-c*D('1.10')
        assert contribution>0
        profiles.append({'profile':label,'cost_per_final_result':float(c),'selling_price_per_final_result':float(pv),'contribution_per_result':float(contribution),'contribution_pct':float(contribution/pv*100)})
    mixed=[]
    for i,q in enumerate(WEAR_QUOTA):
        wallet=D(WEAR_WALLET[i]); ai=D('.60')*q*D('1.1'); fee=wallet*D('.025')
        first=D(str(RESULTS['WearFrame'][i]['first_cost']))+ai+fee
        renewal=D(str(RESULTS['WearFrame'][i]['annual_cost']))+ai+fee
        mixed.append({'tier':TIERS[i],'wallet':int(wallet),'photo_equivalent':q,'flow_2eur_equivalent':int(wallet/2),
          'first_year_total':PRICES['WearFrame'][i],'first_year_full_credit_cost':float(first),
          'first_margin_pct_if_credit_used':float((D(PRICES['WearFrame'][i])*D('.9')-first)/PRICES['WearFrame'][i]*100),
          'renewal_fixed_plus_same_credit':RENEWALS['WearFrame'][i]+int(wallet),
          'renewal_margin_pct_if_credit_used':float((D(RENEWALS['WearFrame'][i]+int(wallet))*D('.9')-renewal)/(RENEWALS['WearFrame'][i]+int(wallet))*100)})
    return {'revision':'02','date':'2026-11-12','currency':'EUR','tax':'IVA esclusa',
      'first_year_total':PRICES,'first_year_service_excluding_wearframe_credit':BASE_PRICES,'renewals':RENEWALS,
      'renewal_note':'WearFrame: sola quota fissa, consumo separato. Altri prodotti: quote AI delle schede incluse.',
      'training_seats':SEATS,'training_value':TRAINING,'training_cost_per_seat':132.5,'maintenance':MAINTENANCE,
      'cost_inputs':INPUTS,'fixed_service_cost_results':RESULTS,'technical_hourly_cost':55,'support_hourly_cost':45,
      'cost_reserve_pct':10,'hypothetical_commission_pct':10,'wallet_collection_cost_pct_assumption':2.5,
      'wearframe':{'pricing_model':'quota fissa servizio con corso + credito euro a consumo per risultato finale',
        'initial_credit':WEAR_WALLET,'profiles':profiles,'examples':mixed,'topup_amounts':[100,500,1000],
        'cost_source':'Indicazione operativa Marco, non media fatture misurata',
        'intermediate_images':'incluse nel prezzo preventivato per il flusso, nessun doppio conteggio',
        'counting_status':'da configurare e collaudare prima della consegna; controllo servizio gestito se necessario'},
      'ask_standard_reply_quota':ASK_QUOTA,'variants_order':['online_shared','coworking','online_private','onsite_local'],
      'variant_results':variant_results,'stress_25pct_cloud_ai_maintenance':stress,
      'bundle':{'first_year_total':BUNDLE_PRICE,'wearframe_credit_included':1600,'renewal_fixed':BUNDLE_RENEW,
        'fixed_service_first_cost':float(BUNDLE_COST),'annual_fixed_cost':float(BUNDLE_ANNUAL),
        'renewal_plus_same_credit':BUNDLE_RENEW+1600},
      'actual_cost_status':{'WearFrame':'Cloud osservato; costo completo per output fornito da Marco',
        'Oraculi':'Cloud stage osservato, non benchmark produzione',
        'AskMyDocs':'Consuntivo Surface verificato; cluster DB condiviso main/develop; chiave AI locale non attribuita a produzione',
        'Portale GDPR':'Consuntivo produzione e staging verificato; fattura Surface non riconciliata'},
      'sources':sources+[{'id':'R02-P05','path':'revisione02/audit_costi_verificati.json','checked_at':'2026-11-12','supports':'Consuntivi Surface e input costi per risultato forniti da Marco'}]}

def main():
    OUT.mkdir(exist_ok=True)
    old.make_doc(CAT,OUT/'Preventivi_Lorenzo_revisione02.docx','Catalogo e preventivi revisione 02')
    old.make_doc(REPORT,OUT/'Costi_e_sostenibilita_revisione02.docx','Analisi interna revisione 02')
    (OUT/'calcolo_prezzi_revisione02.json').write_text(json.dumps(data(),ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({'catalogue_pages':len(CAT),'report_pages':len(REPORT),'output':str(OUT)},ensure_ascii=False))

if __name__=='__main__':main()
