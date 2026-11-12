"""Revisione 05: canone aziendale confermato, corso ZoWorking e dettagli consumi in fondo."""
from pathlib import Path
from decimal import Decimal as D, ROUND_CEILING
from copy import deepcopy
import importlib.util
import json
import math

ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent
PREV = ROOT / 'marketing/offerte-con-formazione-2026-11-12'
spec = importlib.util.spec_from_file_location('word_helpers', ROOT / 'marketing/offerte-portafoglio-2026-11-12/sorgenti/genera_documenti.py')
helpers = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helpers)
para, h, table, page = helpers.para, helpers.h, helpers.table, helpers.page
MODELS = [
    {'name': 'GPT-6 Luna', 'provider': 'OpenAI', 'slug': 'openai/gpt-6-luna', 'input': '.10', 'output': '.50', 'release': '2026-09-22', 'budget': [1, 2]},
    {'name': 'GPT-6.1 Sol', 'provider': 'OpenAI', 'slug': 'openai/gpt-6.1-sol', 'input': '2', 'output': '10', 'release': '2026-09-29', 'budget': [8, 24]},
    {'name': 'Gemini 3.8 Flash', 'provider': 'Google', 'slug': 'google/gemini-3.8-flash', 'input': '.75', 'output': '3.75', 'release': '2026-09-02', 'budget': [4, 10], 'promotion': '50% rilevato il 12 novembre 2026'},
    {'name': 'Gemini 4 Carbon', 'provider': 'Google', 'slug': 'google/gemini-4-carbon', 'input': '2', 'output': '10', 'release': '2026-11-10', 'budget': [8, 24], 'promotion': '50% rilevato il 12 novembre 2026'},
    {'name': 'DeepSeek V4.1 Flash 1017', 'provider': 'DeepSeek', 'slug': 'deepseek/deepseek-v4.1-flash-1017', 'input': '.022', 'output': '.40', 'release': '2026-10-17', 'budget': [.3, .7]},
]
FEE = 19
COURSE = [1200, 1500]
DAY = 700

def it(n, digits=0):
    return f'{n:,.{digits}f}'.replace(',', '_').replace('.', ',').replace('_', '.')

def amount(n):
    return it(n, 0 if D(str(n)) == D(str(n)).to_integral_value() else 2) + ' €'

def credit_range(m):
    return '-'.join(it(x, 1 if D(str(x)) % 1 else 0) for x in m['budget'])

def price_range(m):
    return '-'.join(it(D(str(x)) / 100, 3 if D(str(x)) < 1 else 2) for x in m['budget']) + ' €'

def out(name, data):
    (HERE / name).write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n')

def inference(ni, no, m):
    return (D(ni) * D(m['input']) + D(no) * D(m['output'])) / 1_000_000

def credits(cost):
    return (cost * D('2.5') / D('.01')).quantize(D('.01'), rounding=ROUND_CEILING)

def economics():
    model_rows = []
    for m in MODELS:
        values = []
        for factor in [1, 3]:
            aux_factor = 1 if factor == 1 else 2
            cost = (inference(10000 * factor, 1000 * factor, m) + inference(1000 * aux_factor, 200 * aux_factor, MODELS[0]) + D(200) * D('.02') / 1_000_000) * D('1.055')
            values.append(float(credits(cost)))
        assert all(D(str(actual)) <= D(str(budget)) for actual, budget in zip(values, m['budget']))
        model_rows.append({**m, 'calculated_credits_simple_extended': values})
    doc = credits((D(3300) * D('.02') / 1_000_000 + inference(3000, 300, MODELS[0])) * D('1.055'))
    assert doc <= D('.2')
    # Costi condivisi e ore ipotetiche, distinti dai consuntivi Cloud già osservati.
    recurring = D(6 + 45) * D('1.10')
    activation = D('.5') * 55 * D('1.1')
    annual_net = D(FEE * 12) * D('.875')
    course_rows = []
    for i, price in enumerate(COURSE):
        direct = D(14 * 55 + [100, 150][i] + [40, 60][i] + [20, 30][i]) * D('1.10')
        contribution = D(price) * D('.875') - direct
        assert contribution > 0
        course_rows.append({'band': ['1-10', '11-15'][i], 'price': price, 'direct_cost_assumption': float(direct), 'contribution_after_fees_and_reserve': float(contribution), 'contribution_pct': float(contribution / price * 100)})
    return {
        'date': '2026-11-12', 'currency': 'EUR', 'status': 'Stima aritmetica, non benchmark di produzione',
        'model_prices_source': 'OpenRouter, tariffe del 12 novembre 2026', 'models': model_rows,
        'question_assumptions': {'simple_input_total': 10000, 'simple_output_total_including_reasoning': 1000, 'extended_input_total': 30000, 'extended_output_total_including_reasoning': 3000, 'auxiliary_model': 'GPT-6 Luna', 'auxiliary_simple_tokens': [1000, 200], 'auxiliary_extended_tokens': [2000, 400], 'query_embedding_tokens': 200, 'scope': 'Token sommati su tutte le chiamate della richiesta, non per singola chiamata'},
        'planning_exchange_usd_to_eur': 1, 'exchange_is_assumption_not_current_rate': True,
        'openrouter_purchase_fee_pct': 5.5, 'complete_technical_cost_multiplier': 2.5, 'credit_value_eur': .01, 'credit_precision': .01,
        'cache_assumption': 'Tariffa input ordinaria senza letture cache; per OpenAI disabilitare cache automatica nelle stime con modalità explicit senza breakpoint, altrimenti valorizzare anche le scritture cache. Nessuno sconto cache conteggiato.',
        'reasoning_note': 'I token di ragionamento fatturabili rientrano nei totali output; oltre ipotesi e in modalità Pro il consumo può aumentare molto.',
        'document_preparation': {'standard_pages': 5, 'standard_words': 2000, 'text_tokens_assumed': 3000, 'embedding_tokens_including_overlap': 3300, 'embedding_usd_million': .02, 'short_economic_enrichment_input_output': [3000, 300], 'calculated_credits': float(doc), 'rounded_budget_credits': .2},
        'ocr': {'model': 'mistral-ocr-4-1', 'usd_per_1000_pages': 4, 'direct_service_no_openrouter_fee': True, 'planning_credits_per_page': 1, 'standard_scan_5_pages_preparation_credits': 5.2, 'annotations_excluded': True},
        'software_fixed': {'confirmed_monthly_fee': FEE, 'annual_equivalent_not_prepaid': FEE * 12, 'shared_fixed_annual_budget': 1200, 'incremental_storage_annual_per_company': 6, 'technical_support_hours_annual_per_company': 1, 'support_loaded_hour': 45, 'cost_reserve_pct': 10, 'standard_activation_hours': .5, 'technical_loaded_hour': 55, 'annual_direct_per_company': float(recurring), 'one_time_standard_activation': float(activation), 'annual_contribution_before_shared_fixed': float(annual_net - recurring), 'first_year_contribution_before_shared_fixed': float(annual_net - recurring - activation), 'companies_with_full_year_revenue_to_cover_shared_fixed_renewal': math.ceil(D(1200) / (annual_net - recurring)), 'companies_with_full_year_revenue_to_cover_shared_fixed_first_year': math.ceil(D(1200) / (annual_net - recurring - activation)), 'note': 'Spalmatura del budget comune, non costo di hosting dedicato per ogni azienda. Il pareggio usa dodici mesi incassati: non promette copertura con clienti che disdicono prima.'},
        'course': {'live_days': 2, 'live_hours_per_day_assumed': 6, 'preparation_hours': 2, 'trainer_loaded_hour': 55, 'internal_room_allocation': [100, 150], 'materials': [40, 60], 'exercise_ai_budget': [20, 30], 'results': course_rows, 'limit': 'Il gruppo 1-10 a 1.200 euro ha margine ridotto in edizione dedicata con queste ipotesi; raggruppare aziende e verificare i costi aula effettivi prima di scontare. Non moltiplicare i costi comuni per ogni gruppo della medesima edizione.'},
        'consulting': {'day_price': DAY, 'hours_total_including_preparation': 7, 'max_days': 3, 'loaded_hour': 55, 'direct_with_reserve': 423.5, 'contribution_per_day': float(D(DAY) * D('.875') - D('423.5'))},
        'assumed_sales_commission_pct': 10, 'assumed_collection_pct': 2.5, 'consumed_ai_credit_min_contribution_pct': 43.5,
        'real_cloud_evidence': '../offerte-portafoglio-2026-11-12/revisione02/audit_costi_verificati.json',
        'limits': ['Tariffe e disponibilità possono cambiare; Gemini usa prezzi promozionali rilevati, non sconti permanenti garantiti.', 'Nessuna inferenza a pagamento o benchmark reale eseguito. Prezzi unitari verificati e volumi token ipotizzati.', 'Tutte le chiamate, OCR, ragionamento, scritture cache e tentativi devono entrare nel costo completo effettivo; non usare ledger locali a zero come prova di gratuità.', 'Nuovi modelli, canone, credito frazionario e budget condiviso da configurare e collaudare prima della vendita operativa.', 'Contribuzione simulata, non utile netto; sviluppo evolutivo, struttura e imposte esclusi.'],
    }

def block(heading, text):
    return {'heading': heading, 'text': text}

def art(name, height=145):
    return {'path': f'public/editorial/{name}-day.webp', 'height': height, 'fit': 'cover', 'focus': [.5, .5], 'caption': 'Illustrazione editoriale del prodotto'}

def ep(id, layout, title, deck='', **kw):
    return {'id': id, 'layout': layout, 'title': title, 'deck': deck, 'section': title.replace('\n', ' '), 'source_ids': kw.pop('source_ids', ['OFFERTA-R05', 'AMD-FACTS', 'AMD-BRAND']), 'feature_ids': kw.pop('feature_ids', []), **kw}

def build():
    HERE.mkdir(exist_ok=True)
    model = economics()
    out('modello_costi_interno.json', model)
    offer = {'revision': '05 ZoWorking', 'date': '2026-11-12', 'valid_until': '2026-12-12', 'tax': 'IVA esclusa',
        'software': {'monthly_per_company': 19, 'annual_equivalent': 228, 'billed_monthly': True, 'users': 'illimitati', 'storage_gb_shared_files_and_derivatives': 5, 'included_ai_credit': 0, 'activation': 'Canone dall’attivazione dell’ambiente aziendale per uso ordinario; accesso didattico durante il corso incluso nel corso'},
        'course': {'venue': 'Aule ZoWorking', 'days': 2, 'hours_per_day_assumed': 6, 'bands': [{'people_from': 1, 'people_to': 10, 'group_price': 1200}, {'people_from': 11, 'people_to': 15, 'group_price': 1500}], 'small_groups': 'Per meno partecipanti si valuta un preventivo dedicato', 'mixed_companies_possible': True, 'booking_price_not_exclusive_classroom': True, 'included': 'Docente, aula, materiali, accesso didattico all’interfaccia AskMyDocs e consumi degli esercizi nelle due giornate'},
        'consulting': {'price_per_day': 700, 'days_range': [1, 3], 'hours_per_day_including_preparation': 7, 'half_day_price': 350, 'may_be_distributed': True},
        'credits': {'value_eur': .01, 'topups_eur': [25, 50, 100], 'expires': False, 'usable_with_active_software_subscription': True, 'consumption_shared_by_company': True},
        'standard_document': 'Circa cinque pagine di testo leggibile, circa 2.000 parole; un riferimento indicativo, non qualsiasi file',
        'quotes_excluding_ai_consumption': [{'label': 'Avvio fino a 10 persone e 1 giornata di consulenza', 'course': 1200, 'consulting': 700, 'software_first_month': 19, 'total': 1919}, {'label': 'Reparto fino a 10 persone e 2 giornate di consulenza', 'course': 1200, 'consulting': 1400, 'software_first_month': 19, 'total': 2619}, {'label': 'Percorso fino a 15 persone e 3 giornate di consulenza', 'course': 1500, 'consulting': 2100, 'software_first_month': 19, 'total': 3619}],
        'supersedes': 'Sostituisce la revisione 04 del solo AskMyDocs. Conferma utente: canone 19 euro al mese per azienda, consumi esclusi. Corso soltanto nelle aule ZoWorking, due fasce e due giornate. Consulenza 700 euro al giorno, massimo tre giorni.',
    }
    for q in offer['quotes_excluding_ai_consumption']:
        assert q['total'] == q['course'] + q['consulting'] + q['software_first_month']
    out('offerta.json', offer)
    sources = [{'id': 'OFFERTA-R05', 'path': 'offerta.json', 'title': 'Listino concordato nella conversazione', 'kind': 'proposal', 'publisher': 'Surface', 'checked_at': '2026-11-12', 'supports': 'Canone 19 euro confermato; corso 1.200/1.500 euro a ZoWorking; consulenza 700 euro per massimo tre giorni', 'limits': 'Prezzi proposti, non già attivati nel software'}, {'id': 'MODELLO-R05', 'path': 'modello_costi_interno.json', 'title': 'Stima consumi e sostenibilità', 'kind': 'calculation', 'publisher': 'Surface', 'checked_at': '2026-11-12', 'supports': 'Ipotesi token e calcolo di crediti, costi condivisi e servizi'}]
    for i, m in enumerate(MODELS, 1):
        sources.append({'id': f'OR-M{i}', 'url': 'https://openrouter.ai/' + m['slug'], 'title': m['name'], 'publisher': 'OpenRouter', 'kind': 'pricing', 'checked_at': '2026-11-12', 'supports': f"{m['input']} USD input, {m['output']} USD output per milione di token", 'released': m['release'], 'limits': m.get('promotion', 'Tariffe al momento della verifica; provider e instradamento possono cambiarle')})
    sources += [
        {'id': 'OR-FEES', 'url': 'https://openrouter.ai/pricing', 'title': 'Commissioni OpenRouter', 'publisher': 'OpenRouter', 'kind': 'pricing', 'checked_at': '2026-11-12', 'supports': '5,5% su acquisto crediti Standard, inclusa una sola volta nel modello'},
        {'id': 'OR-EMBED', 'url': 'https://openrouter.ai/openai/text-embedding-3-small', 'title': 'Preparazione per la ricerca', 'publisher': 'OpenRouter', 'kind': 'pricing', 'checked_at': '2026-11-12', 'supports': '0,02 USD per milione di token'},
        {'id': 'OCR', 'url': 'https://docs.mistral.ai/models/ocr-4-1', 'title': 'OCR 4.1', 'publisher': 'Mistral', 'kind': 'pricing', 'checked_at': '2026-11-12', 'supports': '4 USD per mille pagine senza annotazioni'},
        {'id': 'AMD-FACTS', 'path': 'marketing/analisi/presentazione-prodotto-2026-10-29/inventario-candidato.json', 'title': 'Catalogo funzionale verificato', 'kind': 'product', 'checked_at': '2026-11-12', 'supports': 'F-001 fonti, F-005 ingestione, F-006 OCR, F-034 organizzazioni, F-036 permessi', 'limits': 'Nuovi modelli e stato tenant non collaudati in questa revisione'},
        {'id': 'AMD-BRAND', 'path': 'marketing/offerte-con-formazione-2026-11-12/brand.json', 'title': 'Identità da landing', 'kind': 'media', 'checked_at': '2026-11-12', 'supports': 'Font, logo, palette e immagini editoriali già verificati'},
    ]
    out('fonti.json', {'sources': sources})
    definition = 'Un documento standard è un riferimento di circa cinque pagine di testo leggibile, circa 2.000 parole. Un’email di 200 parole vale circa un decimo; un manuale di 50 pagine circa dieci documenti standard. Contano testo, formato, immagini e modello: le quantità sono indicative. Una raccolta è un gruppo di documenti sullo stesso argomento, non un’unità di prezzo.'
    model_note = 'Stime di budget su circa 10.000-30.000 token letti e 1.000-3.000 token prodotti, sommati su tutti i passaggi del modello scelto; uscita comprensiva del ragionamento fatturabile. Sono aggiunte preparazione della domanda e ricerca. Richieste che superano queste ipotesi possono costare di più. Tariffe via API rilevate il 12 novembre 2026; per Gemini includono la promozione in corso, soggetta a variazione. Modelli da verificare e configurare all’attivazione.'
    pages = [
        ep('cover', 'cover', 'Il software per l’azienda\nLe competenze per usarlo', 'AskMyDocs a 19 € al mese per azienda, consumi esclusi.\nCorso in aula e consulenza con prezzi separati.', kicker='SOFTWARE / FORMAZIONE / CONSULENZA', display_name='AskMyDocs', image=art('knowledge'), band_title='ASKMYDOCS / AULE ZOWORKING', band_subtitle='OFFERTA COMMERCIALE / 12 NOVEMBRE 2026 / REV 05'),
        ep('prices', 'table', 'I prezzi\nPrima dei dettagli', 'Tre voci semplici per iniziare: accesso aziendale al software, corso in aula e consulenza sul lavoro da migliorare. Tutti gli importi sono IVA esclusa.', kicker='IL LISTINO', headers=['SERVIZIO', 'PREZZO', 'CHE COSA COMPRENDE'], column_weights=[1.2, 1, 2], table_font=10, padding=11, rows=[
            ['AskMyDocs', '19 € al mese\nper azienda', 'Utenti illimitati, ambiente condiviso e 5 GB. Consumi AI separati.'],
            ['Corso a ZoWorking\nFino a 10 persone', '1.200 €\nper gruppo', 'Due giornate in aula, accesso didattico al software ed esercitazioni.'],
            ['Corso a ZoWorking\nDa 11 a 15 persone', '1.500 €\nper gruppo', 'Lo stesso percorso di due giornate per un gruppo più numeroso.'],
            ['Consulenza', '700 € al giorno\nMassimo 3 giornate', 'Analisi di flussi, fonti e casi adatti alla ricerca documentale AI.']], note='Le quote del corso si riferiscono al gruppo iscritto, con possibili partecipanti di altre aziende nella stessa classe. Per gruppi più piccoli si valuta un preventivo dedicato. Date e organizzazione vengono comunicate all’iscrizione.\n\nIl canone è mensile: 228 € è il solo equivalente di dodici mesi, senza obbligo di pagamento annuale anticipato. Le ricariche e gli esempi di consumo sono riportati nelle ultime pagine.'),
        ep('course', 'article', 'Due giornate in aula\nPer capire e fare pratica', 'Il corso si tiene nelle aule ZoWorking. Comprende dodici ore di lezione ed esercitazione, distribuite su due giornate da sei ore, con accesso all’interfaccia AskMyDocs durante le attività.', kicker='IL CORSO DI BASE AI', columns=[
            [block('Prima giornata', 'Funzionamento e limiti dell’AI; differenza tra modelli; token, crediti e consumi; scelta del modello in base al compito. Si impara a formulare richieste chiare e a riconoscere quando una risposta va controllata.'), block('Seconda giornata', 'Laboratorio sul software: preparare i testi, organizzare le fonti, fare domande e verificare i riferimenti. Si affrontano dati aziendali, permessi e regole di utilizzo, collegando ogni scelta al lavoro quotidiano.')],
            [block('Il prezzo del gruppo', '1.200 € fino a dieci persone; 1.500 € da undici a quindici. La quota comprende aula, docente, materiali e consumi degli esercizi didattici. Con dieci persone, 1.200 € equivalgono a 120 € a persona per due giorni, cioè 60 € al giorno.'), block('Come si partecipa', 'Organizziamo le date e possiamo riunire nella stessa classe persone di aziende diverse. La quota prenota la partecipazione del gruppo, non un’aula esclusiva. Per meno partecipanti si valuta il preventivo in base al gruppo e all’edizione disponibile.')]], callout='L’accesso didattico al software è compreso nelle due giornate. L’utilizzo ordinario dell’ambiente aziendale segue il canone di 19 € al mese e i consumi effettivi.'),
        ep('consulting', 'table', 'Capire i flussi\nScegliere dove intervenire', 'La consulenza serve a capire come lavora l’azienda: quali passaggi si ripetono, quali fonti sono disponibili e che cosa conviene rendere consultabile con l’AI, attraverso il RAG.', kicker='CONSULENZA SU MISURA', headers=['IMPEGNO', 'PREZZO', 'COME SI DEFINISCE IL LAVORO'], column_weights=[.9, .8, 2.7], table_font=10, padding=12, rows=[
            ['1 giornata', '700 €', 'Un primo flusso circoscritto e le relative fonti'],
            ['2 giornate', '1.400 €', 'Analisi più articolata e verifica di un caso concreto'],
            ['3 giornate', '2.100 €', 'Più flussi collegati, priorità e piano operativo']], note='Si concordano obiettivo e giornate in base alla complessità effettiva, fino a un massimo di tre. Le descrizioni orientano il preventivo e non garantiscono un numero fisso di processi esaminati. Una giornata vale sette ore complessive, comprese preparazione, analisi e restituzione. Le giornate possono essere consecutive o distribuite, anche in mezze giornate da 350 €.\n\nIl risultato è una mappa dei flussi esaminati, l’elenco delle fonti adatte e dei problemi da risolvere, una proposta di accessi e un piano di prova. Caricamenti sui dati aziendali consumano il credito del team. Integrazioni, bonifica massiva e riscrittura dei manuali richiedono un perimetro specifico.'),
        ep('software', 'case', 'Un posto per le fonti\nUn metodo per interrogarle', 'AskMyDocs raccoglie i documenti dell’azienda e permette di consultarli con domande e riferimenti alle fonti. Il canone riguarda l’azienda; il credito riguarda le elaborazioni.', kicker='CHE COSA FA IL SOFTWARE', feature_ids=['F-001', 'F-005', 'F-034', 'F-036'], image=art('archive', 130), columns=[
            [block('Preparare i contenuti', 'Si scelgono documenti aggiornati e leggibili. Il testo viene suddiviso in parti e preparato per la ricerca. Questa preparazione iniziale e gli aggiornamenti possono consumare credito AI.'), block('Fare una domanda', 'Il sistema cerca normalmente i passaggi pertinenti e li usa per costruire la risposta. Il modello scelto, la quantità di testo e i passaggi necessari determinano il consumo.')],
            [block('Verificare le fonti', 'Chi usa lo strumento controlla i riferimenti e riconosce quando la documentazione non basta. Il corso insegna questo metodo; la consulenza lo adatta ai flussi dell’azienda.'), block('Lavorare con il gruppo', 'Gli utenti autorizzati condividono l’ambiente e il saldo dell’azienda, con i propri accessi. Non si acquistano licenze per ogni persona. File e derivati rientrano nei 5 GB compresi nel canone.')]]),
        ep('conditions', 'article', 'Canone, corso e consulenza\nRestano voci distinte', 'Il canone parte dall’attivazione dell’ambiente per uso aziendale. La partecipazione al corso comprende già l’accesso didattico per le due giornate.', kicker='CONDIZIONI E PREVENTIVI', columns=[
            [block('Canone mensile', '19 € al mese per azienda, fatturati anticipatamente. Rinnovo mensile con disdetta prima della scadenza successiva. Utenti illimitati e 5 GB condivisi per file e derivati; maggiore spazio o installazioni dedicate si quotano a parte.'), block('Consumi e controllo', 'Il credito è separato e condiviso dal gruppo. Le operazioni possono essere messe in coda. Prima di una lavorazione si indica una stima o un tetto di spesa; il tetto non viene superato senza autorizzazione. I dettagli di ricarica sono in fondo.')],
            [block('Esempio di avvio', 'Corso fino a dieci persone 1.200 €, una giornata di consulenza 700 € e primo mese software 19 €: totale 1.919 € + IVA, consumi AI separati. Dai mesi successivi resta il canone di 19 € più l’utilizzo effettivo.'), block('Calendario e ordine', 'Date del corso e calendario della consulenza si concordano all’ordine. Corso e consulenza: 50% all’ordine e 50% a completamento. Assistenza tecnica nei feriali tramite referente aziendale. Provider, accessi e trattamento dei dati vengono definiti prima dei caricamenti reali.')]], callout='Proposta valida fino al 12 dicembre 2026. Il percorso si costruisce sulle persone da formare e sul lavoro da analizzare; non si riacquistano automaticamente corso e consulenza ogni mese.'),
        ep('models', 'table', 'Modelli recenti\nConsumi diversi', 'Esempi OpenAI della famiglia GPT, Google Gemini e DeepSeek. Le cifre descrivono un budget indicativo per una richiesta ordinaria o estesa; non sono una tariffa fissa per qualunque domanda.', kicker='APPROFONDIMENTO SUI MODELLI', source_ids=['MODELLO-R05', 'OR-M1', 'OR-M2', 'OR-M3', 'OR-M4', 'OR-M5'], headers=['MODELLO', 'CREDITI INDICATIVI', 'SPESA INDICATIVA'], column_weights=[1.7, 1.05, 1.15], table_font=9.7, padding=9, rows=[[m['name'], credit_range(m), price_range(m)] for m in MODELS], note=model_note + '\n\nUn credito equivale a 0,01 € di utilizzo e si può consumare anche in frazioni. I token sono piccole parti del testo elaborato e non equivalgono ai crediti. I prezzi riguardano i modelli via API; non sono i prezzi degli abbonamenti personali alle applicazioni ChatGPT o Gemini.\n\nIl confronto riguarda il costo a parità di ipotesi, non una classifica di qualità. La scelta si prova sul lavoro reale: un modello più costoso non è automaticamente migliore per ogni richiesta.'),
        ep('examples', 'article', 'Documenti ed esempi\nPer leggere il credito', 'Le quantità servono a orientare il budget. La preparazione iniziale e le domande successive consumano lo stesso saldo.', kicker='DETTAGLI DI UTILIZZO', feature_ids=['F-005', 'F-006'], columns=[
            [block('Che cosa è un documento', definition), block('Email e manuali', 'Un file solo può contenere poco testo oppure un intero manuale. Per questo il nome del file o il numero di cartelle non definiscono il prezzo. Gli allegati delle email aggiungono contenuto; le email si considerano come testo esportato e importato.')],
            [block('Esempio con testo già leggibile', '100 PDF di cinque pagine di testo e 500 domande con GPT-6 Luna: preparazione stimata circa 0,20 €, domande circa 5-10 €. Totale indicativo di utilizzo 5,20-10,20 €, oltre al canone. La lettura manuale dei file già caricati non consuma credito AI.'), block('Esempio con scansioni', '100 scansioni di cinque pagine e 300 domande con GPT-6 Luna: preparazione con OCR circa 5,20 €, domande circa 3-6 €. Totale indicativo 8,20-11,20 €, oltre al canone. Immagini da interpretare, annotazioni e layout difficili possono aumentare la spesa.')]]),
        ep('sources', 'references', 'Note e fonti\nDelle stime', 'Tariffe verificate il 12 novembre 2026. Le quantità derivano da ipotesi esplicite di testo elaborato; non sono medie misurate sull’archivio di un cliente.', kicker='PER APPROFONDIRE', source_ids=['MODELLO-R05', 'OR-M1', 'OR-M2', 'OR-M3', 'OR-M4', 'OR-M5', 'OR-EMBED', 'OR-FEES', 'OCR'], items=[
            {'title': 'OpenAI GPT-6 Luna', 'detail': 'Tariffe API: 0,10 USD input e 0,50 USD output per milione di token.', 'url': 'https://openrouter.ai/openai/gpt-6-luna'},
            {'title': 'OpenAI GPT-6.1 Sol', 'detail': 'Tariffe API: 2 USD input e 10 USD output per milione di token.', 'url': 'https://openrouter.ai/openai/gpt-6.1-sol'},
            {'title': 'Google Gemini 3.8 Flash', 'detail': '0,75 USD input e 3,75 USD output per milione. Promozione rilevata, soggetta a variazione.', 'url': 'https://openrouter.ai/google/gemini-3.8-flash'},
            {'title': 'Google Gemini 4 Carbon', 'detail': '2 USD input e 10 USD output per milione. Promozione rilevata, soggetta a variazione.', 'url': 'https://openrouter.ai/google/gemini-4-carbon'},
            {'title': 'DeepSeek V4.1 Flash 1017', 'detail': '0,022 USD input e 0,40 USD output per milione di token.', 'url': 'https://openrouter.ai/deepseek/deepseek-v4.1-flash-1017'},
            {'title': 'Criterio di calcolo', 'detail': 'Costo AI completo moltiplicato per 2,5; commissioni pertinenti incluse una volta. Cambio 1 USD = 1 EUR come ipotesi prudenziale, non cambio corrente. Nessuno sconto cache assunto; eventuali scritture cache vanno conteggiate.'},
            {'title': 'Preparazione e scansioni', 'detail': 'Embedding 0,02 USD per milione di token, più breve preparazione economica. OCR 4.1: 4 USD per mille pagine, senza annotazioni.', 'url': 'https://docs.mistral.ai/models/ocr-4-1'},
            {'title': 'Perimetro', 'detail': 'Funzioni web di ricerca e fonti già verificate; nuovi modelli e nuovo listino da configurare e collaudare. Le immagini sono illustrative. Nessuna inferenza a pagamento eseguita per questo preventivo.'},
            {'title': 'Ricariche e credito', 'detail': '25 € = 2.500 crediti; 50 € = 5.000; 100 € = 10.000. Crediti senza scadenza, utilizzabili con abbonamento attivo. Preparazione di un documento standard: circa 0,2 crediti; OCR semplice: circa 1 credito per pagina. Stime da affinare su un campione reale.'},
        ]),
        ep('closing', 'closing', 'Imparare insieme\nApplicarlo al lavoro', 'Due giornate a ZoWorking per acquisire il metodo. Una consulenza proporzionata ai flussi dell’azienda. Un canone chiaro per usare AskMyDocs.\naskmydocs.surfacesrl.com', kicker='IL PERCORSO DI AVVIO', image=art('community', 260), quote='Il software rende consultabili le fonti. La formazione prepara le persone. La consulenza collega lo strumento al lavoro reale.'),
    ]
    theme = deepcopy(json.loads((PREV / 'contenuti.json').read_text())['theme'])
    out('brochure.json', {'document': {'title': 'AskMyDocs listino software corso ZoWorking e consulenza', 'subject': 'Canone 19 euro e formazione in presenza con consulenza fino a tre giornate', 'brand_name': 'AskMyDocs', 'edition': '12 NOVEMBRE 2026 / REV 05', 'header': 'ASKMYDOCS / SOFTWARE E FORMAZIONE', 'footer_note': 'Proposta commerciale · IVA esclusa · Consumi indicativi', 'author': '', 'source_ids': ['OFFERTA-R05', 'MODELLO-R05', 'AMD-BRAND']}, 'theme': theme, 'pages': pages})
    word_pages = [
        page('listino', 'AskMyDocs listino software corso e consulenza', [
            para('Proposta del 12 novembre 2026. AskMyDocs costa 19 € al mese per azienda, con utenti illimitati e consumi AI separati. La formazione si svolge esclusivamente nelle aule ZoWorking. La consulenza si valuta sul lavoro da analizzare, fino a un massimo di tre giornate. Tutti gli importi sono IVA esclusa.'),
            table(['Servizio', 'Prezzo', 'Contenuto'], [['Software AskMyDocs', '19 € al mese per azienda', 'Utenti illimitati, ambiente condiviso, 5 GB per file e derivati. Consumi separati.'], ['Corso fino a 10 persone', '1.200 € per gruppo', 'Due giornate a ZoWorking, accesso didattico all’interfaccia ed esercitazioni.'], ['Corso da 11 a 15 persone', '1.500 € per gruppo', 'Lo stesso percorso di due giornate per un gruppo più numeroso.'], ['Consulenza', '700 € al giorno', 'Da una a tre giornate in base alla complessità.']], [1.6, 1.25, 2.45]),
            h('Il corso nelle aule ZoWorking'),
            para('Due giornate da sei ore, dodici ore complessive. Sono compresi aula, docente, materiali, accesso didattico al software durante le attività e consumi degli esercizi. Per gruppi con meno partecipanti si valuta un preventivo dedicato. Con dieci persone la quota da 1.200 € equivale a 120 € a persona per due giorni, cioè 60 € al giorno.'),
            para('Le date vengono organizzate e comunicate all’iscrizione; nella stessa classe possono esserci persone di altre aziende. Le fasce si riferiscono al gruppo iscritto e non a un’aula esclusiva. Il percorso comprende funzionamento e limiti dell’AI, scelta tra modelli, token e consumi, richieste, verifica delle fonti, dati e regole d’uso.'),
            h('Consulenza sui flussi aziendali'),
            table(['Giornate', '1', '2', '3'], [['Prezzo', '700 €', '1.400 €', '2.100 €']], [1.3, 1.3, 1.3, 1.3]),
            para('Analizziamo flussi, fonti, documenti e accessi, individuando che cosa conviene rendere consultabile con la ricerca AI detta RAG. Le giornate possono essere consecutive o distribuite, anche in mezze giornate da 350 €. Una giornata vale sette ore complessive, comprese preparazione e restituzione. Obiettivo e impegno si concordano prima dell’ordine, fino a tre giornate.'),
        ]),
        page('servizio', 'Software e condizioni di avvio', [
            para('AskMyDocs prepara i documenti per la ricerca e permette di fare domande con riferimenti alle fonti. Gli utenti autorizzati della stessa azienda condividono l’ambiente e il saldo di consumo. Il referente sceglie fonti aggiornate, controlla gli accessi e verifica le risposte. Il corso insegna il metodo; la consulenza lo adatta al lavoro dell’azienda.'),
            h('Canone e accesso didattico'),
            para('Il canone di 19 € è mensile, fatturato anticipatamente dall’attivazione dell’ambiente per uso aziendale. L’equivalente di dodici mesi è 228 €, senza obbligo di pagamento annuale anticipato. Rinnovo mensile con disdetta prima della scadenza successiva. L’accesso didattico durante le due giornate è compreso nel corso.'),
            para('Sono compresi 5 GB condivisi per file e derivati, utenti illimitati e gestione ordinaria del servizio. Maggiore spazio, infrastrutture dedicate e integrazioni si quotano a parte. Le elaborazioni possono essere messe in coda; il riferimento tecnico è fino a due operazioni AI contemporanee per azienda. Assistenza tecnica nei feriali tramite referente aziendale.'),
            h('Consumi e controllo'),
            para('Preparazione dei documenti, OCR, domande e nuove elaborazioni consumano credito condiviso. Caricamento senza elaborazione, lettura manuale dei file già disponibili ed esportazione non consumano credito AI. Si comunica una stima o un tetto prima della lavorazione, senza superare il tetto senza autorizzazione. A saldo zero si fermano nuove operazioni AI; i file restano leggibili con abbonamento attivo.'),
            para('Crediti acquistati senza scadenza, utilizzabili con abbonamento attivo. Provider, modelli, permessi e trattamento dati vengono definiti prima dei caricamenti reali. L’OCR remoto richiede attivazione concordata. In uscita si concorda l’esportazione entro 30 giorni dalla fine del servizio.'),
            h('Esempio di preventivo iniziale'),
            para('Corso per un gruppo fino a dieci persone 1.200 € + una giornata di consulenza 700 € + primo mese software 19 € = 1.919 € + IVA, consumi AI separati. Con due giornate di consulenza il totale è 2.619 €. Corso da undici a quindici persone, tre giornate di consulenza e primo mese software: 3.619 € + IVA, consumi separati.'),
            para('La consulenza restituisce una mappa dei flussi esaminati, fonti adatte e criticità dei materiali, proposta di accessi e piano di prova. Integrazioni, bonifica massiva e riscrittura dei manuali richiedono un perimetro specifico. Corso e consulenza: 50% all’ordine e 50% a completamento; date e calendario concordati all’ordine. Proposta valida fino al 12 dicembre 2026.'),
            para('Cliente ____________________  Gruppo corso ____________________\nData corso ____________________  Giornate consulenza ____________\nAttivazione software ____________________  Totale ________________'),
        ]),
        page('consumi', 'Dettagli dei consumi e modelli di esempio', [
            para('Esempi di modelli recenti OpenAI della famiglia GPT, Google Gemini e DeepSeek. Un credito vale 0,01 € di utilizzo e si può consumare in frazioni. Le fasce descrivono un budget indicativo per richiesta ordinaria o estesa, da verificare sul caso reale.'),
            table(['Modello', 'Crediti indicativi', 'Spesa indicativa'], [[m['name'], credit_range(m), price_range(m)] for m in MODELS], [2.4, 1.3, 1.3]),
            para(model_note),
            h('Che cosa significa documento standard'),
            para(definition),
            para('Esempio testuale: 100 PDF di cinque pagine e 500 domande con GPT-6 Luna possono consumare circa 0,20 € per la preparazione e 5-10 € per le domande: totale indicativo 5,20-10,20 €, oltre al canone. Con 100 scansioni di cinque pagine e 300 domande: preparazione con OCR circa 5,20 € e domande 3-6 €, totale 8,20-11,20 €. Immagini, annotazioni e layout complessi possono aumentare il consumo.'),
            h('Fonti e ipotesi'),
            para('Tariffe API verificate il 12 novembre 2026. Le stime sommano tutti i passaggi e applicano un fattore di servizio 2,5 al costo AI completo, comprese commissioni pertinenti. Cambio ipotizzato 1 USD = 1 EUR per preventivo, non cambio corrente. Nessuno sconto cache assunto; eventuali scritture cache vanno conteggiate. Sono stime aritmetiche, non medie misurate in produzione.'),
            para('Fonti: openrouter.ai/openai/gpt-6-luna ; openrouter.ai/openai/gpt-6.1-sol ; openrouter.ai/google/gemini-3.8-flash ; openrouter.ai/google/gemini-4-carbon ; openrouter.ai/deepseek/deepseek-v4.1-flash-1017 ; openrouter.ai/pricing ; docs.mistral.ai/models/ocr-4-1'),
            para('Ricariche credito: 25 € = 2.500 crediti; 50 € = 5.000; 100 € = 10.000. Crediti senza scadenza, utilizzabili con abbonamento attivo. Preparazione indicativa di un documento standard: 0,2 crediti; OCR semplice: 1 credito per pagina. Queste ricariche si aggiungono al canone e si acquistano quando servono.'),
        ]),
    ]
    docx = HERE / 'AskMyDocs_listino_ZoWorking.docx'
    helpers.make_doc(word_pages, docx, 'AskMyDocs listino ZoWorking revisione 05')
    word = helpers.Document(docx)
    word.core_properties.subject = 'Canone 19 euro al mese corso ZoWorking e consulenza 700 euro fino a tre giornate'
    word.core_properties.keywords = 'AskMyDocs, ZoWorking, canone, corso in aula, crediti, consulenza'
    from docx.shared import Pt
    for p in word.paragraphs:
        if p.text.startswith('Ricariche credito:'):
            for run in p.runs:
                run.font.size = Pt(9)
    word.save(docx)
    note = ['ASKMYDOCS - NOTA INTERNA REVISIONE 05', '12 novembre 2026', '',
        'CANONE CONFERMATO', '19 euro al mese per azienda, consumi esclusi, confermato espressamente da Marco. Fatturazione mensile, 228 euro equivalente di dodici mesi. Non attribuire tutto il costo della piattaforma a ciascun cliente.',
        'Budget comune 1.200 euro/anno più 6 euro di spazio incrementale e un’ora di supporto da 45 euro per azienda/anno, riserva 10%. Avvio tecnico standard ipotetico mezz’ora da 55 euro. Costi di personale e spalmature sono ipotesi, non fatture reali.',
        f"Con dodici mesi incassati servono circa {model['software_fixed']['companies_with_full_year_revenue_to_cover_shared_fixed_first_year']} aziende nel primo anno o {model['software_fixed']['companies_with_full_year_revenue_to_cover_shared_fixed_renewal']} a rinnovo per coprire il budget condiviso, senza contare margini dei consumi o dei servizi. Le disdette anticipano il rischio di recupero costi.",
        '', 'CORSO CONCORDATO', 'Solo nelle aule ZoWorking: 1.200 euro fino a 10 iscritti nel gruppo, 1.500 da 11 a 15; quote indicative e preventivo dedicato per gruppi piccoli. Possibili aziende diverse nella stessa classe, senza promessa di aula esclusiva. La quota è per il gruppo iscritto, non il costo dell’intera classe da ripartire automaticamente tra tutte le aziende.',
        'Due giornate: proposta di 6 ore di lezione per giorno, 12 totali, più due ore di preparazione. Accesso didattico e consumi degli esercizi compresi nelle due giornate. L’utilizzo ordinario aziendale attiva il canone separato.',
        model['course']['limit']]
    for c in model['course']['results']:
        note.append(f"Fascia {c['band']}: ricavo {c['price']} euro; costo diretto ipotetico con riserva {c['direct_cost_assumption']:.2f}; contribuzione {c['contribution_after_fees_and_reserve']:.2f} euro, {c['contribution_pct']:.2f}% dopo ipotesi di provvigione/incasso.")
    note += ['', 'CONSULENZA', '700 euro per giornata di sette ore complessive, fino a tre giornate, anche distribuite; mezza giornata 350 euro. Con costo ipotetico 55 euro/ora e riserva 10%, contributo per giornata 189 euro dopo provvigione ipotetica 10% e incasso 2,5%.',
        '', 'CONSUMI E MODELLI RECENTI', 'Ricariche 25/50/100 euro riportate soltanto in fondo ai documenti. Nessun credito incluso nel canone. Crediti senza scadenza con utilizzo subordinato a canone attivo; supera la condizione di scadenza/proroga della revisione 04.',
        'Tariffe aggiornate nelle schede OpenRouter dei cinque modelli; GPT-6 Luna usato anche come preprocessore ed esempio economico. Preparazione del documento standard calcolata 0,14 crediti, budget prudente 0,2. Modelli, ragionamento, cache e instradamento cambiano il conto. Le promozioni Gemini non sono garantite in futuro.',
        'Input e output sono totali di tutte le chiamate del modello scelto. Il ragionamento fatturabile è compreso nel budget output; richieste che lo superano richiedono più credito. Cache automatica OpenAI da disabilitare nel profilo di stima plain input oppure costi di scrittura da valorizzare.',
        '', 'LIMITI E ATTIVAZIONE', *model['limits'],
        'Costi Cloud reali già osservati nell’audit precedente: main prudenziale 48,66 USD nel periodo, ambienti identificati 73,08 USD. Non sono costi per singola azienda. Il budget condiviso è una proposta di lancio, da verificare al crescere del carico.',
        'Nessun prezzo, software, contatore, canone o dato di produzione è stato modificato. Calcolare e riconciliare costo completo, credito, modelli, quota spazio e rinnovi prima dell’attivazione.',
        '', 'PREVENTIVI', 'Corso 1.200 + consulenza 700 + primo mese 19 = 1.919 euro. Con due giornate di consulenza: 2.619. Corso 1.500 + tre giornate 2.100 + mese 19 = 3.619. Consumi AI separati in tutti i casi.']
    (HERE / 'Nota_costi_interna.txt').write_text('\n'.join(note) + '\n')
    (HERE / 'Per_Lorenzo.txt').write_text('''ASKMYDOCS - REVISIONE 05 ZOWORKING
12 novembre 2026 - importi IVA esclusa

Software: 19 euro al mese per azienda, utenti illimitati, 5 GB condivisi; consumi AI separati. Fatturazione mensile, senza pagamento annuale obbligatorio.

Corso: due giornate nelle aule ZoWorking, accesso didattico all’interfaccia AskMyDocs ed esercitazioni compresi. Quota del gruppo: 1.200 euro fino a dieci persone; 1.500 da undici a quindici. Per gruppi piccoli si valuta il preventivo. Date organizzate da noi, con possibili iscritti di altre aziende nella stessa classe; non si prenota un’aula esclusiva. Programma proposto: sei ore per giornata, dodici complessive.

Consulenza: 700 euro al giorno, massimo tre giornate. Una 700, due 1.400, tre 2.100. Impegno valutato in base ai flussi e alle fonti da analizzare; giornate consecutive o distribuite.

Esempio di avvio: corso fino a dieci persone 1.200 + una giornata di consulenza 700 + primo mese software 19 = 1.919 euro + IVA, consumi separati.

I modelli di esempio sono aggiornati: GPT-6 Luna, GPT-6.1 Sol, Gemini 3.8 Flash, Gemini 4 Carbon e DeepSeek V4.1 Flash 1017. Le ipotesi di consumo e le ricariche sono nelle ultime pagine, come dettagli di approfondimento. Tariffe verificate il 12 novembre 2026, non garanzie di consumo fisso o disponibilità futura.

Il PDF e il Word sostituiscono la precedente revisione 04 di AskMyDocs. Proposta valida fino al 12 dicembre 2026; listino e nuovi modelli da configurare e collaudare prima dell’attivazione. Gli altri prodotti restano invariati.
''')
    print(json.dumps({'pdf_pages': len(pages), 'word_sections': len(word_pages), 'output': str(HERE)}, ensure_ascii=False))

if __name__ == '__main__':
    build()
