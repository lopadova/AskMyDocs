"""Revisione 06: taglie con crediti inclusi e avvio consigliato a 1.500 euro."""
from pathlib import Path
from copy import deepcopy
from decimal import Decimal as D
import importlib.util
import json

ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent
R05 = ROOT / 'marketing/offerta-zoworking-2026-11-12'
spec = importlib.util.spec_from_file_location('r05_helpers', R05 / 'build.py')
r05 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(r05)
para, h, table, page = r05.para, r05.h, r05.table, r05.page
block, art = r05.block, r05.art

PLANS = [
    {'name': 'S', 'monthly_price': 29, 'included_monthly_credits': 1000, 'sample_documents': 500, 'sample_questions': [450, 900]},
    {'name': 'M', 'monthly_price': 49, 'included_monthly_credits': 3000, 'sample_documents': 1000, 'sample_questions': [1400, 2800]},
    {'name': 'L', 'monthly_price': 99, 'included_monthly_credits': 10000, 'sample_documents': 2000, 'sample_questions': [4800, 9600]},
]
BUNDLE = 1500
COURSE_ONE = 600
COURSE_TWO = 1200
DAY = 700

def out(name, data):
    (HERE / name).write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n')

def ep(id, layout, title, deck='', **kw):
    result = r05.ep(id, layout, title, deck, **kw)
    result['source_ids'] = [x.replace('OFFERTA-R05', 'OFFERTA-R06').replace('MODELLO-R05', 'MODELLO-R06') for x in result['source_ids']]
    return result

def economics():
    result = r05.economics()
    result['revision'] = '07 Pacchetti'
    result['software_fixed']['internal_component_monthly'] = result['software_fixed'].pop('confirmed_monthly_fee')
    result['software_fixed']['public_itemization'] = False
    # La differenza fra prezzo del piano e componente interna finanzia il credito incluso.
    plans = []
    for p in PLANS:
        credit_value = D(p['included_monthly_credits']) * D('.01')
        assert credit_value > 0
        credit_cost = credit_value / D('2.5')
        fixed_direct_month = D(str(result['software_fixed']['annual_direct_per_company'])) / 12
        contribution = D(p['monthly_price']) * D('.875') - credit_cost * D('1.1') - fixed_direct_month
        assert contribution > 0
        remaining = D(p['included_monthly_credits']) - D(p['sample_documents']) * D('.2')
        assert p['sample_questions'][0] * 2 <= remaining
        assert p['sample_questions'][1] <= remaining
        plans.append({**p, 'software_component_internal_only': 19, 'included_credit_value_internal_only': float(credit_value), 'technical_cost_if_all_included_credits_consumed': float(credit_cost), 'monthly_direct_company_cost_with_reserve': float(fixed_direct_month), 'monthly_contribution_before_shared_fixed_costs': float(contribution), 'monthly_contribution_pct': float(contribution / D(p['monthly_price']) * 100), 'unused_credit_not_assumed_as_profit': True})
    result['plans'] = plans
    result['included_credit_rules'] = {'monthly_allowance_does_not_roll_over': True, 'prepaid_extra_credit_does_not_expire': True, 'consume_monthly_allowance_before_extra_wallet': True, 'upgrade_current_cycle': 'Pagare differenza intera di prezzo e ricevere solo differenza di crediti; scadenza mensile invariata, nessun doppio accredito', 'downgrade': 'Al rinnovo successivo'}
    result.pop('course')
    # Una classe può riunire più aziende: aula e docente comuni si contano una sola volta.
    course_rows = []
    for days, price in [(1, COURSE_ONE), (2, COURSE_TWO)]:
        live = days * 6
        prep = days
        shared = D((live + prep) * 55 + days * 50)
        per_group = D(days * 30)  # materiali e credito degli esercizi, fino a 10 iscritti
        for groups in [1, 2]:
            direct_per_group = (shared / groups + per_group) * D('1.1')
            contribution = D(price) * D('.875') - direct_per_group
            course_rows.append({'days': days, 'price': price, 'groups_same_edition': groups, 'people_per_group_assumed': 10, 'direct_cost_per_group_with_reserve': float(direct_per_group), 'contribution_per_group': float(contribution), 'contribution_pct': float(contribution / price * 100)})
    bundle_rows = []
    for row in course_rows:
        if row['days'] != 2:
            continue
        direct = D(str(row['direct_cost_per_group_with_reserve'])) + D('423.5')
        contribution = D(BUNDLE) * D('.875') - direct
        bundle_rows.append({'groups_same_course_edition': row['groups_same_edition'], 'consulting_day_is_dedicated_to_each_company': True, 'bundle_price_per_group': BUNDLE, 'direct_per_group': float(direct), 'contribution': float(contribution), 'contribution_pct': float(contribution / BUNDLE * 100)})
    assert bundle_rows[0]['contribution'] < 0 < bundle_rows[1]['contribution']
    result['courses_and_bundle'] = {'max_people_per_booked_group': 10, 'live_hours_per_day': 6, 'prep_hours_per_day': 1, 'trainer_loaded_hour': 55, 'internal_room_cost_per_day_assumed': 50, 'materials_exercises_per_group_per_day': 30, 'course_scenarios': course_rows, 'bundle_scenarios': bundle_rows, 'bundle_discount_vs_separate': COURSE_TWO + DAY - BUNDLE, 'operating_rule': 'Calendario a classi condivise. Il bundle 1.500 è sostenibile nelle ipotesi con almeno due gruppi nello stesso corso; la giornata di consulenza resta dedicata a ogni azienda. Non trattare il prezzo come edizione esclusiva di tre giornate.'}
    result['limits'] += ['Il bundle 1.500 euro ha contribuzione negativa in edizione esclusiva con un solo gruppo nelle ipotesi conservative. Organizzare le classi con più aziende e verificare i costi effettivi prima di offrire date dedicate.', 'La componente software interna da 19 euro e il valore economico interno dei crediti NON devono essere scorporati nei materiali del cliente. Il prezzo pubblico è il totale della taglia.']
    return result

def build():
    HERE.mkdir(exist_ok=True)
    model = economics()
    out('modello_costi_interno.json', model)
    offer = {'revision': '07 Pacchetti e avvio consigliato', 'date': '2026-11-12', 'valid_until': '2026-12-12', 'tax': 'IVA esclusa',
        'plans': PLANS, 'software': {'public_prices_are_total_monthly_per_company': True, 'show_component_breakdown': False, 'users': 'illimitati', 'storage_gb_shared_files_derivatives': 5, 'same_features_in_all_plans': True, 'billing': 'Mensile anticipato'},
        'credit_rules': {'credit_value_eur': .01, 'included_reset_each_billing_month': True, 'included_rollover': False, 'extra_prepaid_no_expiry': True, 'spend_included_before_extra': True, 'topups_eur': [25, 50, 100], 'overage_requires_topup_or_upgrade': True, 'no_unapproved_automatic_overage': True, 'upgrade_current_cycle': {'S_to_M': {'additional_price': 20, 'additional_credits': 2000}, 'M_to_L': {'additional_price': 50, 'additional_credits': 7000}, 'same_renewal_date': True}, 'downgrade_next_renewal': True},
        'courses': {'venue': 'Aule ZoWorking', 'max_people_per_booked_group': 10, 'mixed_companies_possible': True, 'prices_fixed_per_group': [{'name': 'Essenziale', 'days': 1, 'hours': 6, 'price': 600}, {'name': 'Completo', 'days': 2, 'hours': 12, 'price': 1200}], 'small_groups': 'Per meno partecipanti si valuta un preventivo dedicato', 'included': 'Aula, docente, materiali, accesso didattico al software e consumi degli esercizi durante le giornate di corso'},
        'recommended_bundle': {'price_once': 1500, 'course_days': 2, 'course_max_people': 10, 'consulting_days_included': 1, 'consulting_hours_including_preparation': 7, 'course_shared_calendar': True, 'software_subscription_separate': True, 'separate_service_total': 1900, 'saving': 400, 'highlight_in_price_tables': True},
        'consulting': {'price_per_day': 700, 'max_days_total_including_bundle_day': 3, 'half_day_price': 350, 'may_be_distributed': True},
        'recommended_start_quotes': [{'plan': p['name'], 'bundle': 1500, 'first_month_subscription': p['monthly_price'], 'first_month_credits_included': p['included_monthly_credits'], 'total_start': 1500 + p['monthly_price']} for p in PLANS],
        'supersedes': 'Sostituisce la revisione 05 del solo AskMyDocs. Rimossa la fascia corso 11-15; aggiunto corso breve e bundle 1.500. Pubblicare soltanto prezzi totali 29/49/99 senza scorporo software e credito.'}
    assert [q['total_start'] for q in offer['recommended_start_quotes']] == [1529, 1549, 1599]
    out('offerta.json', offer)
    sources = deepcopy(json.loads((R05 / 'fonti.json').read_text())['sources'])
    for s in sources:
        s['id'] = s['id'].replace('OFFERTA-R05', 'OFFERTA-R06').replace('MODELLO-R05', 'MODELLO-R06')
        if s['id'] == 'OFFERTA-R06':
            s.update({'path': 'offerta.json', 'title': 'Listino aggiornato su indicazioni di Marco', 'supports': 'Taglie 29/49/99 con crediti inclusi; corso 600/1.200 fino a dieci persone; bundle consigliato 1.500 con una consulenza; nessuno scorporo pubblico'})
        if s['id'] == 'MODELLO-R06':
            s.update({'path': 'modello_costi_interno.json', 'title': 'Stima consumi e sostenibilità per taglia e classe condivisa'})
    out('fonti.json', {'sources': sources})
    old = json.loads((R05 / 'brochure.json').read_text())
    old_pages = {p['id']: deepcopy(p) for p in old['pages']}
    for p in old_pages.values():
        p['source_ids'] = [x.replace('OFFERTA-R05', 'OFFERTA-R06').replace('MODELLO-R05', 'MODELLO-R06') for x in p['source_ids']]
    model_page = old_pages['models']
    software_page = old_pages['software']
    sources_page = old_pages['sources']
    sources_page['items'][-1] = {'title': 'Ricariche extra', 'detail': '25 € = 2.500 crediti; 50 € = 5.000; 100 € = 10.000. I crediti extra acquistati non scadono e si usano con abbonamento attivo. I crediti inclusi nella taglia si rinnovano ogni mese e non si accumulano. Preparazione indicativa di un documento standard: 0,2 crediti; OCR semplice: 1 credito per pagina.'}
    definition = 'Un documento standard è un riferimento di circa cinque pagine di testo leggibile, circa 2.000 parole. Un’email di 200 parole vale circa un decimo; un manuale di 50 pagine circa dieci documenti standard. Testo, formato, immagini e modello cambiano il consumo: le quantità sono indicative, non un limite di file.'
    monthly_note = 'Stima combinata con GPT-6 Luna: circa 0,2 crediti per preparare un documento standard e 1-2 crediti per domanda nelle ipotesi dichiarate. Documenti e domande consumano lo stesso budget, già suddiviso nella tabella. Si ipotizzano PDF con testo leggibile, senza OCR. La preparazione si paga quando serve: i documenti invariati non devono essere ricaricati ogni mese.\n\n' + definition + '\n\nScansioni e immagini possono richiedere altro credito. Esempio: 100 scansioni di cinque pagine richiedono circa 520 crediti di preparazione con OCR, cui si aggiungono le domande. Il consumo dipende dal modello, dal contesto e dai passaggi necessari; con modelli più costosi il numero di domande diminuisce.'
    prices_rows = [
        ['AskMyDocs S', '29 € al mese', '1.000 crediti AI al mese inclusi'],
        ['AskMyDocs M', '49 € al mese', '3.000 crediti AI al mese inclusi'],
        ['AskMyDocs L', '99 € al mese', '10.000 crediti AI al mese inclusi'],
        ['Corso essenziale', '600 €', 'Una giornata a ZoWorking, fino a 10 persone'],
        ['Corso completo', '1.200 €', 'Due giornate a ZoWorking, fino a 10 persone'],
        ['CONSIGLIATO\nCorso e consulenza', '1.500 €', 'Corso completo di due giornate presso ZoWorking fino a 10 persone + una giornata di consulenza'],
        ['Consulenza aggiuntiva', '700 € al giorno', 'Fino a 3 giornate totali, inclusa quella dell’eventuale percorso consigliato'],
    ]
    pages = [
        ep('cover', 'cover', 'Il software per l’azienda\nUn percorso per iniziare', 'Da 29 € al mese per azienda, con crediti AI inclusi.\nCorso e consulenza insieme a 1.500 €.', kicker='SOFTWARE / FORMAZIONE / CONSULENZA', display_name='AskMyDocs', image=art('knowledge'), band_title='ASKMYDOCS / AULE ZOWORKING', band_subtitle='OFFERTA COMMERCIALE / 12 NOVEMBRE 2026 / REV 07'),
        ep('prices', 'table', 'Il listino per l’azienda\nSoftware e affiancamento', 'Prezzi IVA esclusa. Gli abbonamenti sono mensili per azienda, con utenti illimitati. Corso e percorso di avvio hanno un prezzo per gruppo, fino a dieci partecipanti.', kicker='PREZZI E SOLUZIONE CONSIGLIATA', headers=['SERVIZIO', 'PREZZO TOTALE', 'CONTENUTO'], column_weights=[1.25, .9, 2.05], table_font=9.3, padding=7, rows=prices_rows, highlight_rows=[5], note='Tutte le taglie includono le stesse funzioni e 5 GB condivisi per file e derivati; cambia il budget di crediti. Oltre il credito disponibile si sceglie una ricarica extra o il passaggio a una taglia superiore.\n\nIl percorso consigliato comprende formazione e consulenza; l’abbonamento software si acquista separatamente. I corsi sono organizzati a calendario nelle aule ZoWorking, anche con partecipanti di altre aziende. Per gruppi più piccoli si valuta un preventivo dedicato. La formazione dedicata presso il cliente si svolge in loco; le tariffe indicate sono valide per le province di Firenze e Prato. Per altre province si prepara un preventivo specifico.'),
        ep('recommended', 'article', 'La soluzione consigliata\nCorso e consulenza insieme', '1.500 € una tantum per il gruppo: corso completo di due giornate fino a dieci persone e una giornata di consulenza dedicata all’azienda.', kicker='IL PERCORSO DI AVVIO', columns=[
            [block('Due giornate per imparare', 'Il corso si tiene nelle aule ZoWorking, a calendario e con possibili partecipanti di altre aziende. Comprende dodici ore di lezione e laboratorio, accesso didattico al software, materiali e consumi degli esercizi.'), block('Una giornata sul vostro lavoro', 'La consulenza analizza un primo flusso dell’azienda, le fonti disponibili, gli accessi e le domande utili. La giornata è dedicata alla vostra realtà e comprende preparazione e restituzione, per sette ore complessive.')],
            [block('Un prezzo unico per i due servizi', 'Acquistati separatamente, il corso completo costa 1.200 € e una giornata di consulenza 700 €, per un totale di 1.900 €. Il percorso da 1.500 € permette un risparmio di 400 €.'), block('Software scelto a parte', 'Scegliete la taglia S, M o L in base all’utilizzo previsto. Il primo mese costa rispettivamente 29, 49 o 99 €, con il relativo credito già incluso. La giornata di consulenza del percorso rientra nel massimo di tre giornate totali; ulteriori giornate costano 700 € ciascuna.')]], callout='Avvio con percorso consigliato e primo mese AskMyDocs S: 1.500 € + 29 € = 1.529 € + IVA, con 1.000 crediti mensili inclusi. Con M: 1.549 €; con L: 1.599 €.'),
        ep('courses', 'article', 'Un giorno per iniziare\nDue per approfondire', 'Formazione nelle aule ZoWorking, fino a dieci partecipanti per gruppo iscritto. Docente, aula, materiali, interfaccia AskMyDocs e consumi degli esercizi sono compresi.', kicker='DUE DURATE DI CORSO', columns=[
            [block('Essenziale a 600 €', 'Una giornata da sei ore per capire il funzionamento e i limiti dell’AI, distinguere modelli e consumi, formulare richieste e verificare le fonti. Il laboratorio introduce all’interfaccia e all’uso dei documenti.'), block('A chi serve il corso breve', 'È un’introduzione pratica per chi vuole orientarsi e iniziare con un primo esercizio. Il programma è circoscritto alla giornata: l’analisi dei flussi specifici dell’azienda si affronta nella consulenza.')],
            [block('Completo a 1.200 €', 'Due giornate da sei ore, dodici ore complessive. Alla base si aggiungono più esercitazioni su documenti, confronto dei modelli, qualità delle risposte, dati, permessi e regole d’uso. È il corso incluso nel percorso consigliato da 1.500 €.'), block('Come si partecipa', 'Organizziamo le date e possiamo riunire nella stessa classe persone di aziende diverse. Il prezzo riguarda il gruppo iscritto, fino a dieci persone, e non riserva un’aula esclusiva. Per meno partecipanti si valuta il preventivo in base al gruppo e all’edizione disponibile.')]], callout='L’accesso didattico al software è compreso per la durata del corso scelto. Per l’utilizzo aziendale ordinario si attiva la taglia S, M o L, con crediti mensili inclusi.'),
        ep('consulting', 'table', 'Capire i flussi\nScegliere dove intervenire', 'La consulenza valuta come lavora l’azienda: passaggi ricorrenti, fonti, accessi e documenti adatti alla ricerca AI detta RAG. L’impegno si concorda sul caso concreto.', kicker='CONSULENZA SU MISURA', headers=['IMPEGNO', 'PREZZO SEPARATO', 'PERIMETRO DA CONCORDARE'], column_weights=[.9, 1, 2.4], table_font=10, padding=11, rows=[['1 giornata', '700 €', 'Un primo flusso circoscritto e le relative fonti'], ['2 giornate', '1.400 €', 'Analisi più articolata e verifica del caso'], ['3 giornate', '2.100 €', 'Più flussi collegati, priorità e piano operativo']], note='Il massimo è di tre giornate complessive, contando anche quella inclusa nel percorso consigliato. Le giornate possono essere consecutive o distribuite, anche in mezze giornate da 350 €. Una giornata vale sette ore complessive, comprese preparazione e restituzione.\n\nIl risultato è una mappa dei flussi esaminati, fonti adatte e criticità dei materiali, proposta di accessi e piano di prova. Le descrizioni non garantiscono un numero fisso di processi esaminati. Integrazioni, bonifica massiva e riscrittura dei manuali richiedono un perimetro specifico. Le elaborazioni sui documenti aziendali usano il credito della taglia scelta.'),
        software_page,
        ep('subscription', 'article', 'La taglia segue l’utilizzo\nIl budget resta controllabile', 'Le funzioni e gli utenti sono gli stessi per S, M e L. La scelta dipende dal credito necessario per preparare le fonti e fare domande.', kicker='ABBONAMENTO E CONSUMI', columns=[
            [block('Credito incluso ogni mese', 'S comprende 1.000 crediti, M 3.000 e L 10.000. La quota inclusa si rinnova a ogni mese di fatturazione e non si accumula. Si utilizzano prima i crediti inclusi e poi quelli delle eventuali ricariche extra.'), block('Se serve altro credito', 'Potete acquistare una ricarica extra oppure scegliere una taglia superiore. I crediti extra acquistati non scadono e sono utilizzabili con abbonamento attivo. A saldo zero si fermano le nuove operazioni AI; i file restano consultabili durante il servizio.')],
            [block('Passare alla taglia superiore', 'Nel mese in corso si paga la differenza di prezzo e si riceve solo la differenza di crediti: da S a M, 20 € e 2.000 crediti aggiuntivi; da M a L, 50 € e 7.000 crediti. La data di rinnovo resta invariata e i crediti mensili seguono la stessa scadenza.'), block('Ordine e rinnovo', 'Canone mensile anticipato dall’attivazione dell’ambiente aziendale. Passaggi di taglia e ricariche si confermano prima dell’addebito; la riduzione di taglia vale dal rinnovo successivo. Disdetta prima della scadenza successiva. Corso, consulenza o percorso di avvio: 50% all’ordine e 50% a completamento.')]], callout='Prima delle lavorazioni si comunica una stima o un tetto di spesa. Non si supera il tetto senza autorizzazione. Date, accessi, provider e trattamento dati si concordano prima dell’uso dei documenti reali.'),
        model_page,
        ep('examples', 'table', 'Documenti e domande\nCome leggere la taglia', 'Esempi indicativi di utilizzo del credito mensile già incluso nell’abbonamento. La preparazione dei documenti e le domande condividono lo stesso saldo.', kicker='STIME DI UTILIZZO', headers=['TAGLIA', 'CREDITI INCLUSI', 'ESEMPIO COMBINATO CON GPT-6 LUNA'], column_weights=[.7, 1, 2.6], table_font=10, padding=10, rows=[[p['name'], r05.it(p['included_monthly_credits']), f"Circa {r05.it(p['sample_documents'])} documenti standard\n+ {r05.it(p['sample_questions'][0])}-{r05.it(p['sample_questions'][1])} domande"] for p in PLANS], note=monthly_note),
        sources_page,
        ep('closing', 'closing', 'Imparare insieme\nApplicarlo al lavoro', 'Un corso a ZoWorking per acquisire il metodo, una consulenza sul lavoro reale e una taglia software proporzionata all’utilizzo.\naskmydocs.surfacesrl.com', kicker='IL PERCORSO PER L’AZIENDA', image=art('community', 260), quote='Percorso consigliato: due giornate di corso fino a dieci persone e una giornata di consulenza a 1.500 € + IVA. Abbonamento AskMyDocs a scelta tra S, M e L.'),
    ]
    document = deepcopy(old['document'])
    document.update({'title': 'AskMyDocs pacchetti con crediti e percorso consigliato', 'subject': 'Taglie S M L e formazione a ZoWorking con consulenza inclusa nel percorso di avvio', 'edition': '12 NOVEMBRE 2026 / REV 07', 'source_ids': ['OFFERTA-R06', 'MODELLO-R06', 'AMD-BRAND']})
    out('brochure.json', {'document': document, 'theme': deepcopy(old['theme']), 'pages': pages})
    word_pages = [
        page('listino', 'AskMyDocs pacchetti e percorso di avvio', [
            para('Tre abbonamenti mensili per azienda con utenti illimitati e crediti AI inclusi. Le funzioni e i 5 GB condivisi per file e derivati sono uguali: cambia il budget di utilizzo. Corso e consulenza si quotano separatamente dal software. Importi IVA esclusa.'),
            table(['Taglia', 'Prezzo totale mensile', 'Crediti AI inclusi al mese'], [[p['name'], r05.amount(p['monthly_price']), r05.it(p['included_monthly_credits'])] for p in PLANS], [1, 2, 2]),
            h('Formazione nelle aule ZoWorking'),
            table(['Servizio fino a 10 persone', 'Prezzo', 'Contenuto'], [['Corso essenziale', '600 €', 'Una giornata da sei ore'], ['Corso completo', '1.200 €', 'Due giornate da sei ore ciascuna'], ['CONSIGLIATO\nCorso e consulenza', '1.500 €', 'Corso completo di due giornate presso ZoWorking + una giornata di consulenza aziendale']], [1.9, .8, 2.5]),
            para('Il percorso consigliato unisce i due servizi che, acquistati separatamente, costano 1.900 €: 1.200 € di corso e 700 € di consulenza. Il risparmio è di 400 €. La quota del percorso è una tantum per il gruppo, fino a dieci partecipanti; l’abbonamento software resta separato.'),
            para('Sono compresi aula, docente, materiali, accesso didattico al software e consumi degli esercizi per la durata del corso. Organizziamo le date con possibili partecipanti di altre aziende nella stessa classe. La quota non riserva un’aula esclusiva. Per gruppi piccoli si valuta un preventivo dedicato. La formazione dedicata presso il cliente si svolge in loco; le tariffe indicate sono valide per le province di Firenze e Prato. Per altre province si prepara un preventivo specifico.'),
            h('Consulenza sui flussi aziendali'),
            table(['Giornate totali', '1', '2', '3'], [['Prezzo separato', '700 €', '1.400 €', '2.100 €']], [1.5, 1.2, 1.2, 1.2]),
            para('Nel massimo di tre giornate si conta anche la giornata inclusa nel percorso consigliato. Obiettivo e impegno dipendono dalla complessità del lavoro. Le giornate possono essere consecutive o distribuite.'),
        ]),
        page('percorso', 'Il corso e la consulenza sul lavoro reale', [
            h('Corso essenziale di una giornata'),
            para('Una giornata da sei ore, 600 € per gruppo fino a dieci persone. Introduzione al funzionamento e ai limiti dell’AI, scelta del modello, token e crediti, richieste, verifica delle fonti e primo laboratorio nell’interfaccia AskMyDocs. Il programma è circoscritto alla giornata.'),
            h('Corso completo di due giornate'),
            para('Due giornate da sei ore, dodici ore complessive, 1.200 € per gruppo fino a dieci persone. Alla base si aggiungono esercizi su documenti, confronto dei modelli e dei consumi, qualità delle risposte, dati, permessi e regole d’uso. È il corso compreso nel percorso consigliato da 1.500 €.'),
            h('Una giornata dedicata all’azienda'),
            para('La consulenza del percorso consigliato analizza un primo flusso, le fonti disponibili, gli accessi e le domande utili. La giornata è dedicata alla realtà aziendale e vale sette ore complessive, comprese preparazione e restituzione. Si possono distribuire le attività anche in mezze giornate.'),
            para('Consegne: mappa dei flussi esaminati, fonti adatte e criticità dei materiali, proposta di accessi e piano di prova. Ulteriori giornate costano 700 € ciascuna, fino a tre giornate totali inclusa quella del percorso; mezze giornate da 350 €. Integrazioni, bonifica massiva e riscrittura dei manuali richiedono un perimetro specifico. I consumi sui documenti aziendali usano il credito della taglia scelta.'),
            h('Preventivi di avvio con il percorso consigliato'),
            table(['Voce', 'Con S', 'Con M', 'Con L'], [['Corso completo + 1 consulenza', '1.500 €', '1.500 €', '1.500 €'], ['Primo mese software', '29 €', '49 €', '99 €'], ['Crediti del primo mese', '1.000', '3.000', '10.000'], ['Totale avvio IVA esclusa', '1.529 €', '1.549 €', '1.599 €']], [2, 1, 1, 1]),
            para('Dai mesi successivi si paga l’abbonamento scelto; corso e consulenza non si riacquistano automaticamente. Date del corso e calendario della consulenza vengono concordati all’ordine. Il percorso si svolge con lezioni a calendario nelle aule ZoWorking, anche con persone di altre aziende; la consulenza resta dedicata alla singola azienda.'),
        ]),
        page('condizioni', 'Credito incluso rinnovi e cambio taglia', [
            para('AskMyDocs prepara i documenti per la ricerca e permette di fare domande con riferimenti alle fonti. Gli utenti autorizzati della stessa azienda condividono l’ambiente e il saldo. Ogni persona lavora con i propri accessi; il referente controlla fonti e risposte.'),
            h('Il credito mensile e le ricariche'),
            para('S comprende 1.000 crediti al mese, M 3.000 e L 10.000. I crediti inclusi si rinnovano a ogni mese di fatturazione e non si accumulano. Si consumano prima quelli inclusi e poi i crediti extra eventualmente acquistati. Le ricariche extra non scadono e sono utilizzabili con abbonamento attivo.'),
            para('Se il budget non basta, si può acquistare una ricarica o passare a una taglia superiore. A saldo zero si fermano nuove operazioni AI; i file restano leggibili durante il servizio. Nessun addebito oltre il credito disponibile senza conferma. Prima della lavorazione si comunica una stima o un tetto di spesa.'),
            h('Passare alla taglia superiore'),
            table(['Passaggio nel mese in corso', 'Differenza di prezzo', 'Crediti aggiuntivi'], [['Da S a M', '20 €', '2.000'], ['Da M a L', '50 €', '7.000'], ['Da S a L', '70 €', '9.000']], [2.3, 1.4, 1.4]),
            para('Si paga la differenza intera e si riceve solo il credito aggiuntivo: non si accredita una seconda volta tutta la taglia. La data di rinnovo non cambia; il credito incluso mantiene la scadenza del periodo. Il passaggio alla taglia inferiore vale dal rinnovo successivo. Modifiche e importi vengono mostrati prima della conferma.'),
            h('Condizioni del servizio'),
            para('Abbonamento mensile anticipato dall’attivazione dell’ambiente aziendale; disdetta prima della scadenza successiva. Accesso didattico al software compreso nel corso scelto. Credito per preparazione, OCR, domande e rielaborazioni; caricamento senza elaborazione, lettura manuale ed esportazione non consumano credito AI.'),
            para('Assistenza tecnica nei feriali tramite referente. File e derivati entro 5 GB; spazio aggiuntivo o infrastrutture dedicate a preventivo. Le elaborazioni possono restare in coda, con riferimento tecnico di due operazioni AI contemporanee per azienda. Provider, modelli, accessi e trattamento dati concordati prima del caricamento reale; OCR remoto da attivare specificamente. Esportazione concordata entro 30 giorni dalla fine del servizio.'),
            para('Corso, consulenza o percorso consigliato: 50% all’ordine e 50% a completamento. Validità fino al 12 dicembre 2026. Tutti gli importi IVA esclusa.'),
            para('Cliente ____________________  Taglia ____________________\nCorso o percorso ____________________  Partecipanti ____________\nData corso ____________________  Consulenza ____________________'),
        ]),
        page('dettagli', 'Modelli e stime del credito incluso', [
            para('Esempi di modelli recenti. Un credito vale 0,01 € di utilizzo e può essere consumato in frazioni. Le fasce seguenti sono stime per una richiesta ordinaria o estesa, non un prezzo fisso di qualsiasi domanda.'),
            table(['Modello', 'Crediti indicativi', 'Valore di utilizzo'], [[m['name'], r05.credit_range(m), r05.price_range(m)] for m in r05.MODELS], [2.5, 1.3, 1.3]),
            para('Ipotesi: 10.000-30.000 token letti e 1.000-3.000 prodotti, ragionamento fatturabile incluso, sommati su tutti i passaggi del modello scelto, più preparazione della domanda e ricerca. Più testo o altri passaggi aumentano il consumo. I prezzi Gemini rilevati includono una promozione variabile. Modelli da verificare e configurare all’attivazione.'),
            table(['Taglia', 'Esempio combinato con GPT-6 Luna'], [[p['name'], f"Circa {r05.it(p['sample_documents'])} documenti standard + {r05.it(p['sample_questions'][0])}-{r05.it(p['sample_questions'][1])} domande"] for p in PLANS], [.7, 4.4]),
            para(definition + ' Gli esempi usano lo stesso credito mensile per entrambe le attività: circa 0,2 crediti per preparazione e 1-2 per domanda con GPT-6 Luna. PDF leggibili senza OCR, entro lo spazio disponibile. I documenti invariati non richiedono nuova preparazione ogni mese. OCR semplice: circa 1 credito per pagina; immagini e layout complessi possono costare di più.'),
            para('Tariffe API verificate il 12 novembre 2026. Stime con fattore di servizio 2,5 sul costo AI completo e ipotesi prudenziale 1 USD = 1 EUR, non cambio corrente. Commissioni pertinenti incluse una volta; nessuno sconto cache assunto, eventuali scritture cache conteggiate. Sono calcoli di preventivo, non medie di produzione.'),
            para('Fonti: openrouter.ai/openai/gpt-6-luna ; openrouter.ai/openai/gpt-6.1-sol ; openrouter.ai/google/gemini-3.8-flash ; openrouter.ai/google/gemini-4-carbon ; openrouter.ai/deepseek/deepseek-v4.1-flash-1017 ; openrouter.ai/pricing ; docs.mistral.ai/models/ocr-4-1'),
            para('Ricariche extra: 25 € = 2.500 crediti; 50 € = 5.000; 100 € = 10.000. I crediti extra acquistati non scadono e si usano con abbonamento attivo. I crediti inclusi nel piano si rinnovano ogni mese e non si accumulano.'),
        ]),
    ]
    path = HERE / 'AskMyDocs_listino_pacchetti.docx'
    r05.helpers.make_doc(word_pages, path, 'AskMyDocs pacchetti e avvio revisione 07')
    word = r05.helpers.Document(path)
    word.core_properties.subject = 'Taglie 29 49 99 con crediti inclusi e percorso consigliato 1500'
    word.core_properties.keywords = 'AskMyDocs, ZoWorking, crediti inclusi, corso, consulenza'
    from docx.shared import Pt, RGBColor
    from docx.oxml import OxmlElement
    from docx.oxml.ns import qn
    highlighted = 0
    for t in word.tables:
        for row in t.rows:
            if 'CONSIGLIATO' not in row.cells[0].text:
                continue
            highlighted += 1
            for cell in row.cells:
                shd = OxmlElement('w:shd')
                shd.set(qn('w:fill'), '416147')
                cell._tc.get_or_add_tcPr().append(shd)
                for p in cell.paragraphs:
                    for run in p.runs:
                        run.bold = True
                        run.font.color.rgb = RGBColor.from_string('FFFFFF')
    assert highlighted == 1
    for p in word.paragraphs:
        if p.text.startswith(('Ricariche extra:', 'Fonti:')):
            for run in p.runs:
                run.font.size = Pt(9)
    word.save(path)
    note = ['ASKMYDOCS - NOTA INTERNA REVISIONE 06', '12 novembre 2026', '',
        'PREZZI PUBBLICI', 'Mostrare soltanto S 29 euro, M 49, L 99 al mese per azienda, con 1.000/3.000/10.000 crediti mensili inclusi. Marco ha chiesto di NON mostrare la scorporazione tra software e credito. Il calcolo interno resta 19 euro per la componente software e 10/30/100 di valore credito incluso: queste cifre non entrano nei materiali del cliente.',
        'Utenti, funzioni e quota 5 GB uguali; cambia il consumo incluso. I crediti mensili non si accumulano. Gli extra acquistati non scadono; spendere prima la quota mensile. Nessun addebito oltre saldo senza ricarica o upgrade confermato.',
        'Upgrade nel periodo: differenza intera di prezzo e solo differenza di crediti, mantenendo il rinnovo. S-M +20 e +2.000; M-L +50 e +7.000; S-L +70 e +9.000. Downgrade dal rinnovo successivo. Regole da implementare, non già attivate.',
        '', 'MARGINI DELLE TAGLIE A CONSUMO PIENO']
    for p in model['plans']:
        note.append(f"{p['name']}: ricavo mensile {p['monthly_price']}; costo tecnico di tutti i crediti inclusi {p['technical_cost_if_all_included_credits_consumed']:.2f} euro; contribuzione prima dei costi condivisi {p['monthly_contribution_before_shared_fixed_costs']:.2f} euro ({p['monthly_contribution_pct']:.1f}%), dopo ipotesi provvigione 10%, incasso 2,5%, riserva costi 10% e budget diretto azienda. Non assumere crediti inutilizzati come margine acquisito.")
    note += ['', 'FORMAZIONE E PERCORSO CONSIGLIATO', 'Corso breve 600 euro per una giornata; completo 1.200 per due. Entrambi fino a 10 persone nel gruppo iscritto, a ZoWorking e con classi anche miste. Il vecchio corso 11-15 da 1.500 non è più proposto.',
        'La nuova riga consigliata da 1.500 comprende il corso completo di due giornate e UNA giornata di consulenza aziendale dedicata. Risparmio rispetto a 1.200+700: 400 euro. Software mensile a parte. L’eventuale consulenza ulteriore resta nel massimo di tre giornate totali.',
        'Ipotesi operative: sei ore live e una di preparazione per giornata di corso, docente 55 euro/ora, aula interna 50 euro/giorno; materiali ed esercizi per gruppo 30 euro/giorno. Consulenza sette ore complessive, costo diretto con riserva 423,50 euro per giornata. Aula e docente comuni si contano una sola volta per edizione.']
    for b in model['courses_and_bundle']['bundle_scenarios']:
        note.append(f"Bundle, {b['groups_same_course_edition']} gruppo/i nella stessa edizione: costo diretto per gruppo {b['direct_per_group']:.2f}; contribuzione {b['contribution']:.2f} euro ({b['contribution_pct']:.1f}%) dopo provvigione/incasso ipotetici. La consulenza resta dedicata a ogni azienda.")
    note += [model['courses_and_bundle']['operating_rule'],
        'Non promettere aula esclusiva o tre giornate dedicate a 1.500 con queste ipotesi: il caso con un solo gruppo produce perdita di contribuzione di 134 euro. Con due gruppi nello stesso corso il contributo stimato per bundle è 344,50 euro. Pianificare iscrizioni, date e capienza prima di garantire l’edizione; valutare i piccoli gruppi senza sconti automatici.',
        '', 'PROVENIENZA E LIMITI', 'Costi AI, ipotesi token e modelli recenti dalla revisione 05 verificata il 12 novembre. Tariffe primarie ricontrollate. Preparazione e domande non sono equivalenze universali. Le medie di produzione e il ledger completo per richiesta non sono stati misurati in questo lavoro.',
        'Cloud reale osservato nell’audit precedente: main prudenziale 48,66 USD nel periodo e ambienti identificati 73,08 USD. Non è un costo per singola azienda. Budget comune ipotizzato 1.200 euro/anno, oltre ai costi incrementali. Staff, aula e riserve sono ipotesi, non fatture.',
        *model['limits'],
        'Nessun software, prezzo live, fatturazione o dato di produzione è stato cambiato. Configurare e collaudare allowance mensile, wallet extra, rinnovo, scadenze, upgrade idempotenti e costo completo prima dell’attivazione.',
        '', 'PREVENTIVI DI AVVIO', 'Percorso 1.500 + primo mese S 29 = 1.529; con M 49 = 1.549; con L 99 = 1.599 euro IVA esclusa. Il primo mese comprende il credito della taglia. Dai mesi successivi solo la taglia e gli eventuali extra acquistati.']
    (HERE / 'Nota_costi_interna.txt').write_text('\n'.join(note) + '\n')
    (HERE / 'Per_Lorenzo.txt').write_text('''ASKMYDOCS - REVISIONE 06 PACCHETTI E AVVIO
12 novembre 2026 - importi IVA esclusa

Software mensile per azienda: S 29 euro con 1.000 crediti AI inclusi; M 49 euro con 3.000; L 99 euro con 10.000. Utenti illimitati e stesse funzioni, 5 GB condivisi. I prezzi sono i totali mensili da proporre al cliente.

SOLUZIONE CONSIGLIATA: 1.500 euro una tantum, con corso completo di due giornate a ZoWorking fino a dieci persone e una giornata di consulenza dedicata all’azienda inclusa. Il risparmio rispetto ai due servizi separati è 400 euro. L’abbonamento software è a parte.

Corsi acquistabili separatamente: una giornata 600 euro; due giornate 1.200. Sempre fino a dieci persone nel gruppo, con accesso didattico al software e consumi degli esercizi compresi. Classi a calendario anche con altre aziende, senza aula esclusiva. Per piccoli gruppi si valuta il preventivo. La formazione dedicata presso il cliente si svolge in loco; le tariffe indicate sono valide per le province di Firenze e Prato. Per altre province si prepara un preventivo specifico.

Consulenza: 700 euro per giornata, massimo tre totali contando anche quella inclusa nel percorso consigliato. Le giornate possono essere distribuite. Analisi di flussi, fonti, permessi e casi adatti al RAG.

Avvio consigliato: 1.529 euro con il primo mese S, 1.549 con M o 1.599 con L, con crediti inclusi secondo taglia. Dai mesi successivi si rinnova il solo software.

I crediti inclusi si rinnovano ogni mese e non si accumulano; gli extra acquistati non scadono e si usano con abbonamento attivo. Se il budget non basta, si sceglie una ricarica extra oppure un upgrade: nessun addebito non confermato. Dettagli ed esempi di consumo sono nelle ultime pagine.

Questa proposta sostituisce la revisione 05 del solo AskMyDocs. Validità fino al 12 dicembre 2026; nuovi listino, crediti e cambi taglia da configurare e collaudare prima dell’attivazione. Gli altri prodotti restano invariati.
''')
    print(json.dumps({'pdf_pages': len(pages), 'word_sections': len(word_pages), 'output': str(HERE)}, ensure_ascii=False))

if __name__ == '__main__':
    build()
