"""AskMyDocs revisione 04: credito, esempi di consumo, corsi a gruppo e consulenza a giornata."""
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

def it(n, digits=0):
    return f'{n:,.{digits}f}'.replace(',', '_').replace('.', ',').replace('_', '.')

def euro(n):
    return it(n, 0 if D(str(n)) == D(str(n)).to_integral_value() else 2) + ' €'

def out(name, obj):
    (HERE / name).write_text(json.dumps(obj, ensure_ascii=False, indent=2) + '\n')

MODELS = [
    {'name': 'Economico', 'model': 'openai/gpt-4o-mini', 'input_usd_m': '.15', 'output_usd_m': '.60', 'budget_credits': [1, 2]},
    {'name': 'Intermedio', 'model': 'openai/gpt-4.1-mini', 'input_usd_m': '.40', 'output_usd_m': '1.60', 'budget_credits': [2, 5]},
    {'name': 'Più costoso', 'model': 'openai/gpt-4.1', 'input_usd_m': '2', 'output_usd_m': '8', 'budget_credits': [8, 23]},
]
PACKS = [
    {'name': 'Prima prova', 'price': 25, 'credits': 2500, 'standard_documents': 500, 'questions_display': [450, 1200]},
    {'name': 'Lavoro quotidiano', 'price': 50, 'credits': 5000, 'standard_documents': 1000, 'questions_display': [900, 2400]},
    {'name': 'Uso frequente', 'price': 100, 'credits': 10000, 'standard_documents': 2000, 'questions_display': [1900, 4800]},
]
BANDS = ['Da 1 a 5', 'Da 6 a 10', 'Da 11 a 15']
COURSES = {'Online': [900, 1200, 1500], 'Coworking': [1300, 1700, 2100], 'In azienda': [1200, 1500, 1800]}
CONSULTING_DAY = 850
TRAVEL = 250
STANDARD_DOC_CREDITS = D('.2')

def inference(ni, no, model):
    return (D(ni) * D(model['input_usd_m']) + D(no) * D(model['output_usd_m'])) / 1_000_000

def retail_credits(technical_eur):
    # Un credito vale 0,01 euro; precisione centesimi di credito, non un credito minimo per file.
    return (technical_eur * D('2.5') / D('.01')).quantize(D('.01'), rounding=ROUND_CEILING)

def cost_model():
    rows = []
    for m in MODELS:
        prices = []
        for factor in [1, 3]:
            # Somme per l'intera richiesta, NON per singola chiamata. Storico, fonti, risposte intermedie e verifiche inclusi nei totali ipotizzati.
            selected = inference(10_000 * factor, 1_000 * factor, m)
            preprocessor = inference(1_000 * (1 if factor == 1 else 2), 200 * (1 if factor == 1 else 2), MODELS[0])
            query_embedding = D(200) * D('.02') / 1_000_000
            technical = (selected + preprocessor + query_embedding) * D('1.055')
            credits = retail_credits(technical)
            prices.append(float(credits))
        assert all(D(str(actual)) <= D(budget) for actual, budget in zip(prices, m['budget_credits']))
        rows.append({**m, 'calculated_credits_simple_extended': prices})
    # 3.000 token di testo, 10% extra embedding; una sintesi breve con modello economico è un'ipotesi di arricchimento.
    embedding = D(3300) * D('.02') / 1_000_000
    enrichment = inference(3000, 300, MODELS[0])
    doc_cost = (embedding + enrichment) * D('1.055')
    assert retail_credits(doc_cost) <= STANDARD_DOC_CREDITS
    # OCR 4.1 diretto: niente commissione OpenRouter sul costo Mistral diretto.
    ocr_per_page = D(4) / 1000
    ocr_credits = retail_credits(ocr_per_page)
    assert ocr_credits == 1
    assert STANDARD_DOC_CREDITS + 5 * ocr_credits == D('5.2')
    pack_math = []
    for p in PACKS:
        used = D(p['standard_documents']) * STANDARD_DOC_CREDITS
        balance = D(p['credits']) - used
        low, high = int(balance / 5), int(balance / 2)
        assert p['questions_display'][0] <= low and p['questions_display'][1] <= high
        pack_math.append({'price': p['price'], 'document_credits': float(used), 'remaining_credits': float(balance), 'intermediate_questions_exact_budget_range': [low, high]})
    # Solo costi condivisi: non attribuire tutta l'applicazione a ogni azienda.
    fixed_annual = 1200
    cohorts = []
    for annual_spend in [25, 50, 100, 300, 600]:
        contribution = D(annual_spend) * D('.435') - D(6)
        cohorts.append({'annual_consumed_credit_revenue_per_company': annual_spend, 'after_variable_contribution_per_company': float(contribution), 'companies_for_shared_fixed_break_even': math.ceil(D(fixed_annual) / contribution)})
    course_economics = []
    for mode, prices in COURSES.items():
        for i, price in enumerate(prices):
            room = [200, 250, 300][i] if mode == 'Coworking' else 0
            direct = D(10 * 55 + room + [20, 40, 60][i]) * D('1.10')
            contribution = D(price) * D('.875') - direct
            assert contribution > 0
            course_economics.append({'mode': mode, 'participants': BANDS[i], 'price': price, 'direct_cost_assumption': float(direct), 'contribution': float(contribution), 'contribution_pct': float(contribution / D(price) * 100)})
    day_cost = D(7 * 55) * D('1.1')
    return {
        'date': '2026-11-12', 'currency': 'EUR', 'status': 'stima aritmetica di preventivo, non benchmark di produzione',
        'credit_value_eur': .01, 'metering_precision_credits': .01, 'technical_cost_multiplier': 2.5,
        'planning_exchange_usd_to_eur': 1, 'exchange_note': 'Ipotesi prudenziale di calcolo, NON cambio corrente. In fatturazione usare il costo effettivo in euro.',
        'openrouter_purchase_fee_assumption_pct': 5.5, 'cache_discounts_assumed': False,
        'question_assumptions': {'simple_selected_model_total_input_tokens': 10000, 'simple_selected_model_total_output_tokens': 1000, 'extended_selected_model_total_input_tokens': 30000, 'extended_selected_model_total_output_tokens': 3000, 'additional_preprocessor_tokens_simple': [1000, 200], 'additional_preprocessor_tokens_extended': [2000, 400], 'query_embedding_tokens': 200, 'scope': 'Totali di TUTTE le chiamate del modello scelto in una richiesta; se il flusso supera questi totali, la domanda costa di più.'},
        'models': rows, 'document_assumptions': {'equivalent_text_pages': 5, 'words': 2000, 'source_text_tokens_assumed': 3000, 'embedding_tokens_with_overlap': 3300, 'embedding_usd_per_million': .02, 'optional_economic_enrichment_input_output_tokens': [3000, 300], 'calculated_preparation_credits': float(retail_credits(doc_cost)), 'rounded_budget_preparation_credits': .2, 'embedding_only_credits': float(retail_credits(embedding * D('1.055')))},
        'ocr': {'model': 'mistral-ocr-4-1', 'direct_usd_per_1000_pages': 4, 'retail_credits_per_page_planning': float(ocr_credits), 'standard_five_page_scan_including_preparation_credits': 5.2, 'openrouter_fee_added_to_direct_mistral': False, 'annotations_and_image_interpretation_excluded': True},
        'pack_calculations': pack_math,
        'fixed_costs': {'shared_annual_budget': fixed_annual, 'shared_cloud_budget_assumed': 900, 'shared_maintenance_operations_budget_assumed': 300, 'additional_storage_and_service_budget_per_company_annual': 6, 'actual_cloud_reference': '../offerte-portafoglio-2026-11-12/revisione02/audit_costi_verificati.json', 'actual_main_prudential_upper_usd_period': 48.66, 'actual_all_identified_environments_usd_period': 73.08, 'note': '48,66 USD attribuisce prudenzialmente a main tutto il DB condiviso; non è un costo per azienda. Il budget 1.200 euro è un minimo di lancio da verificare con il carico, non un consuntivo né hosting dedicato.'},
        'assumed_sales_commission_pct': 10, 'assumed_collection_pct': 2.5, 'technical_cost_reserve_pct': 10,
        'minimum_usage_contribution_pct': 43.5, 'shared_fixed_break_even_scenarios': cohorts,
        'course_cost_assumptions': {'trainer_live_hours': 8, 'preparation_hours': 2, 'loaded_hour': 55, 'room_per_course_coworking': [200, 250, 300], 'materials_platform_per_group': [20, 40, 60], 'room_prices_are_unquoted_budget_estimates': True, 'results': course_economics},
        'consulting_cost_assumptions': {'total_hours_per_day_including_preparation_and_report': 7, 'loaded_hour': 55, 'price_per_day': 850, 'direct_cost_with_reserve': float(day_cost), 'contribution_per_day': float(D(850) * D('.875') - day_cost)},
        'limits': ['Nessuna inferenza a pagamento eseguita; stime basate su prezzi pubblici e token ipotizzati, non medie misurate di AskMyDocs.', 'Il ledger locale precedente con costo zero non prova gratuità. Costo completo per richiesta e saldo commerciale da riconciliare e collaudare prima del lancio.', 'Le chiamate accessorie, le rigenerazioni, OCR, immagini, ragionamento e contesti oltre ipotesi vanno conteggiati; nessuna equivalenza fissa crediti/domande.', 'Ricavi considerati quando consumati: il credito prepagato inutilizzato non è margine acquisito nel modello.', 'Una sola ricarica annua da 25 euro non sostiene da sola infrastruttura e assistenza personalizzata. Il pareggio dipende da portafoglio clienti e utilizzo; servizi professionali fatturati a parte.', 'La contribuzione non è utile netto; sviluppo evolutivo, costi generali e imposte non inclusi.'],
    }

def block(title, text):
    return {'heading': title, 'text': text}

def art(name, height=145, caption='Illustrazione editoriale del prodotto'):
    return {'path': f'public/editorial/{name}-day.webp', 'height': height, 'fit': 'cover', 'focus': [.5, .5], 'caption': caption}

def editorial(id, layout, title, deck='', **kw):
    return {'id': id, 'layout': layout, 'section': title.replace('\n', ' '), 'title': title, 'deck': deck, 'source_ids': kw.pop('source_ids', ['CONSUMI-PROPOSTA', 'AMD-FACTS', 'AMD-BRAND']), 'feature_ids': kw.pop('feature_ids', []), **kw}

def build():
    HERE.mkdir(exist_ok=True)
    model = cost_model()
    out('modello_stime_interno.json', model)
    offer = {
        'revision': '04 Consumi comprensibili', 'date': '2026-11-12', 'valid_until': '2026-12-12', 'tax': 'IVA esclusa',
        'pricing_basis': 'Ricariche prepagate a consumo, condivise da tutti gli utenti della stessa azienda', 'per_user_fee': 0, 'monthly_software_subscription': 0,
        'credit_value_eur': .01, 'credit_precision': .01, 'packages': PACKS,
        'standard_document': {'meaning': 'Unità indicativa di testo: circa cinque pagine, circa 2.000 parole; non un file a tariffa fissa', 'index_preparation_budget_credits': .2, 'scan_five_pages_budget_credits': 5.2},
        'models_budget': [{'name': m['name'], 'model': m['model'], 'question_credits_range': m['budget_credits']} for m in MODELS],
        'service': {'shared_company_storage_gb_including_derivatives': 5, 'users': 'illimitati', 'credit_and_access_months_per_topup': 12, 'credit_rollover': 'Una ricarica prima della scadenza proroga anche il saldo residuo di 12 mesi', 'automatic_renewal': False, 'minimum_annual_topup_if_renewing': 25, 'technical_operations_parallel_per_team': 2},
        'course': {'live_hours': 8, 'participant_bands': BANDS, 'fixed_group_prices': COURSES, 'is_separate_from_software': True, 'positioning': 'Passaggio fondamentale consigliato alle aziende che iniziano; prezzo di gruppo, non tariffa per singolo partecipante'},
        'consulting': {'price_per_day': 850, 'hours_per_day': 7, 'days_range': [1, 5], 'can_be_split_into_half_days': True, 'scope': 'Analizzare flussi, fonti, documenti, permessi e casi di RAG; lavoro concordato e restituzione inclusi nel monte ore'},
        'local_travel_per_visit': 250, 'supersedes': 'Sostituisce per AskMyDocs la revisione 03 con canoni 149/299/599 euro e corsi per persona. Gli altri prodotti non cambiano.',
        'quotes': [{'name': 'Primo utilizzo fino a 5 persone', 'software_credit': 25, 'course': 900, 'consulting': 850, 'travel': 0, 'total': 1775}, {'name': 'Reparto fino a 10 persone', 'software_credit': 50, 'course': 1700, 'consulting': 2550, 'travel': 0, 'total': 4300}, {'name': 'Diffusione fino a 15 persone', 'software_credit': 100, 'course': 1800, 'consulting': 4250, 'travel': 500, 'total': 6650}],
    }
    for q in offer['quotes']:
        assert sum(q[k] for k in ['software_credit', 'course', 'consulting', 'travel']) == q['total']
    out('offerta.json', offer)
    sources = [
        {'id': 'CONSUMI-PROPOSTA', 'path': 'offerta.json', 'title': 'Proposta commerciale revisione 04', 'publisher': 'Surface', 'kind': 'proposal', 'checked_at': '2026-11-12', 'supports': 'Ricariche, condizioni, corsi per gruppo e giornate di consulenza proposti', 'limits': 'Non è un listino già attivato nel software'},
        {'id': 'MODELLO-STIME', 'path': 'modello_stime_interno.json', 'title': 'Calcolo dei consumi e ipotesi economiche', 'publisher': 'Surface', 'kind': 'calculation', 'checked_at': '2026-11-12', 'supports': 'Token, tariffe, arrotondamenti, scenari e pareggio', 'limits': 'Stime, non benchmark di produzione'},
        {'id': 'ANNOTA', 'url': 'https://annota.ai/it/pricing/#plans', 'title': 'Piani e crediti Annota', 'publisher': 'Annota', 'kind': 'market', 'checked_at': '2026-11-12', 'supports': 'Struttura a crediti condivisi; Team 29,28 euro IVA inclusa per membro/mese, minimo due', 'limits': 'Capacità e quote differenti, nessuna equivalenza funzionale o di prestazioni'},
        {'id': 'OR-ECONOMICO', 'url': 'https://openrouter.ai/openai/gpt-4o-mini', 'title': 'GPT 4o mini tariffe API', 'publisher': 'OpenRouter', 'kind': 'pricing', 'checked_at': '2026-11-12', 'supports': '0,15 USD input e 0,60 USD output per milione di token'},
        {'id': 'OR-INTERMEDIO', 'url': 'https://openrouter.ai/openai/gpt-4.1-mini', 'title': 'GPT 4.1 mini tariffe API', 'publisher': 'OpenRouter', 'kind': 'pricing', 'checked_at': '2026-11-12', 'supports': '0,40 USD input e 1,60 USD output per milione di token'},
        {'id': 'OR-COSTOSO', 'url': 'https://openrouter.ai/openai/gpt-4.1', 'title': 'GPT 4.1 tariffe API', 'publisher': 'OpenRouter', 'kind': 'pricing', 'checked_at': '2026-11-12', 'supports': '2 USD input e 8 USD output per milione di token'},
        {'id': 'OR-EMBEDDING', 'url': 'https://openrouter.ai/openai/text-embedding-3-small', 'title': 'Embedding testo tariffe API', 'publisher': 'OpenRouter', 'kind': 'pricing', 'checked_at': '2026-11-12', 'supports': '0,02 USD per milione di token per preparare la ricerca'},
        {'id': 'OR-FEES', 'url': 'https://openrouter.ai/pricing', 'title': 'Commissioni OpenRouter', 'publisher': 'OpenRouter', 'kind': 'pricing', 'checked_at': '2026-11-12', 'supports': 'Standard 5,5% sugli acquisti di credito; conteggiata una sola volta'},
        {'id': 'MISTRAL-OCR', 'url': 'https://docs.mistral.ai/models/ocr-4-1', 'title': 'OCR 4.1', 'publisher': 'Mistral', 'kind': 'pricing', 'checked_at': '2026-11-12', 'supports': 'OCR semplice 4 USD per mille pagine; annotazioni escluse'},
        {'id': 'AMD-FACTS', 'path': 'marketing/analisi/presentazione-prodotto-2026-10-29/inventario-candidato.json', 'title': 'Catalogo funzionale AskMyDocs', 'publisher': 'Surface', 'kind': 'product', 'checked_at': '2026-11-12', 'supports': 'F-001 ricerca e fonti, F-005 ingestione, F-006 OCR, F-034 team, F-036 permessi', 'limits': 'Catalogo storico; disponibilità OCR e modelli da configurare per il cliente, nessuna nuova demo live'},
        {'id': 'AMD-CODE', 'path': 'config/ai.php', 'title': 'Pipeline e modelli configurabili', 'publisher': 'Surface', 'kind': 'product', 'checked_at': '2026-11-12', 'supports': 'Modelli distinti per chat ed embedding; preparazione, ricerca e sintesi non equivalgono a una sola chiamata', 'limits': 'Codice letto, non benchmark'},
        {'id': 'AMD-BRAND', 'path': 'marketing/offerte-con-formazione-2026-11-12/brand.json', 'title': 'Identità della landing', 'publisher': 'Surface', 'kind': 'media', 'checked_at': '2026-11-12', 'supports': 'Font, logo, palette e immagini editoriali già verificate'},
        {'id': 'AMD-CLOUD', 'path': 'marketing/offerte-portafoglio-2026-11-12/revisione02/audit_costi_verificati.json', 'title': 'Audit costi Cloud già osservati', 'publisher': 'Surface', 'kind': 'cost', 'checked_at': '2026-11-12', 'supports': 'Infrastruttura condivisa osservata, non costo per singola azienda'},
    ]
    out('fonti.json', {'sources': sources})
    definition = '* Documento standard: unità indicativa di circa cinque pagine di testo leggibile, circa 2.000 parole. Un file può valere meno di un documento standard oppure molti documenti standard. Contano testo elaborato, immagini, formato e modello scelto; i numeri non sono un limite di file né una quantità garantita.'
    pack_note = 'Stima con circa 0,2 crediti per preparare un documento standard e 2-5 crediti per domanda con GPT-4.1 mini. I documenti e le domande consumano lo stesso saldo: le quantità in tabella sono già una combinazione dei due usi. PDF con testo selezionabile, senza OCR, entro lo spazio disponibile. Questi esempi sono indicativi; richieste più lunghe o altri modelli cambiano il consumo.'
    pages = [
        editorial('cover', 'cover', 'Documenti da consultare\nCredito da utilizzare', 'Ricariche da 25 € per tutta l’azienda.\nCorso e consulenza si quotano separatamente.', kicker='UTILIZZO A CONSUMO', display_name='AskMyDocs', image=art('knowledge'), band_title='DOCUMENTI / DOMANDE / CREDITI', band_subtitle='ASKMYDOCS / REVISIONE 04 / 12 NOVEMBRE 2026'),
        editorial('how', 'article', 'Caricare le fonti\nFare domande con criterio', 'AskMyDocs prepara i testi dell’azienda per la ricerca e permette di fare domande con riferimenti ai documenti. Il costo dipende dalle elaborazioni svolte.', kicker='CHE COSA STATE ACQUISTANDO', feature_ids=['F-001', 'F-005', 'F-034', 'F-036'], columns=[
            [block('Un saldo per tutta l’azienda', 'Acquistate credito, condiviso dagli utenti autorizzati della stessa azienda. Un credito vale 0,01 € di utilizzo. Non c’è una tariffa per persona. La ricarica si usa quando si preparano documenti o si chiedono risposte all’AI.'), block('Preparare i documenti', 'I testi vengono suddivisi in parti e preparati per la ricerca. Questa attività iniziale si chiama indicizzazione. Nuovi documenti, aggiornamenti e nuove elaborazioni possono consumare altro credito.')],
            [block('Interrogare i contenuti', 'A ogni domanda il sistema cerca i passaggi pertinenti e li passa al modello AI. Di solito non rilegge tutto il patrimonio documentale. Una richiesta ampia, molti confronti o una conversazione lunga possono richiedere più testo e più passaggi.'), block('Imparare a scegliere', 'Il corso spiega che cosa può fare l’AI, come scegliere un modello e come verificare una risposta. La consulenza analizza il lavoro dell’azienda e decide quali flussi e documenti conviene rendere consultabili.')]], callout='Una raccolta è un gruppo di documenti sullo stesso argomento, per esempio i manuali del reparto. Il suo nome o il numero di cartelle non determinano il prezzo.'),
        editorial('documents', 'table', 'Che cosa intendiamo\nper documento', 'Per fare una stima usiamo un documento standard di circa cinque pagine di testo, circa 2.000 parole. È un riferimento per capire il credito, non una tariffa uguale per ogni file.', kicker='L’ASTERISCO SPIEGATO', feature_ids=['F-005', 'F-006'], headers=['ESEMPIO', 'EQUIVALENZA INDICATIVA', 'CHE COSA INCIDE'], column_weights=[1.35, 1.1, 1.65], table_font=9.6, padding=9, rows=[
            ['Email di 200 parole', 'Circa 1/10 di documento standard', 'Vale il testo del messaggio; allegati e messaggi citati si aggiungono'],
            ['PDF di 5 pagine di testo', 'Circa 1 documento standard', 'Testo selezionabile, circa 2.000 parole complessive'],
            ['Manuale di 50 pagine di testo', 'Circa 10 documenti standard', 'È un solo file, ma contiene molto più testo'],
            ['Scansione di 5 pagine', 'Circa 1 documento standard dopo l’estrazione', 'Si aggiunge l’OCR, cioè la lettura del testo dalle immagini'],
            ['PDF con tabelle o fotografie', 'Da stimare sul contenuto reale', 'Layout, testo estratto e interpretazione delle immagini possono richiedere più elaborazioni']], note=definition + ' Le email sono considerate come testo esportato e importato: non è promessa una sincronizzazione automatica della casella.'),
        editorial('packs', 'table', 'Tre ricariche\nUn solo saldo condiviso', 'Si paga il credito acquistato. Nessun canone mensile del software e nessuna tariffa per utente. Tutti gli importi sono IVA esclusa.', kicker='QUANTO COSTA ASKMYDOCS', headers=['RICARICA', 'CREDITO', 'ESEMPIO COMBINATO DI UTILIZZO*'], column_weights=[1.1, .85, 2.25], table_font=10, padding=12, rows=[
            ['25 €\nPrima prova', '2.500 crediti', 'Circa 500 documenti standard\n+ 450-1.200 domande'],
            ['50 €\nLavoro quotidiano', '5.000 crediti', 'Circa 1.000 documenti standard\n+ 900-2.400 domande'],
            ['100 €\nUso frequente', '10.000 crediti', 'Circa 2.000 documenti standard\n+ 1.900-4.800 domande']], note=pack_note + '\n\n' + definition + '\n\nOgni ricarica comprende 12 mesi di accesso e 5 GB condivisi per file e derivati. Il credito è valido 12 mesi; una ricarica prima della scadenza proroga anche il residuo. Per rinnovare basta una ricarica da 25 €. Nessun rinnovo automatico. Corso e consulenza sono extra.'),
        editorial('models', 'table', 'La stessa domanda\nPuò costare diversamente', 'Il modello scelto cambia il consumo. Anche la quantità di testo letta, la lunghezza della risposta e i passaggi necessari contano. Le fasce seguenti sono stime, non tariffe fisse per domanda.', kicker='SCEGLIERE IL MODELLO', source_ids=['MODELLO-STIME', 'OR-ECONOMICO', 'OR-INTERMEDIO', 'OR-COSTOSO', 'OR-FEES'], headers=['MODELLO DI ESEMPIO', 'CREDITI PER DOMANDA*', 'COSTO INDICATIVO'], column_weights=[1.6, 1.1, 1.1], table_font=10, padding=11, rows=[
            ['Economico\nGPT-4o mini', 'Circa 1-2', '0,01-0,02 €'],
            ['Intermedio\nGPT-4.1 mini', 'Circa 2-5', '0,02-0,05 €'],
            ['Più costoso\nGPT-4.1', 'Circa 8-23', '0,08-0,23 €']], note='* Ipotesi di richiesta ordinaria o estesa: circa 10.000-30.000 token letti e 1.000-3.000 token prodotti, sommati su tutti i passaggi del modello scelto, più ricerca e preparazione della domanda. Un token è una piccola parte di testo: non coincide con un documento o con un credito. Richieste che superano queste ipotesi possono costare di più.\n\nEsempio con 25 €: dopo aver preparato 500 documenti standard restano circa 2.400 crediti. Possono corrispondere a circa 1.200-2.400 domande economiche, 450-1.200 intermedie oppure 100-300 con il modello più costoso. Sono tre usi alternativi dello stesso saldo.\n\nI modelli disponibili si concordano all’attivazione. Un prezzo maggiore non garantisce una risposta migliore per ogni compito. Si verifica sempre il risultato sulle fonti.'),
        editorial('examples', 'table', 'Email, PDF e scansioni\nEsempi di spesa', 'Scenari illustrativi con modello intermedio e testo leggibile. Preparazione iniziale e domande si pagano dallo stesso credito. Importi IVA esclusa.', kicker='CHE COSA PUÒ CAMBIARE', headers=['MATERIALI E DOMANDE', 'PREPARAZIONE', 'DOMANDE', 'TOTALE STIMATO'], column_weights=[1.7, .85, .85, 1.05], table_font=9.5, padding=10, rows=[
            ['100 email da 200 parole\n+ 100 domande', 'Circa 0,02 €', '2-5 €', '2,02-5,02 €'],
            ['100 PDF di 5 pagine di testo\n+ 500 domande', 'Circa 0,20 €', '10-25 €', '10,20-25,20 €'],
            ['20 manuali di 50 pagine\n+ 200 domande', 'Circa 0,40 €', '4-10 €', '4,40-10,40 €'],
            ['100 scansioni di 5 pagine\n+ 300 domande', 'Circa 5,20 €\nOCR compreso', '6-15 €', '11,20-20,20 €']], note='I primi tre scenari usano documenti con testo già disponibile. Per le scansioni l’ipotesi è 1 credito per pagina OCR e 0,2 crediti per documento standard preparato: 500 pagine OCR costano circa 5 €, cui si aggiungono circa 0,20 € di preparazione.\n\nSono conti di preventivo, non risultati misurati sul vostro archivio. Immagini da interpretare, annotazioni, qualità delle scansioni, tabelle difficili, riepiloghi lunghi e tentativi aggiuntivi possono aumentare il costo. L’OCR remoto richiede un’attivazione concordata. Prima di un caricamento massivo si stima un campione rappresentativo.'),
        editorial('course', 'table', 'Il corso insegna\nA usare e scegliere', 'Otto ore di lezione. Il prezzo è fisso per il gruppo nella fascia scelta: non si moltiplica per ogni partecipante. Corso e credito software sono voci separate.', kicker='FORMAZIONE DI BASE AI', headers=['MODALITÀ', '1-5 PERSONE', '6-10 PERSONE', '11-15 PERSONE'], column_weights=[1.4, 1, 1, 1], table_font=9.8, padding=10, rows=[
            ['Online', '900 €', '1.200 €', '1.500 €'], ['Coworking', '1.300 €', '1.700 €', '2.100 €'], ['In azienda', '1.200 €', '1.500 €', '1.800 €']], note='Programma: due ore su funzionamento e limiti dell’AI; due su modelli, token, crediti e costi; due su domande, fonti e verifica; due di laboratorio su documenti, dati e regole d’uso. Sono compresi docente, preparazione, materiali e attestato di partecipazione. I consumi AI degli esercizi didattici sono compresi nel corso.\n\nLo stesso corso online costa 900 € sia per una sia per cinque persone. Con cinque partecipanti il costo medio è 180 €; con dieci, il gruppo da 1.200 € equivale a 120 € a persona. Si fattura comunque il gruppo. Il limite di aula è 15 persone; oltre si organizzano edizioni distinte.\n\nOnline: quattro incontri da due ore o due da quattro. Coworking: due mezze giornate, sala compresa; si possono riunire aziende diverse, massimo 15 persone totali, con un solo costo di edizione ripartito e preventivo comune. In azienda: una giornata da otto ore o due mezze giornate, sala tranquilla a disposizione del cliente. Trasferta locale 250 € per visita entro 50 km e due ore A/R. Due visite costano 500 €.'),
        editorial('consulting', 'table', 'Prima capire i flussi\nPoi scegliere i documenti', 'La consulenza studia come lavora l’azienda: quali passaggi si ripetono, quali fonti esistono e che cosa conviene rendere consultabile con la ricerca documentale AI, detta RAG.', kicker='CONSULENZA DA UNA A CINQUE GIORNATE', headers=['IMPEGNO', 'PREZZO', 'PERIMETRO DA CONCORDARE'], column_weights=[.8, .8, 2.8], table_font=9.6, padding=9, rows=[
            ['1 giornata', '850 €', 'Un flusso circoscritto, fonti principali e primo caso di lavoro'],
            ['2 giornate', '1.700 €', 'Analisi più approfondita, qualità dei materiali e prova del caso'],
            ['3 giornate', '2.550 €', 'Più flussi collegati o un reparto, priorità e regole operative'],
            ['4 giornate', '3.400 €', 'Più interlocutori, permessi e articolazione delle fonti'],
            ['5 giornate', '4.250 €', 'Percorso articolato tra reparti, prove e piano di adozione']], note='Le descrizioni aiutano a orientarsi; il numero di flussi non è una quantità garantita per giornata. Si concordano obiettivo e giornate dopo un incontro iniziale. Una giornata vale sette ore complessive, comprese analisi, preparazione e restituzione. Le giornate si possono svolgere insieme o distribuire, anche in mezze giornate da 425 €.\n\nConsegniamo una mappa dei flussi esaminati, l’elenco delle fonti adatte e dei problemi da risolvere, una proposta di accessi e un piano di prova con domande campione. Il carico viene stimato sui documenti reali. Il corso base resta distinto; implementazioni, integrazioni, bonifica massiva e riscrittura dei manuali richiedono un perimetro proprio. Credito AI per i documenti aziendali e trasferte separati.'),
        editorial('quotes', 'table', 'Un preventivo semplice\nCon voci riconoscibili', 'Esempi di avvio, IVA esclusa. Il credito si ricarica quando serve; corso e consulenza si scelgono secondo preparazione delle persone e complessità del lavoro.', kicker='CREDITO / CORSO / CONSULENZA', headers=['VOCE', 'PRIMO UTILIZZO', 'REPARTO', 'DIFFUSIONE'], column_weights=[1.25, 1, 1, 1], table_font=9.5, padding=10, rows=[
            ['Ricarica AskMyDocs', '25 €', '50 €', '100 €'],
            ['Corso di 8 ore', '900 €\nOnline, 1-5 persone', '1.700 €\nCoworking, 6-10', '1.800 €\nIn azienda, 11-15'],
            ['Consulenza', '850 €\n1 giornata', '2.550 €\n3 giornate', '4.250 €\n5 giornate'],
            ['Trasferte', '0 €', '0 €', '500 €\n2 visite locali'],
            ['Totale avvio', '1.775 €', '4.300 €', '6.650 €']], note='Nel terzo esempio il corso si tiene in due mezze giornate in azienda; la consulenza è online. Le due trasferte riguardano quindi il solo corso. Una diversa presenza in sede si riquota.\n\nQueste combinazioni non impongono un percorso unico. Alle aziende che iniziano raccomandiamo il corso prima dell’uso operativo e una consulenza proporzionata al caso. Si può acquistare anche solo il credito: in quel caso non sono comprese analisi dei flussi o lezioni. Al termine dell’avvio non si ripagano automaticamente corso e consulenza.'),
        editorial('conditions', 'article', 'Credito controllabile\nCondizioni comprensibili', 'Il credito serve per le elaborazioni. Il corso serve per capire le scelte. La consulenza serve per applicarle al lavoro dell’azienda.', kicker='SERVIZIO E CONDIZIONI', columns=[
            [block('Accesso e spazio', 'Ogni ricarica comprende 12 mesi di servizio condiviso per una sola azienda e 5 GB complessivi per file e derivati. Gli utenti autorizzati sono illimitati. Più spazio, installazioni dedicate e integrazioni si quotano prima dell’ordine. Le elaborazioni possono restare in coda.'), block('Saldo e scadenza', 'Il credito è valido 12 mesi. Una ricarica prima della scadenza proroga il residuo di altri 12 mesi. Nessun rinnovo automatico: per proseguire basta una ricarica da 25 €. A saldo zero si fermano nuove elaborazioni AI; i file restano consultabili durante il periodo di servizio. Si concorda l’esportazione entro 30 giorni dalla scadenza.')],
            [block('Stime e autorizzazione', 'Prima delle elaborazioni si comunica il consumo stimato o un tetto di spesa. Nessun superamento del tetto senza autorizzazione. Caricare un file senza elaborarlo, leggerlo o esportarlo non consuma credito AI. Rielaborazioni e modelli diversi possono cambiare i costi.'), block('Ordine e assistenza', 'Credito prepagato; corso e consulenza 50% all’ordine e 50% a completamento. Assistenza tecnica del servizio nei feriali, tramite referente aziendale; lezioni e lavoro sul caso sono servizi separati. Provider e trattamento dati si concordano prima dei caricamenti reali. Proposta valida fino al 12 dicembre 2026.')]], callout='I crediti sono unità commerciali; i token sono unità tecniche del testo elaborato dai modelli. Il conto dipende dall’intero lavoro svolto, non dal numero di utenti.'),
        editorial('sources', 'references', 'Come sono state fatte\nLe stime di consumo', 'Tariffe verificate il 12 novembre 2026. Gli esempi sono scenari di preventivo, da affinare con un campione di documenti e richieste reali.', kicker='NOTE E FONTI', items=[
            {'title': 'Unità di riferimento', 'text': 'Documento standard: circa 2.000 parole, cinque pagine di solo testo; ipotesi tecnica circa 3.000 token. La lunghezza reale varia per lingua, impaginazione e contenuto.'},
            {'title': 'Preparazione e domande', 'text': 'Preparazione stimata con embedding e un breve arricchimento economico. Per le domande si sommano testo e risposte di tutti i passaggi: l’ipotesi ordinaria è 10.000 token letti e 1.000 prodotti, quella estesa 30.000 e 3.000. Ricerca e preparazione della domanda sono aggiunte.'},
            {'title': 'Criterio economico', 'text': 'Le stime applicano un fattore di servizio 2,5 al costo AI completo, incluse commissioni pertinenti. Per prudenza si ipotizza 1 USD = 1 EUR, non un cambio corrente. I crediti si misurano anche in frazioni; le fasce pubblicate sono arrotondate per fare budget.'},
            {'title': 'Tariffe dei modelli di risposta', 'text': 'Fonti ufficiali OpenRouter. Esempi di modelli, non classifica di qualità.', 'url': 'https://openrouter.ai/openai/gpt-4.1-mini'},
            {'title': 'Modello economico e modello più costoso', 'text': 'GPT-4o mini e GPT-4.1 nelle rispettive schede tariffarie ufficiali.', 'url': 'https://openrouter.ai/openai/gpt-4.1'},
            {'title': 'Preparazione per la ricerca', 'text': 'Text Embedding 3 Small, tariffa di riferimento per indicizzare il testo.', 'url': 'https://openrouter.ai/openai/text-embedding-3-small'},
            {'title': 'Lettura di scansioni', 'text': 'Mistral OCR 4.1: 4 USD per mille pagine per OCR semplice; annotazioni escluse.', 'url': 'https://docs.mistral.ai/models/ocr-4-1'},
            {'title': 'Perimetro della proposta', 'text': 'AskMyDocs web, funzioni di ricerca e fonti dal catalogo verificato e codice corrente. OCR e modelli vanno configurati. Immagini illustrative della landing; nessuna testimonianza o misura di produttività. Prezzi del servizio, contatori e soglie sono una proposta commerciale da attivare e collaudare.'},
        ]),
        editorial('closing', 'closing', 'Partiamo dal lavoro\nE dalle persone', 'Scegliamo un primo gruppo di documenti, formiamo chi userà lo strumento e misuriamo il consumo su richieste utili.\naskmydocs.surfacesrl.com', kicker='IL PRIMO PASSO', image=art('community', 260), quote='Il credito rende chiaro quanto si utilizza. Il corso e la consulenza aiutano a capire come utilizzarlo bene.'),
    ]
    for p in pages:
        if p['layout'] == 'references':
            for entry in p['items']:
                entry['detail'] = entry.pop('text')
    theme = deepcopy(json.loads((PREV / 'contenuti.json').read_text())['theme'])
    out('brochure.json', {'document': {'title': 'AskMyDocs credito documenti e formazione', 'subject': 'Proposta a consumo con esempi di documenti e domande', 'brand_name': 'AskMyDocs', 'edition': '12 NOVEMBRE 2026 / REV 04', 'header': 'ASKMYDOCS / DOCUMENTI E CONSUMI', 'footer_note': 'Proposta commerciale · IVA esclusa · Stime indicative', 'author': '', 'source_ids': ['CONSUMI-PROPOSTA', 'MODELLO-STIME', 'AMD-BRAND']}, 'theme': theme, 'pages': pages})
    word_pages = [
        page('ricariche', 'AskMyDocs credito documenti e domande', [
            para('Proposta del 12 novembre 2026. Acquistate credito per preparare documenti e fare domande con l’AI. Il saldo è condiviso da tutti gli utenti autorizzati della stessa azienda. Nessun canone mensile del software e nessuna tariffa per persona. Corso e consulenza si quotano separatamente. Importi IVA esclusa.'),
            table(['Ricarica', 'Crediti', 'Esempio combinato con modello intermedio'], [[euro(p['price']), it(p['credits']), f"Circa {it(p['standard_documents'])} documenti standard + {it(p['questions_display'][0])}-{it(p['questions_display'][1])} domande"] for p in PACKS], [.7, .8, 3.6]),
            para(pack_note), h('Che cosa significa documento standard'), para(definition),
            table(['Materiale', 'Equivalenza indicativa'], [['Email di 200 parole', 'Circa 1/10 di documento standard; allegati a parte'], ['PDF testuale di 5 pagine', 'Circa 1 documento standard'], ['Manuale testuale di 50 pagine', 'Circa 10 documenti standard, anche se è un file solo'], ['Scansione di 5 pagine', 'Circa 1 documento standard + lettura OCR']], [2.2, 3.1]),
            para('Una raccolta è un insieme di documenti sullo stesso argomento, per esempio i manuali commerciali. Non è un’unità di fatturazione. I testi vengono preparati per la ricerca una prima volta; a ogni domanda si cercano normalmente i passaggi pertinenti. Aggiornamenti o nuove elaborazioni possono consumare altro credito.'),
            para('Ogni ricarica comprende 12 mesi di accesso e 5 GB condivisi per file e derivati. Il credito è valido 12 mesi; una ricarica prima della scadenza proroga il residuo. Per rinnovare basta una ricarica da 25 €. Nessun rinnovo automatico. Più spazio si quota a parte.'),
        ]),
        page('stime', 'Il modello cambia il consumo', [
            para('Un credito vale 0,01 € di utilizzo e può essere consumato anche in frazioni. I token sono piccole parti del testo elaborato dai modelli: non sono documenti né crediti. Le fasce seguenti sono stime per richieste ordinarie o estese, non un prezzo fisso per qualsiasi domanda.'),
            table(['Modello di esempio', 'Crediti per domanda', 'Spesa indicativa'], [['GPT-4o mini economico', '1-2', '0,01-0,02 €'], ['GPT-4.1 mini intermedio', '2-5', '0,02-0,05 €'], ['GPT-4.1 più costoso', '8-23', '0,08-0,23 €']], [2.5, 1.3, 1.3]),
            para('Le ipotesi sommano tutti i passaggi del modello scelto: circa 10.000-30.000 token letti e 1.000-3.000 prodotti, più preparazione della domanda e ricerca. Più testo, conversazioni lunghe, tentativi aggiuntivi e modelli diversi cambiano i consumi. Si concordano i modelli disponibili all’attivazione.'),
            h('Esempi con documenti diversi'),
            table(['Materiali e domande', 'Preparazione', 'Domande', 'Totale stimato'], [['100 email da 200 parole + 100 domande', '0,02 €', '2-5 €', '2,02-5,02 €'], ['100 PDF testuali di 5 pagine + 500 domande', '0,20 €', '10-25 €', '10,20-25,20 €'], ['20 manuali di 50 pagine + 200 domande', '0,40 €', '4-10 €', '4,40-10,40 €'], ['100 scansioni di 5 pagine + 300 domande', '5,20 € con OCR', '6-15 €', '11,20-20,20 €']], [2.4, .9, .8, 1]),
            para('Scenari illustrativi con modello intermedio. Le scansioni ipotizzano 500 pagine OCR a 1 credito per pagina, più 0,2 crediti per documento standard. Annotazioni, immagini da interpretare e layout difficili possono aumentare la spesa. Le email sono testo esportato e importato, senza promessa di sincronizzazione automatica. Si stima un campione prima di caricare grandi quantità.'),
            h('Da dove vengono le cifre'),
            para('Tariffe API verificate il 12 novembre 2026: GPT-4o mini 0,15/0,60 USD, GPT-4.1 mini 0,40/1,60 USD, GPT-4.1 2/8 USD per milione di token letti/prodotti. Embedding 0,02 USD per milione; OCR 4.1 4 USD per mille pagine. Stime con fattore servizio 2,5 e ipotesi prudenziale 1 USD = 1 EUR, non cambio corrente. Sono calcoli di preventivo, non consumi medi misurati di AskMyDocs.'),
            para('Fonti: openrouter.ai/openai/gpt-4o-mini ; openrouter.ai/openai/gpt-4.1-mini ; openrouter.ai/openai/gpt-4.1 ; openrouter.ai/openai/text-embedding-3-small ; openrouter.ai/pricing ; docs.mistral.ai/models/ocr-4-1'),
        ]),
        page('servizi', 'Corso per gruppo e consulenza a giornata', [
            para('Il corso è un passaggio fondamentale consigliato alle aziende che iniziano. Insegna funzionamento e limiti dell’AI, scelta dei modelli, token e costi, richieste, controllo delle fonti, dati e regole d’uso. Otto ore di lezione con laboratorio, materiali e attestato. I consumi degli esercizi didattici sono compresi.'),
            table(['Corso di otto ore', '1-5 persone', '6-10 persone', '11-15 persone'], [[mode] + [euro(v) for v in prices] for mode, prices in COURSES.items()], [1.6, 1.1, 1.1, 1.1]),
            para('Il prezzo è fisso per il gruppo nella fascia scelta. Il corso online da 900 € costa lo stesso per una o cinque persone; non si fattura un prezzo per partecipante. Capienza massima 15 persone, oltre si organizzano edizioni distinte. Online: quattro incontri da due ore o due da quattro; coworking: due mezze giornate, sala inclusa. Più aziende possono condividere un’edizione con un unico prezzo di gruppo ripartito e preventivo comune. In azienda: una giornata da otto ore o due mezze giornate, con sala tranquilla, schermo, connessione e computer per persona a disposizione del cliente.'),
            h('Consulenza sui flussi aziendali'),
            table(['Giornate', '1', '2', '3', '4', '5'], [['Prezzo'] + [euro(CONSULTING_DAY * i) for i in range(1, 6)]], [.9, .8, .8, .8, .8, .8]),
            para('La consulenza analizza flussi, fonti, documenti, accessi e casi adatti alla ricerca documentale AI detta RAG. Il numero di giornate si stima sulla complessità del lavoro, non sul numero di utenti. Una giornata vale sette ore complessive, comprese preparazione e restituzione; può essere suddivisa in mezze giornate da 425 €, ravvicinate o distribuite.'),
            para('Consegne: mappa dei flussi esaminati, fonti adatte e problemi dei materiali, proposta di accessi, piano di prova con domande campione. Corso base, integrazioni, bonifica massiva e riscrittura dei manuali restano separati. I consumi sui documenti aziendali sono addebitati al credito.'),
            para('Trasferta locale: 250 € per visita entro 50 km e massimo due ore A/R. Due mezze giornate in sede comportano due visite, quindi 500 €. Trasferte ulteriori da quotare prima dell’ordine. Le giornate di consulenza in sede aggiungono le proprie visite.'),
        ]),
        page('preventivi', 'Preventivi di avvio e condizioni', [
            table(['Voce', 'Primo utilizzo', 'Reparto', 'Diffusione'], [['Ricarica AskMyDocs', '25 €', '50 €', '100 €'], ['Corso 8 ore', '900 € online 1-5 persone', '1.700 € coworking 6-10', '1.800 € in sede 11-15'], ['Consulenza online', '850 € per 1 giornata', '2.550 € per 3 giornate', '4.250 € per 5 giornate'], ['Trasferte corso', '0 €', '0 €', '500 € per 2 visite locali'], ['Totale IVA esclusa', '1.775 €', '4.300 €', '6.650 €']], [1.25, 1.3, 1.3, 1.3]),
            para('Il terzo esempio prevede il corso in due mezze giornate in sede e la consulenza online. Le due trasferte sono del solo corso. Sono combinazioni esemplificative: il credito può essere acquistato da solo, senza lezioni o analisi dei flussi comprese. Per le aziende all’inizio consigliamo il corso prima dell’uso operativo.'),
            h('Condizioni del servizio'),
            para('Ricariche prepagate da 25, 50 o 100 €, condivise da tutti gli utenti autorizzati della stessa azienda. Ogni ricarica comprende 12 mesi di accesso al servizio condiviso e 5 GB per file e derivati. Credito valido 12 mesi, prorogato sul residuo se si ricarica prima della scadenza. Per rinnovare basta una ricarica da 25 €. Nessun rinnovo automatico. Maggiore spazio o infrastrutture dedicate richiedono un preventivo.'),
            para('Caricamento senza elaborazione, lettura ed esportazione non consumano credito AI. Nuovi documenti, indicizzazione, OCR, domande, modifiche e rigenerazioni possono consumarlo. Prima dell’operazione si comunica una stima o un tetto, senza superamento del tetto senza autorizzazione. A credito esaurito si fermano nuove operazioni AI; i file restano leggibili durante il periodo di accesso. Esportazione concordata entro 30 giorni dalla scadenza.'),
            para('Assistenza tecnica nei feriali tramite referente aziendale; lezioni e lavoro sul caso sono servizi professionali separati. Elaborazioni con risorse condivise e code, fino a due operazioni AI contemporanee per azienda. Provider, modelli e trattamento dati concordati prima del caricamento reale. OCR remoto da attivare specificamente. Questa proposta non comprende integrazioni o certificazioni dei risultati.'),
            para('Corso e consulenza: 50% all’ordine e 50% a completamento, calendario concordato con conclusione entro 90 giorni salvo diverso accordo. Trasferte preventivate. Validità della proposta fino al 12 dicembre 2026. Tutti gli importi sono IVA esclusa.'),
            para('Cliente ____________________  Ricarica ____________________\nCorso e fascia ____________________  Giornate consulenza __________\nSede e calendario ____________________  Totale ____________________'),
        ]),
    ]
    docx = HERE / 'AskMyDocs_listino_consumi.docx'
    helpers.make_doc(word_pages, docx, 'AskMyDocs listino consumi revisione 04')
    word = helpers.Document(docx)
    word.core_properties.subject = 'Credito a consumo con esempi di documenti e domande corso a gruppo consulenza a giornata'
    word.core_properties.keywords = 'AskMyDocs, crediti, documenti, modelli, corso per gruppo, consulenza'
    word.save(docx)
    note = ['ASKMYDOCS - NOTA INTERNA REVISIONE 04', '12 novembre 2026', '',
        'CORREZIONE DEL MODELLO COMMERCIALE',
        'I canoni della revisione 03 non sono più la proposta. Il costo della piattaforma è condiviso: non si attribuisce tutto il budget Cloud a ogni azienda. Tre ricariche da 25/50/100 euro, interamente in credito, utenti illimitati; niente canone mensile. Il minimo per rinnovare l’accesso annuale è una ricarica da 25 euro. Corso a gruppo e consulenza a giornata separati.',
        '', 'STIME DI UTILIZZO, NON CONSUMI MEDI OSSERVATI',
        'Un documento standard è una quantità indicativa di testo, circa 2.000 parole o cinque pagine; nel calcolo si ipotizzano 3.000 token. La preparazione usa embedding e un arricchimento economico ipotizzato: 0,19 crediti calcolati, arrotondati a 0,2 per budget. Non è costo fisso di qualsiasi file.',
        'La domanda ordinaria/estesa somma TUTTE le chiamate del modello selezionato fino a 10.000/30.000 token input e 1.000/3.000 output. Preprocessore e query embedding sono aggiunti. Se il flusso usa più token, costa di più. Cache e promozioni non assunte.',
        'Il prezzo tecnico completo in euro è moltiplicato per 2,5. Commissione di acquisto OpenRouter del 5,5% inclusa una volta; nessuna commissione OpenRouter sul servizio Mistral diretto. Cambio 1 USD = 1 EUR solo come ipotesi di preventivo; sostituire con cambio effettivo di acquisto.',
        'Si contabilizzano centesimi di credito, non un credito minimo per ciascun file. Questo evita che molte email brevi costino quanto tanti manuali lunghi.',
        '', 'SOSTENIBILITÀ DELLA COMPONENTE A CONSUMO',
        'Ipotesi: provvigione commerciale 10%, incasso 2,5%, riserva 10% sul costo tecnico. Con fattore 2,5 resta contribuzione minima del 43,5% sui crediti realmente consumati, prima dei costi condivisi. Il prepagato residuo non è considerato profitto.',
        'Budget condiviso di lancio 1.200 euro/anno: 900 Cloud e 300 manutenzione/operazioni, più 6 euro per azienda/anno per spazio e servizio incrementali. Sono budget da verificare sotto carico, non prezzi quotati né costo per singola azienda. Il Cloud osservato (48,66 USD main prudenziale, 73,08 tutti gli ambienti nel periodo) è nell’audit precedente.',
        'Pareggio solo della componente a consumo, senza usare ricavi di corsi/consulenza:']
    for c in model['shared_fixed_break_even_scenarios']:
        note.append(f"- {c['annual_consumed_credit_revenue_per_company']} euro di credito CONSUMATO per azienda/anno: circa {c['companies_for_shared_fixed_break_even']} aziende per coprire il budget condiviso.")
    note += ['Non dichiarare che una ricarica annua da 25 euro sostiene da sola tutto il servizio. Con basso utilizzo serve una base clienti ampia, o margine dei servizi professionali a copertura dei costi di lancio. Assistenza personale, giornate e lezioni non sono illimitate comprese nel credito.',
        '', 'SERVIZI PROFESSIONALI',
        'Corso: 8 ore live più 2 di preparazione, costo orario caricato ipotetico 55 euro; materiali/piattaforma e sala modellati separatamente. La sala è una stima senza preventivo del coworking. Lezioni a prezzo fisso per fascia 1-5, 6-10, 11-15, anche con aziende raggruppate: un unico prezzo di edizione, non il totale corso a ciascuna azienda.',
        'Consulenza: 850 euro per giornata di 7 ore complessive, comprese preparazione e restituzione. Da una a cinque giornate, divisibili in mezze giornate da 425 euro. Trasferte a parte; non promettere un numero fisso di flussi analizzati in ciascuna giornata.',
        '', 'CONTROLLI PRIMA DELL’ATTIVAZIONE', *model['limits'],
        'Configurare e collaudare ricariche, saldo condiviso e frazioni, prenotazione massimale, tutte le chiamate per richiesta, scadenza/proroga credito e accesso, quota spazio e code. In questa attività non è stato cambiato il software né il sito.',
        '', 'PREVENTIVI', 'Primo utilizzo 25 + 900 + 850 = 1.775 euro; reparto 50 + 1.700 + 2.550 = 4.300 euro; diffusione 100 + 1.800 + 4.250 + 500 = 6.650 euro, IVA esclusa.',
        'La revisione 04 sostituisce soltanto AskMyDocs. Non riutilizzare i vecchi totali dei pacchetti congiunti prima di ricalcolarli.']
    (HERE / 'Nota_stime_interna.txt').write_text('\n'.join(note) + '\n')
    (HERE / 'Per_Lorenzo.txt').write_text('''ASKMYDOCS - OFFERTA A CONSUMO, REVISIONE 04
12 novembre 2026 - tutti gli importi IVA esclusa

Software: ricariche da 25, 50 e 100 euro, pari a 2.500, 5.000 e 10.000 crediti condivisi dall'azienda. Nessuna tariffa per utente e nessun canone mensile. Ogni ricarica comprende 12 mesi di accesso e 5 GB; il credito vale 12 mesi e una ricarica prima della scadenza proroga il residuo. Il minimo per rinnovare è 25 euro in credito.

Esempio: con 25 euro si possono preparare circa 500 documenti standard e fare circa 450-1.200 domande con GPT-4.1 mini. Un documento standard significa indicativamente cinque pagine di testo, circa 2.000 parole: non equivale a un file di qualsiasi dimensione. Email brevi valgono meno, manuali lunghi di più; scansioni e immagini richiedono lavorazioni aggiuntive. Documenti e domande consumano lo stesso saldo. Sono stime, dipendenti da contenuto e modello.

Corso: otto ore, prezzo fisso per gruppo nelle fasce 1-5, 6-10 e 11-15 persone. Online 900/1.200/1.500 euro; coworking 1.300/1.700/2.100; in azienda 1.200/1.500/1.800 più trasferta. Non moltiplicare il costo di gruppo per i partecipanti. Aziende riunite nella stessa edizione condividono un solo prezzo complessivo, ripartito nel preventivo.

Consulenza: da una a cinque giornate, 850 euro per giornata di sette ore complessive, anche divisibili. Serve per capire i flussi aziendali, le fonti, i permessi e che cosa conviene rendere consultabile con l'AI. Corso e consulenza sono separati dal credito.

Primo utilizzo fino a cinque persone: 25 euro credito + 900 euro corso online + 850 euro consulenza = 1.775 euro + IVA. Nel PDF e nel Word ci sono gli esempi completi e le condizioni. Proposta valida fino al 12 dicembre 2026; tariffazione da configurare prima del lancio.

Questa revisione sostituisce la precedente proposta AskMyDocs da 149/299/599 euro al mese. Gli altri prodotti restano invariati.
''')
    print(json.dumps({'pdf_pages': len(pages), 'word_pages': len(word_pages), 'output': str(HERE)}, ensure_ascii=False))

if __name__ == '__main__':
    build()
