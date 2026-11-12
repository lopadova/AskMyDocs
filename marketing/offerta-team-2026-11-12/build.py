"""Listino AskMyDocs a team: canone, utilizzo, consulenza e corsi separati."""
from pathlib import Path
from decimal import Decimal as D, ROUND_CEILING
from copy import deepcopy
import json, hashlib, importlib.util

ROOT=Path(__file__).resolve().parents[2]
HERE=Path(__file__).resolve().parent
PREV=ROOT/'marketing/offerte-con-formazione-2026-11-12'
helper_path=ROOT/'marketing/offerte-portafoglio-2026-11-12/sorgenti/genera_documenti.py'
spec=importlib.util.spec_from_file_location('document_helpers',helper_path)
helpers=importlib.util.module_from_spec(spec);spec.loader.exec_module(helpers)
para,h,table,page,euro=helpers.para,helpers.h,helpers.table,helpers.page,helpers.euro
PLANS=['Team','Business','Scale']
MONTHLY=[149,299,599]
ANNUAL=[n*12 for n in MONTHLY]
STORAGE=[20,100,300]
CONCURRENT=[2,5,10]
CONSULTING=[1200,2400,4200]
HOURS=[8,16,28]
COURSE={'online_shared_per_person':240,'coworking_per_person':320,'online_private':1400,'onsite_private':1800,'local_travel':250}
EXAMPLES=[
 {'name':'Solo piattaforma Team','plan':0,'credit':100,'consulting':0,'course':0},
 {'name':'Primo progetto Team','plan':0,'credit':100,'consulting':1200,'course':640},
 {'name':'Reparto Business','plan':1,'credit':300,'consulting':2400,'course':1400},
 {'name':'Diffusione Scale','plan':2,'credit':500,'consulting':4200,'course':2050},
]
for e in EXAMPLES:e['total']=ANNUAL[e['plan']]+e['credit']+e['consulting']+e['course']
assert [e['total'] for e in EXAMPLES]==[1888,3728,7688,13938]

def credits(cost):return int((D(str(cost))*D('2.5')/D('.01')).to_integral_value(rounding=ROUND_CEILING))
def block(title,text):return {'heading':title,'text':text}
def art(name,height=145,caption='Illustrazione editoriale del prodotto'):
    return {'path':f'public/editorial/{name}-day.webp','height':height,'fit':'cover','focus':[.5,.5],'caption':caption}
def editorial(id,layout,title,deck='',**kw):
    return {'id':id,'layout':layout,'section':title.replace('\n',' '),'title':title,'deck':deck,
      'source_ids':['TEAM-PROPOSAL','AMD-FACTS','AMD-BRAND'],'feature_ids':kw.pop('feature_ids',[]),**kw}

def build_cost_model():
    results=[]
    for i in range(3):
        cloud=[720,1440,2400][i];maintenance=[110,220,440][i];support_hours=[2,4,8][i]
        recurring=D(cloud+maintenance+support_hours*45)*D('1.10')
        setup=D(3*55)*D('1.10')
        revenue=D(ANNUAL[i]);after_fees=revenue*D('.875')
        results.append({'plan':PLANS[i],'annual_revenue':ANNUAL[i],'cloud_budget':cloud,
          'maintenance_budget':maintenance,'technical_support_hours_assumption':support_hours,
          'recurring_direct_cost':float(recurring),'standard_activation_cost':float(setup),
          'first_year_contribution_pct':float((after_fees-recurring-setup)/revenue*100),
          'renewal_contribution_pct':float((after_fees-recurring)/revenue*100),
          'cloud_plus_25pct_first_year_margin':float((after_fees-recurring-setup-D(cloud)*D('.25')*D('1.1'))/revenue*100)})
        assert after_fees>recurring+setup
    unit_examples=[]
    for c in ['.008','.04','.20','1.00']:
        retail=D(credits(c))*D('.01');margin=(retail*D('.875')-D(c)*D('1.1'))/retail
        assert margin>=D('.435')
        unit_examples.append({'technical_cost_eur_hypothetical':float(D(c)),'credits':credits(c),'retail_eur':float(retail),'contribution_pct':float(margin*100)})
    return {'currency':'EUR','assumptions_not_actual_staff_costs':True,'loaded_technical_hour':55,'support_hour':45,
      'direct_cost_reserve_pct':10,'hypothetical_sales_commission_pct':10,'collection_cost_pct_assumption':2.5,
      'standard_activation_hours_included':3,'fixed_service_results':results,
      'ai_usage':{'rule':'ceil(technical_cost_eur_complete * 2.5 / 0.01) credits','credit_value_eur':.01,
        'technical_cost_scope':'Intera operazione: modelli, embedding, OCR, verifiche, tentativi e servizi AI a pagamento; commissioni provider e cambio effettivo di acquisto inclusi una sola volta',
        'usage_revenue_recognized_in_model':'solo utilizzo, non credito ancora non consumato',
        'examples_are_arithmetic_not_measured_average':unit_examples},
      'consulting':[{'hours':HOURS[i],'price':CONSULTING[i],'direct_cost':float(D(HOURS[i]*55)*D('1.1')),
        'contribution_pct':float((D(CONSULTING[i])*D('.875')-D(HOURS[i]*55)*D('1.1'))/CONSULTING[i]*100)} for i in range(3)],
      'cloud_evidence':'../offerte-portafoglio-2026-11-12/revisione02/audit_costi_verificati.json',
      'limits':['Il consumo completo per risposta AskMyDocs in produzione non è ancora riconciliato; niente equivalenze universali domande/crediti',
        'Sostenibilità degli utenti illimitati subordinata a budget AI, risorse finite, code e limiti di parallelismo del team',
        'Contatori, saldo, prenotazione budget e limiti di coda da configurare e collaudare prima di applicare questo nuovo listino',
        'Non sono utili netti; sviluppo evolutivo, struttura generale e imposte esclusi']}

def main():
    HERE.mkdir(exist_ok=True)
    offer={'revision':'03 AskMyDocs Team','date':'2026-11-12','tax':'IVA esclusa','valid_until':'2026-12-12',
      'plans':[{'name':PLANS[i],'monthly_equivalent':MONTHLY[i],'annual_billed':ANNUAL[i],'term_months':12,
        'users':'illimitati','projects':'illimitati','storage_gb':STORAGE[i],'parallel_ai_operations':CONCURRENT[i],
        'included_ai_credit':0,'included_course':False,'standard_team_activation_included':True} for i in range(3)],
      'credit_value_eur':.01,'topups_eur':[50,100,250],'credit_expiry':'Crediti acquistati non in scadenza; utilizzabili con abbonamento attivo dello stesso team',
      'consulting_prices':CONSULTING,'consulting_hours':HOURS,'course_prices':COURSE,'quotes':EXAMPLES,
      'supersedes':'Solo AskMyDocs: sostituisce pacchetti precedenti per utente e corso obbligatorio. Gli altri prodotti restano invariati.',
      'old_portfolio_bundle':'Vecchi totali dei bundle con AskMyDocs da riformulare prima di riutilizzarli; non fanno parte di questo listino.'}
    (HERE/'offerta.json').write_text(json.dumps(offer,ensure_ascii=False,indent=2)+'\n')
    model=build_cost_model();(HERE/'modello_costi_interno.json').write_text(json.dumps(model,ensure_ascii=False,indent=2)+'\n')
    fonti={'sources':[
      {'id':'ANNOTA-20261112','url':'https://annota.ai/it/pricing/#plans','publisher':'Annota AI','checked_at':'2026-11-12','supports':'Team 24 euro + IVA per membro/mese, minimo due; quote di crediti e spazio condivise. I crediti per membro si sommano.','limits':'Prodotti e capacità differenti: confronto della struttura commerciale, non equivalenza delle prestazioni.'},
      {'id':'AMD-CLOUD','path':'marketing/offerte-portafoglio-2026-11-12/revisione02/audit_costi_verificati.json','checked_at':'2026-11-12','supports':'Costi Cloud Surface osservati, separazione main/develop e limiti della misura AI locale'},
      {'id':'AMD-FACTS','path':'marketing/analisi/presentazione-prodotto-2026-10-29/inventario-candidato.json','supports':'ID funzionali di ricerca, fonti, ingestione e organizzazioni; non prova di nuovi limiti commerciali già applicati'},
      {'id':'AMD-BRAND','path':'marketing/offerte-con-formazione-2026-11-12/brand.json','supports':'Identità da landing e asset verificati, font Instrument Serif e Geist'},
      {'id':'TEAM-PROPOSAL','path':'offerta.json','supports':'Proposta commerciale nuova, non tariffa già attiva o approvata da clienti'},
      {'id':'OR-PRICING','url':'https://openrouter.ai/pricing','checked_at':'2026-11-12','supports':'Commissioni su acquisto crediti distinte dall’inferenza; costo completo da valorizzare senza doppio conteggio'},
    ]};(HERE/'fonti.json').write_text(json.dumps(fonti,ensure_ascii=False,indent=2)+'\n')
    theme=deepcopy(json.loads((PREV/'contenuti.json').read_text())['theme'])
    # La griglia e gli asset restano quelli approvati; riscrittura completa dei contenuti commerciali.
    pages=[
      editorial('cover','cover','Una piattaforma per il team\nUn percorso per le persone',
        'Utenti illimitati, utilizzo sotto controllo.\nConsulenza e formazione si scelgono e si quotano a parte.',
        kicker='OFFERTA A TEAM',display_name='AskMyDocs',image=art('knowledge'),
        band_title='CANONE TEAM / CONSUMI AI / CONSULENZA / CORSI',band_subtitle='NUOVO LISTINO ASKMYDOCS / 12 NOVEMBRE 2026'),
      editorial('value','article','Invitare tutto il gruppo\nImparare a lavorare insieme',
        'Il canone riguarda l’organizzazione. Il valore del primo progetto nasce dalla scelta delle fonti, dalle competenze delle persone e dal metodo di verifica.',kicker='IL MODELLO COMMERCIALE',columns=[
        [block('Un prezzo per il team','Potete invitare gli utenti autorizzati della stessa organizzazione senza acquistare posti aggiuntivi. Ogni persona ha un account e i propri permessi. Il piano cambia quando servono più spazio o maggiore capacità di elaborazione.'),
         block('Il software rende disponibili le fonti','AskMyDocs raccoglie documenti, li indicizza e permette di interrogarli con risposte accompagnate dai riferimenti. Il team deve sapere quali contenuti usare e come controllare ciò che ottiene.')],
        [block('Il primo valore è l’affiancamento','Per un’azienda all’inizio del percorso AI suggeriamo formazione di base e consulenza su un primo caso concreto. Lavoriamo su domande ricorrenti, materiali, accessi e criteri di riuscita.'),
         block('Quattro voci riconoscibili','Abbonamento software, credito AI, consulenza e corsi hanno prezzi separati. Potete acquistare la piattaforma da sola oppure costruire il percorso di adozione più adatto al vostro livello di preparazione.')]],
        callout='L’accesso può essere esteso a tutto il gruppo. La formazione e la consulenza preparano le persone a usarlo con criterio.'),
      editorial('case','case','Una domanda che torna\nUn archivio da cui partire',
        'Scenario illustrativo: l’ufficio cerca condizioni e riferimenti in fatture, bollette o preventivi già disponibili.',kicker='IL PRIMO CASO',image=art('archive',145,'Illustrazione del prodotto. Scenario esemplificativo, non una testimonianza.'),columns=[
        [block('Scegliere le fonti giuste','Il referente seleziona un corpus leggibile e aggiornato. Nella consulenza possiamo aiutarlo a organizzare i materiali, impostare gli accessi e definire le domande da provare.'),
         block('Seguire il percorso della risposta','La ricerca aiuta a ritrovare il documento pertinente e a formulare una risposta. Il referente apre le fonti, controlla i dettagli e riconosce quando la documentazione non basta.')],
        [block('Portare il metodo nel gruppo','Nel percorso di avvio guidato concordiamo venti domande e ne valutiamo insieme gli esiti. Il team riceve una procedura da usare nelle attività successive.'),
         block('Riconoscere i confini','Ricerca documentale, OCR e AI richiedono verifica umana. Contabilità, riconciliazioni fiscali e calcoli certificati hanno un perimetro diverso. La consulenza di avvio si acquista separatamente dal software.')]]),
      editorial('plans','table','Tre piani\nSempre utenti illimitati',
        'Canone per una singola organizzazione aziendale. Importi IVA esclusa. I valori mensili sono equivalenti di un abbonamento annuale fatturato anticipatamente.',kicker='ABBONAMENTO SOFTWARE',headers=['CONTENUTO','TEAM','BUSINESS','SCALE'],column_weights=[2.1,1,1,1],table_font=9,padding=7,
        rows=[['Equivalente al mese per team']+[euro(x) for x in MONTHLY],['Totale fatturato per 12 mesi']+[euro(x) for x in ANNUAL],
          ['Utenti nominativi','Illimitati','Illimitati','Illimitati'],['Progetti e archivi','Illimitati','Illimitati','Illimitati'],
          ['Storage condiviso','20 GB','100 GB','300 GB'],['Operazioni AI contemporanee','2','5','10'],
          ['Caricamento e lettura dei file','Inclusi','Inclusi','Inclusi'],['Attivazione standard del team','Inclusa','Inclusa','Inclusa'],
          ['Consumi AI','A credito','A credito','A credito'],['Consulenza e corsi','Extra a scelta','Extra a scelta','Extra a scelta']],
        note='Stesse funzioni principali di ricerca documentale, chat con fonti e gestione degli accessi. L’attivazione standard configura team, amministratore, profilo AI e parametri generali. Nessun prezzo per utente e nessun credito AI incluso nel canone. Documenti e progetti entro lo spazio disponibile. Le elaborazioni oltre il parallelismo del piano restano in coda; utenti illimitati non significa risorse di calcolo illimitate. Installazioni private e integrazioni si dimensionano a parte.'),
      editorial('usage','table','Un saldo condiviso\nUn budget comprensibile',
        'Il credito AI è separato dal canone ed è condiviso dall’intero team. Un credito equivale a 0,01 € di utilizzo, IVA esclusa.',kicker='UTILIZZO DELL’INTELLIGENZA ARTIFICIALE',headers=['VOCE','REGOLA'],column_weights=[1.2,2.5],table_font=9.5,padding=8,
        rows=[['Ricariche','50 € = 5.000 crediti; 100 € = 10.000; 250 € = 25.000'],
          ['Operazioni a consumo','OCR, indicizzazione, risposte e analisi AI'],
          ['Calcolo','2,5 volte il costo tecnico AI completo dell’operazione, arrotondato al credito superiore'],
          ['Costo tecnico completo','Include tutti i passaggi AI e i tentativi necessari, con commissioni provider e cambio effettivo di acquisto'],
          ['Esempi di spesa','100 € coprono 5.000 operazioni da 0,02 €, oppure 1.000 da 0,10 €, oppure 200 da 0,50 €'],
          ['Controllo','Prezzo stimato o massimale prima dell’esecuzione; nessun superamento del budget senza autorizzazione'],
          ['Saldo esaurito','Si fermano le nuove operazioni AI; documenti già disponibili restano leggibili ed esportabili durante il servizio'],
          ['Validità','Crediti acquistati senza scadenza, utilizzabili con abbonamento attivo dello stesso team']],
        note='Gli esempi illustrano la matematica del budget e non garantiscono lo stesso numero di risposte per qualsiasi modello o domanda. I token sono unità tecniche dei modelli e non coincidono con i crediti commerciali. Caricare, leggere o esportare un file non consuma credito AI; estrarlo con OCR, indicizzarlo o analizzarlo sì. Nessuna ricarica automatica obbligatoria.'),
      editorial('consulting','table','La consulenza\ntrasforma il primo utilizzo',
        'Servizi una tantum, acquistabili separatamente e abbinabili a qualsiasi piano software. Le ore indicate comprendono preparazione, incontri e lavoro sul caso concordato.',kicker='CONSULENZA DI ADOZIONE EXTRA',headers=['PERCORSO','PRIMO CASO','REPARTO','DIFFUSIONE'],column_weights=[1.7,1,1,1.1],table_font=9,padding=7,
        rows=[['Prezzo una tantum']+[euro(x) for x in CONSULTING],['Monte ore complessivo','8 ore','16 ore','28 ore'],
          ['Casi di lavoro delimitati','1','Fino a 3','Fino a 6'],['Materiali assistiti iniziali','100 file / 1.000 pagine','500 file / 5.000 pagine','1.500 file / 15.000 pagine'],
          ['Attività','Scelta caso, fonti, accessi, laboratorio e 20 domande di prova','Organizzazione del reparto, referenti, materiali e verifica dei casi','Metodo comune, responsabilità e piano di diffusione tra reparti']],
        note='Il primo progetto parte dal lavoro che il cliente vuole migliorare. La consulenza include scelta del corpus, configurazione del caso, caricamento assistito entro soglia e verifica con il referente; i consumi AI si addebitano sul credito, senza un secondo costo tecnico già nascosto nella consulenza. Non comprende il corso base, riscrittura integrale dei manuali o sviluppo di integrazioni. Ulteriori ore di consulenza: 150 € online; trasferte quotate prima dell’ordine.'),
      editorial('courses','table','La formazione è un servizio\nIl suo prezzo è esplicito',
        'Corso base AI di otto ore, facoltativo rispetto all’abbonamento e consigliato alle aziende che iniziano. Il prezzo comprende docente, materiali, esercitazioni e verifica finale.',kicker='CORSI EXTRA',headers=['MODALITÀ','PREZZO IVA ESCLUSA','PARTECIPANTI'],column_weights=[1.6,1.1,1.1],table_font=9.5,padding=8,
        rows=[['Online condiviso','240 € a persona','Posti acquistati'],['Coworking condiviso','320 € a persona','Posti acquistati'],
          ['Online dedicato','1.400 € per gruppo','Fino a 8 persone'],['In sede del cliente','1.800 € + trasferta','Fino a 8 persone'],
          ['Trasferta locale proposta','250 € per visita','Entro 50 km e massimo 2 ore A/R']],
        note='Programma: due ore sul funzionamento dei modelli, due su richieste e verifica, due su dati e responsabilità, due di esercizi. Dispensa, checklist, attestato di partecipazione e accesso per 90 giorni al solo corso AI su Oraculi con 100 risposte tutor per partecipante. Il corso condiviso si organizza inizialmente ogni quindici giorni: due mezze giornate in coworking o quattro incontri online. Calendario concordato all’ordine e completamento entro 90 giorni. In presenza serve una sala tranquilla con schermo, connessione e un computer per persona; oltre la trasferta locale si fissa il totale prima dell’ordine.'),
      editorial('quotes','table','Il preventivo mostra\nche cosa state acquistando',
        'Esempi per il primo anno, IVA esclusa. Il credito è un budget iniziale di utilizzo; corso e consulenza sono scelte separate.',kicker='QUATTRO VOCI DISTINTE',headers=['VOCE','SOLO TEAM','PRIMO PROGETTO','REPARTO'],column_weights=[1.8,1,1.1,1.1],table_font=9,padding=8,
        rows=[['Piano software','Team','Team','Business'],['Abbonamento 12 mesi','1.788 €','1.788 €','3.588 €'],['Credito AI iniziale','100 €','100 €','300 €'],
          ['Consulenza','Non acquistata','1.200 € / 8 ore','2.400 € / 16 ore'],['Corso','Non acquistato','640 € / 2 posti coworking','1.400 € / online fino a 8'],['Totale primo anno','1.888 €','3.728 €','7.688 €']],
        note='Esempio Scale: software 7.188 € + credito 500 € + consulenza 4.200 € + corso in sede 1.800 € e trasferta locale 250 € = 13.938 €. Il rinnovo riguarda il solo abbonamento; nuovi crediti e servizi si acquistano quando servono. Software annuale anticipato, crediti prepagati, consulenza e corsi 50% all’ordine e 50% al completamento. Validità fino al 12 dicembre 2026. L’ammissibilità a un bando dipende dal testo vigente; non è un contributo già concesso.'),
      editorial('closing','closing','Scegliamo il primo lavoro\nPoi costruiamo il percorso',
        'La piattaforma dà accesso al gruppo. Consulenza e formazione aiutano l’azienda a costruire un uso comprensibile e verificabile dell’AI.\naskmydocs.surfacesrl.com',kicker='IL PROSSIMO PASSO',image=art('community',260),
        quote='Una singola organizzazione, utenti illimitati, consumi condivisi. L’affiancamento si sceglie in base alle competenze e agli obiettivi del team.')
    ]
    brochure={'document':{'title':'AskMyDocs abbonamento a team e servizi di adozione','brand_name':'AskMyDocs','edition':'12 NOVEMBRE 2026 / REV 03','header':'ASKMYDOCS / TEAM E ADOZIONE AI','footer_note':'Proposta commerciale · IVA esclusa · Immagini illustrative','author':'','source_ids':['TEAM-PROPOSAL','AMD-FACTS','AMD-BRAND']},'theme':theme,'pages':pages}
    (HERE/'brochure.json').write_text(json.dumps(brochure,ensure_ascii=False,indent=2)+'\n')
    client_pages=[
      page('listino','AskMyDocs listino a team',[
        para('Revisione 03 del 12 novembre 2026. Canone fisso per organizzazione con utenti e progetti illimitati. Consumi AI, consulenza e corsi sono voci separate. Il corso è facoltativo rispetto all’abbonamento e consigliato alle aziende che iniziano. Tutti gli importi sono IVA esclusa.'),
        table(['Piano','Team','Business','Scale'],[
          ['Equivalente mensile per team']+[euro(x) for x in MONTHLY],['Fatturato annuo anticipato']+[euro(x) for x in ANNUAL],
          ['Utenti / progetti','Illimitati','Illimitati','Illimitati'],['Storage condiviso','20 GB','100 GB','300 GB'],['Operazioni AI in parallelo','2','5','10'],
          ['Credito AI incluso','Nessuno','Nessuno','Nessuno'],['Attivazione standard','Inclusa','Inclusa','Inclusa']], [2,.95,.95,.95]),
        para('Un team è una singola organizzazione aziendale; nessuna tariffa per gli utenti invitati. Stesse funzioni principali di ricerca e chat con fonti. Attivazione standard di team, amministratore, profilo AI e parametri generali compresa; preparazione del caso e formazione sono extra. File e derivati entro lo storage, progetti senza una soglia numerica distinta. Richieste eccedenti il parallelismo in coda. Un file standard non supera 20 MB e 200 pagine.'),
        h('Credito AI condiviso e costo delle operazioni'),
        para('1 credito = 0,01 € di utilizzo. Ricariche: 50 € / 5.000 crediti, 100 € / 10.000, 250 € / 25.000. Utilizzo fatturato a 2,5 volte il costo tecnico AI completo, arrotondato al credito superiore. Si considerano tutti i passaggi dell’operazione, comprese commissioni del provider e conversione al cambio effettivo di acquisto, senza doppio conteggio.'),
        para('OCR, indicizzazione, risposte e analisi consumano credito; caricamento, lettura ed esportazione dei file non lo consumano. Prima dell’esecuzione si indica una stima o un massimale. Nessun superamento del budget senza autorizzazione e nessuna ricarica obbligatoria automatica. I crediti acquistati non scadono: si usano con il servizio attivo dello stesso team.'),
        para('Esempi aritmetici: 100 € coprono 5.000 operazioni da 0,02 €, oppure 1.000 da 0,10 €, oppure 200 da 0,50 €. Non sono quantità garantite per una qualsiasi domanda o modello. I crediti commerciali non equivalgono ai token dei modelli.'),
      ]),
      page('servizi','Consulenza e formazione acquistabili a parte',[
        para('Il valore dell’avvio è aiutare il cliente a scegliere un lavoro utile, preparare le fonti e formare chi lo userà. I servizi si abbinano a qualsiasi piano e non vengono imposti come condizione per acquistare il software.'),
        table(['Consulenza una tantum','Primo caso','Reparto','Diffusione'],[
          ['Prezzo']+[euro(x) for x in CONSULTING],['Ore totali','8','16','28'],['Casi delimitati','1','Fino a 3','Fino a 6'],
          ['Materiali iniziali assistiti','100 file / 1.000 pagine','500 file / 5.000 pagine','1.500 file / 15.000 pagine']], [1.6,1.1,1.1,1.1]),
        para('Le ore includono preparazione, incontri e attività sul caso: analisi del bisogno, fonti e accessi, caricamento assistito, laboratorio e verifica delle risposte con il referente. Nel Primo caso si concordano venti domande di prova. Consumi AI sul credito, corso base separato. Integrazioni, riscrittura integrale dei manuali e ulteriori attività richiedono un perimetro proprio. Ore online aggiuntive: 150 €; trasferte a preventivo.'),
        table(['Corso base AI di otto ore','Prezzo'],[
          ['Online condiviso','240 € a persona'],['Coworking condiviso','320 € a persona'],['Online dedicato fino a 8 persone','1.400 € a gruppo'],['In sede fino a 8 persone','1.800 € + trasferta'],['Trasferta locale','250 € per visita entro 50 km e massimo 2 ore A/R']], [3.6,1.5]),
        para('Programma: funzionamento dei modelli, richieste e verifica, dati e responsabilità, esercitazioni e verifica finale. Compresi docente, materiali, checklist, presenze, attestato e 90 giorni sul solo corso AI di Oraculi con 100 risposte tutor per partecipante. Nessuna licenza Oraculi aziendale inclusa.'),
        para('Corso condiviso inizialmente ogni quindici giorni, con calendario all’ordine e completamento entro 90 giorni; due mezze giornate in coworking oppure quattro incontri online. Per la presenza serve una sala dedicata e tranquilla, schermo, connessione e un computer per persona. Oltre la trasferta locale si concorda il totale prima dell’ordine.'),
      ]),
      page('preventivi','Preventivi tipo con quattro voci separate',[
        table(['Voce','Solo Team','Primo progetto','Reparto'],[
          ['Piano','Team','Team','Business'],['Software per 12 mesi','1.788 €','1.788 €','3.588 €'],['Credito AI iniziale','100 €','100 €','300 €'],['Consulenza','0 €','1.200 €','2.400 €'],['Corso','0 €','640 € coworking 2 persone','1.400 € online fino a 8'],['Totale IVA esclusa','1.888 €','3.728 €','7.688 €']], [1.6,1.1,1.1,1.1]),
        para('Esempio Diffusione Scale: 7.188 € software + 500 € credito + 4.200 € consulenza + 1.800 € corso in sede + 250 € trasferta locale = 13.938 €. Sono combinazioni esemplificative: corso, percorso di consulenza e piano software si scelgono indipendentemente.'),
        h('Servizio e condizioni'),
        para('Software per dodici mesi dalla consegna del team, con hosting gestito, database, manutenzione ordinaria, backup giornaliero con 14 giorni di conservazione e prova di ripristino all’avvio. Infrastruttura condivisa, senza server dedicato. Provider, regioni e condizioni sul trattamento dati vengono dichiarati prima del caricamento dei dati reali.'),
        para('Assistenza tecnica del servizio tramite un referente del team nei feriali 9-18; presa in carico ordinaria entro due giorni lavorativi, senza presidio 24 ore. Consulenza sul metodo e nuovi corsi si acquistano a parte. La correzione dei difetti del servizio compreso non viene fatturata come consulenza.'),
        para('Software annuale anticipato, crediti prepagati, consulenza e corsi 50% all’ordine e 50% a completamento. Rinnovo dell’abbonamento confermato dal cliente; crediti e servizi non si riacquistano obbligatoriamente insieme. In uscita si concorda l’esportazione dei formati disponibili entro 30 giorni dalla scadenza.'),
        para('Proposta valida fino al 12 dicembre 2026. Nessuna integrazione ERP o riconciliazione contabile compresa. L’AI richiede verifica umana. Ammissibilità a bandi da valutare sul testo vigente, senza contributi dati per acquisiti.'),
        para('Cliente ____________________  Fornitore ____________________\nPiano __________  Budget AI __________  Consulenza __________\nCorso e partecipanti ____________________  Sede e calendario ____________________'),
      ]),
    ]
    helpers.make_doc(client_pages,HERE/'AskMyDocs_listino_team.docx','AskMyDocs listino team revisione 03')
    word=helpers.Document(HERE/'AskMyDocs_listino_team.docx')
    word.core_properties.subject='Canone a team con utenti illimitati e servizi extra separati'
    word.core_properties.keywords='AskMyDocs, team, utenti illimitati, consumi, consulenza, corsi extra'
    word.save(HERE/'AskMyDocs_listino_team.docx')
    # Nota leggibile interna: le ipotesi economiche non entrano nel documento del cliente.
    lines=['ASKMYDOCS TEAM - NOTA INTERNA DEL 12 NOVEMBRE 2026','',
      'Annota Team: 24 euro + IVA per membro/mese, minimo due. Crediti e spazio per membro condivisi. AskMyDocs propone invece 149/299/599 euro mensili equivalenti per team, su annuale, senza crediti AI inclusi. Il confronto riguarda la struttura, non prestazioni o quantità equivalenti.',
      'Fonte: https://annota.ai/it/pricing/#plans','',
      'COSTI FISSI E MARGINI SIMULATI - NON UTILE NETTO']
    for x in model['fixed_service_results']:
      lines.append(f"{x['plan']}: ricavo annuo {x['annual_revenue']} euro; costo diretto ricorrente {x['recurring_direct_cost']:.2f}; avvio standard {x['standard_activation_cost']:.2f}. Contribuzione anno1 {x['first_year_contribution_pct']:.1f}%, rinnovo {x['renewal_contribution_pct']:.1f}%; cloud +25% anno1 {x['cloud_plus_25pct_first_year_margin']:.1f}%.")
    lines += ['', 'Ipotesi: tecnico55 euro/ora, supporto45, riserva10% costi, provvigione commerciale10% ricavo e incasso2,5%. Avvio tecnico standard tre ore incluso. Supporto tecnico stimato2/4/8 ore annue; manutenzione110/220/440; cloud720/1440/2400. Il canone è annuale anticipato; non è un piano mensile cancellabile dopo un mese.',
      '', 'CONSUMI AI',
      'Costo tecnico completo in euro moltiplicato2,5 e arrotondato al credito da0,01 euro superiore. A parità di costo questo dà contribuzione minima43,5% dopo le ipotesi di commissioni/incasso/riserva, prima dei costi generali. Esempi puramente aritmetici nel JSON, non medie di produzione.',
      'Il credito non utilizzato non viene trattato come margine acquisito nel modello. Il canone finanzia infrastruttura e gestione; il credito finanzia le elaborazioni. Consulenza e corsi sono ricavi e costi separati.',
      '', 'CONTROLLI PRIMA DELL’APPLICAZIONE',*model['limits'],
      'Le fonti Cloud reali sono conservate nell’audit precedente: main con intero DB condiviso attribuito prudenzialmente48,66USD per periodo, totale main+develop73,08USD. Non sono costi per singolo cliente. Non assumere gratuità dai log locali a costo zero.',
      '', 'PROVENIENZA E SUPERAMENTO',offer['supersedes'],offer['old_portfolio_bundle'],
      'I materiali degli altri tre prodotti non sono stati modificati. Questo lavoro non cambia software, sito, contatori, abbonamenti o dati di produzione.']
    (HERE/'Nota_costi_interna.txt').write_text('\n'.join(lines)+'\n')
    print(json.dumps({'brochure_pages':len(pages),'docx_sections':len(client_pages),'output':str(HERE)},ensure_ascii=False))

if __name__=='__main__':main()
