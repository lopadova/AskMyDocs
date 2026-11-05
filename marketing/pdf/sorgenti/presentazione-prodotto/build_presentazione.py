"""Build a product presentation using the approved October editorial layout.

Reuses its font registrations, colors, geometry, paragraph and table rendering.
Reads only versioned product sources and the local editorial evidence files.
"""
from __future__ import annotations

import ast
import base64
import csv
import html
import importlib.util
import json
import re
import subprocess
import sys
from io import BytesIO
from pathlib import Path

from PIL import Image
from reportlab.lib.utils import ImageReader
from reportlab.pdfgen import canvas

from contenuti_prodotto import (AVAILABILITY, CASE_LAYOUT, CASES, GUIDE, OPENING,
                               PILOT, REFERENCES, REVISION, ROOT, TITLE)

BASE_SOURCE = ROOT / "marketing/pdf/sorgenti/ottobre-2026"
sys.path.insert(0, str(BASE_SOURCE))
spec = importlib.util.spec_from_file_location("october_editorial_layout", BASE_SOURCE / "build_report.py")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
P = base.para
W, H, M, CW, GAP = base.W, base.H, base.M, base.CW, base.GAP
INK, GREEN, CREAM, PAPER = base.INK, base.GREEN, base.CREAM, base.PAPER
MUTED, LINE, RUST, PALE = base.MUTED, base.LINE, base.RUST, base.PALE
PDF = ROOT / "marketing/pdf/askmydocs-presentazione-prodotto.pdf"
HTML = ROOT / "marketing/pdf/askmydocs-presentazione-prodotto.html"
EVIDENCE = ROOT / "marketing/fonti/presentazione-prodotto"
ANALYSIS = ROOT / "marketing/analisi/presentazione-prodotto-2026-10-29"
CATALOG = json.loads((ANALYSIS / "inventario-candidato.json").read_text())
SECTIONS = json.loads((ANALYSIS / "sezioni-app.json").read_text())["sections"]
FEATURES = {f["id"]: f for f in CATALOG["features"]}
CASE_CONDITIONS = [
    "Import, tool live ed eventuali schede dei casi devono essere compatibili e abilitati. Un ticket chiuso non prova l'esito dell'intervento.",
    "Servono endpoint e mapping verificati. Le chiamate descritte leggono i dati; i tool composti richiedono un'attivazione specifica.",
    "Account e cartelle devono essere autorizzati e importati. Il triage è opzionale; la risposta descritta non invia messaggi al cliente.",
    "Servono fonti curate, stati editoriali e collegamenti corretti. Le pagine automatiche restano distinte dai documenti approvati.",
    "Servono client compatibile, origine raggiungibile e consenso sul progetto. L'import documentale richiede conferma sul set di file preparato.",
]


def art(c, path, x, y, width, height):
    """Layout crop with JPEG compression; original asset remains unchanged."""
    im = Image.open(ROOT / path).convert("RGB")
    iw, ih = im.size
    scale = max(width/iw, height/ih)
    dw, dh = iw*scale, ih*scale
    jpeg = BytesIO()
    im.save(jpeg, format="JPEG", quality=90, optimize=True)
    jpeg.seek(0)
    c.saveState()
    clip = c.beginPath()
    clip.rect(x, y, width, height)
    c.clipPath(clip, stroke=0)
    c.drawImage(ImageReader(jpeg), x+(width-dw)/2, y+(height-dh)/2, dw, dh)
    c.restoreState()


def frame(c, name, number):
    base.rect(c, 0, 0, W, H, PAPER)
    base.label(c, "ASKMYDOCS   /   PRESENTAZIONE DEL PRODOTTO", M, H-31, 8, INK)
    c.setStrokeColor(LINE)
    c.setLineWidth(.5)
    c.line(M, H-45, W-M, H-45)
    c.line(M, 43, W-M, 43)
    base.label(c, name.upper(), M, 26, 7.3, INK)
    c.setFillColor(INK)
    c.setFont("Editorial", 10)
    c.drawRightString(W-M, 25, f"{number:02d}")
    P(c, "EDIZIONE OTTOBRE 2026   /   Le funzioni opzionali dipendono dalla configurazione dell'installazione.",
      M, 65, W-2*M, size=7.15, leading=10, color=MUTED, bottom=44)
    c.bookmarkPage(f"pagina-{number}")
    c.addOutlineEntry(name, f"pagina-{number}", level=0, closed=False)


def article(c, a, number):
    frame(c, a["kicker"].split(" / ")[-1], number)
    y = base.page_title(c, a["kicker"], a["title"], a["deck"])
    has_strip = bool(a.get("image_strip"))
    minimum = 218 if has_strip else 198
    kwargs = {"size":10.3,"leading":15,"min_bottom":minimum}
    if a.get("layout") == "essentials":
        kwargs.update(size=10.1, leading=14.6)
    base.blocks(c, a["left"], M, y, CW, **kwargs)
    base.blocks(c, a["right"], M+CW+GAP, y, CW, **kwargs)
    if has_strip:
        art(c, a["image_strip"], M, 93, W-2*M, 105)
        P(c, "Illustrazione editoriale del prodotto. Le scene non rappresentano clienti o schermate reali.",
          M, 83, W-2*M, size=7.5, leading=10, color=MUTED, bottom=69)
    else:
        base.callout(c, a["callout"])
    c.showPage()


def cover(c):
    base.rect(c, 0, 0, W, H, CREAM)
    base.image_fit(c, ROOT / "public/brand/askmydocs-wordmark-light.png", M-3, H-98, 190, 55)
    base.label(c, "PRESENTAZIONE DEL PRODOTTO", W-226, H-58, 8.0, GREEN)
    base.label(c, "EDIZIONE OTTOBRE 2026", W-226, H-74, 8.0, GREEN)
    P(c, "AskMyDocs.", M, H-132, W-2*M, font="Editorial", size=55, leading=66, color=GREEN)
    P(c, "Il sapere che\nentra nel lavoro.", M, H-204, W-2*M, font="Editorial", size=39, leading=44)
    P(c, "Documenti, email e sistemi aziendali.\nRisposte da comprendere e verificare.", M+2, H-313,
      450, size=13.3, leading=19, color=MUTED)
    art(c, "public/editorial/knowledge-day.webp", 0, 66, W, 382)
    base.rect(c, 0, 0, W, 66, GREEN)
    base.label(c, "CONOSCENZA, FONTI E PERSONE", M, 38, 9.5, CREAM)
    base.label(c, "VISIONE  /  CASI D'USO  /  GUIDA ALLE FUNZIONALITÀ", M, 21, 7.5, CREAM)
    c.bookmarkPage("copertina")
    c.addOutlineEntry("AskMyDocs / Presentazione", "copertina", 0)
    c.showPage()


def journey(c, a, number):
    frame(c, "Dalla domanda alle fonti", number)
    y = base.page_title(c, a["kicker"], a["title"], a["deck"])
    for index, title, text in a["steps"]:
        base.rect(c, M, y-33, 36, 36, PALE)
        P(c, index, M+6, y-2, 30, font="Editorial", size=17, leading=20, color=GREEN)
        P(c, title, M+54, y+2, W-2*M-54, font="Editorial", size=16, leading=21)
        P(c, text, M+54, y-27, W-2*M-54, size=10.6, leading=15)
        y -= 76
    base.callout(c, a["callout"], y=89, height=77)
    c.showPage()


def case_page(c, use_case, layout, index, number):
    frame(c, "Caso d'uso / " + use_case["subtitle"], number)
    y = base.page_title(c, f"CASO / {index:02d} / SCENARIO DIMOSTRATIVO", layout["title"],
                        '<b>La domanda.</b> ' + use_case["question"])
    image_h = 152
    art(c, layout["image"], M, y-image_h, W-2*M, image_h)
    P(c, use_case["subtitle"] + ". Illustrazione narrativa; scenario dimostrativo, non una testimonianza cliente.",
      M, y-image_h-10, W-2*M, size=7.6, leading=10, color=MUTED, bottom=74)
    top = y-image_h-43
    editorial = use_case["editorial"]
    P(c, "Il lavoro da ricostruire", M, top, CW, font="Editorial", size=14.8, leading=19)
    P(c, editorial["opening"] + "<br/><br/>" + editorial["mechanism"], M, top-29, CW,
      size=10.2, leading=14.7, bottom=98)
    x=M+CW+GAP
    P(c, "Che cosa ottiene il team", x, top, CW, font="Editorial", size=14.8, leading=19)
    P(c, editorial["payoff"] + "<br/><br/><b>Condizioni.</b> " + CASE_CONDITIONS[index-1], x, top-29, CW,
      size=10.0, leading=14.4, bottom=89)
    c.showPage()


def divider(c):
    frame(c, "Guida alle funzionalità", 13)
    base.label(c, "SECONDA PARTE", M, H-100, color=RUST)
    P(c, "Le funzionalità.\nSezione per sezione.", M, H-139, W-2*M,
      font="Editorial", size=37, leading=43)
    P(c, "Un percorso negli ambienti del prodotto: dal lavoro quotidiano alla cura delle fonti, fino al governo dell'installazione.",
      M, H-245, W-2*M, size=12, leading=18, color=MUTED)
    art(c, "public/editorial/processes-day.webp", M, 190, W-2*M, 270)
    P(c, "47 sezioni dell'interfaccia e i moduli aggiuntivi sono ricondotti alle capacità descritte nelle pagine che seguono.",
      M, 152, W-2*M, font="Editorial-Italic", size=12, leading=17)
    c.showPage()


def availability(c):
    frame(c, "Disponibilità e configurazione", 30)
    y = base.page_title(c, "DAL PRODOTTO ALL'INSTALLAZIONE", "Ogni capacità,\nle sue condizioni.",
        "Il perimetro descritto è quello del prodotto alla revisione verificata. Le funzioni effettivamente disponibili dipendono da configurazione e servizi collegati.")
    bottom = base.draw_table(c, ["CAPACITÀ", "CONDIZIONI DI UTILIZZO"], AVAILABILITY,
                            [152, W-2*M-152], M, y+4, font=8.8, padding=6, bottom=123)
    P(c, "La configurazione va verificata nell'ambiente destinatario. Le integrazioni dei sistemi aziendali e le revisioni dei pacchetti determinano la copertura; il prodotto non implica un accesso universale alle fonti.",
      M, bottom-17, W-2*M, size=8.7, leading=12.5, color=MUTED, bottom=77)
    c.showPage()


def references(c):
    frame(c, "Note dell'edizione", 31)
    y = base.page_title(c, "FATTI, SCENARI E FONTI", "Una presentazione\ncon una base verificabile.",
        "I casi d'uso illustrano percorsi possibili. Non sono testimonianze, progetti cliente certificati o misure di risparmio.")
    y=P(c, "L'edizione descrive le capacità implementate nello snapshot di prodotto del 29 ottobre 2026. I moduli opzionali sono indicati con le loro condizioni. Le illustrazioni hanno funzione narrativa; non sono schermate né prove di un'esecuzione reale.",
        M,y,W-2*M,size=10.3,leading=15)
    y-=28
    for column in range(2):
        cy=y
        x=M+column*(CW+GAP)
        for title,path in REFERENCES[column*6:(column+1)*6]:
            url=f"https://github.com/lopadova/AskMyDocs/blob/{REVISION}/{path}"
            cy=P(c,f'<link href="{url}" color="#224c40">{html.escape(title)}</link>',x,cy,CW,
                 font="Editorial",size=11.3,leading=15)
            cy=P(c,html.escape(path),x,cy-5,CW,size=7.8,leading=10.7,color=MUTED)
            cy-=19
    base.callout(c,"La versione HTML include la mappa delle sezioni, il catalogo delle capacità e i riferimenti alle fonti della presentazione.",height=72)
    c.showPage()


def closing(c):
    frame(c, "Il prossimo passo", 32)
    base.label(c,"ASKMYDOCS / IL SAPERE CHE ENTRA NEL LAVORO",M,H-92,color=RUST)
    P(c,"Partiamo da una\ndomanda che conta.",M,H-125,W-2*M,font="Editorial",size=36,leading=43)
    P(c,"Scegliete un compito ricorrente. Raccogliete le fonti ammesse. Individuate chi può valutarle. Poi seguite la risposta fino alla sua prova.",
      M,H-237,W-2*M,size=13,leading=20,color=MUTED)
    art(c,"public/editorial/connections-night.webp",M,168,W-2*M,260)
    P(c,"Il vostro patrimonio è ciò che l'organizzazione ha imparato.\nIl progetto è renderlo utilizzabile, ogni giorno.",
      M,137,W-2*M,font="Editorial-Italic",size=12.5,leading=18)
    c.showPage()


def source_paths(feature_ids):
    return sorted({e["path"] for f in feature_ids for e in FEATURES[f].get("evidence",[])})


def coverage():
    feature_ids={f for p in OPENING+[PILOT]+GUIDE for f in p["feature_ids"]}
    feature_ids.update(f for l in CASE_LAYOUT for f in l['features'])
    active={f['id'] for f in CATALOG['features'] if f['status']=='active'}
    sections={s for p in GUIDE for s in p['section_ids']}
    expected={s['id'] for s in SECTIONS}
    assert not (active-feature_ids), f"Uncovered features: {active-feature_ids}"
    assert sections==expected, f"Missing sections: {expected-sections}; unexpected: {sections-expected}"
    for _,path in REFERENCES:
        subprocess.run(['git','-C',str(ROOT),'cat-file','-e',REVISION+':'+path],check=True,capture_output=True)
    pages=[]
    for n,p in enumerate(OPENING,2):
        pages.append({'page':n,'title':p['title'].replace('\n',' '),'feature_ids':p['feature_ids'],'source_paths':source_paths(p['feature_ids'])})
    for n,(case,layout) in enumerate(zip(CASES,CASE_LAYOUT),7):
        pages.append({'page':n,'title':layout['title'].replace('\n',' '),'use_case_id':case['id'],'feature_ids':layout['features'],'source_paths':source_paths(layout['features'])})
    pages.append({'page':12,'title':PILOT['title'].replace('\n',' '),'feature_ids':PILOT['feature_ids'],'source_paths':source_paths(PILOT['feature_ids'])})
    for n,p in enumerate(GUIDE,14):
        pages.append({'page':n,'title':p['title'].replace('\n',' '),'feature_ids':p['feature_ids'],'section_ids':p['section_ids'],'source_paths':source_paths(p['feature_ids'])})
    result={'revision':REVISION,'implemented_features_covered':len(active),'sidebar_sections_covered':len(sections),'excluded_non_operational_features':['F-059','F-060'],'pages':pages}
    (EVIDENCE/'mappa-contenuti.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    return result


def pdf():
    base.LAYOUT.clear()
    c=canvas.Canvas(str(PDF),pagesize=(W,H),pageCompression=1)
    c.setTitle(TITLE)
    c.setAuthor("AskMyDocs / Presentazione del prodotto")
    c.setSubject("Visione, casi d'uso e guida alle funzionalità")
    cover(c)
    for number,a in enumerate(OPENING,2):
        journey(c,a,number) if a.get('layout')=='journey' else article(c,a,number)
    for index,(case,layout) in enumerate(zip(CASES,CASE_LAYOUT),1):
        case_page(c,case,layout,index,index+6)
    article(c,PILOT,12)
    divider(c)
    for number,a in enumerate(GUIDE,14):
        article(c,a,number)
    availability(c)
    references(c)
    closing(c)
    assert c.getPageNumber()==33
    c.save()
    (EVIDENCE/'layout-verifica.json').write_text(json.dumps(base.LAYOUT,ensure_ascii=False,indent=2)+'\n')


def data_image(path):
    im=Image.open(ROOT/path)
    if im.mode=='RGBA':
        buffer=BytesIO();im.save(buffer,format='PNG');mime='image/png'
    else:
        buffer=BytesIO();im.convert('RGB').save(buffer,format='JPEG',quality=90,optimize=True);mime='image/jpeg'
    return 'data:'+mime+';base64,'+base64.b64encode(buffer.getvalue()).decode()


def html_blocks(items):
    return ''.join(f'<div class="block"><h3>{html.escape(b["title"])}</h3><p>{b["text"]}</p></div>' for b in items)


def html_article(a,id):
    result=f'<section class="chapter" id="{id}"><span class="eyebrow">{a["kicker"]}</span><h2>{a["title"].replace(chr(10)," ")}</h2><p class="deck">{a["deck"]}</p>'
    if a.get('layout')=='journey':
        result+='<div class="journey">'+''.join(f'<div class="step"><b>{i}</b><div><h3>{title}</h3><p>{text}</p></div></div>' for i,title,text in a['steps'])+'</div>'
    else:
        result+='<div class="cols"><div>'+html_blocks(a['left'])+'</div><div>'+html_blocks(a['right'])+'</div></div>'
    result+=f'<aside class="callout">{a["callout"]}</aside>'
    if a.get('image_strip'):
        result+=f'<img class="chapter-art" src="{data_image(a["image_strip"])}" alt="Illustrazione umanistica: persone e conoscenza condivisa"><p class="caption">Illustrazione editoriale; non una testimonianza o schermata reale.</p>'
    return result+'</section>'


def html_document(mapping):
    tree=ast.parse((BASE_SOURCE/'build_report.py').read_text())
    func=next(n for n in tree.body if isinstance(n,ast.FunctionDef) and n.name=='html_report')
    assign=next(n for n in func.body if isinstance(n,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='css' for t in n.targets))
    css=ast.literal_eval(assign.value)
    css+='''
    .chapter-art{display:block;width:100%;max-height:330px;object-fit:cover;margin:30px 0 10px}.case-art{display:block;width:100%;max-height:440px;object-fit:cover;margin:28px 0 10px}.journey{margin-top:35px}.step{display:flex;gap:25px;margin:26px 0;align-items:flex-start}.step>b{background:var(--pale);padding:12px;font:25px Georgia,serif;color:var(--green)}.step p{margin:0}.section-map td:last-child{min-width:220px}.function-card{border-top:1px solid var(--line);padding:22px 0}.function-card h3{font-size:21px}.function-card p{font-size:15px}.function-card details{font-size:13px}.colophon{font-size:13px;color:var(--muted)}@media(max-width:700px){.masthead .eyebrow{letter-spacing:.8px}.case-art,.chapter-art{max-height:280px}}
    '''
    parts=[f'<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{html.escape(TITLE)}</title><style>{css}</style></head><body>',
        f'<header class="cover"><div class="masthead"><img class="brand" src="{data_image("public/brand/askmydocs-wordmark-light.png")}" alt="AskMyDocs"><div class="eyebrow">Presentazione del prodotto<br>Edizione ottobre 2026</div></div><div class="hero"><span class="eyebrow">Visione / Casi d\'uso / Funzionalità</span><h1>AskMyDocs.<br>Il sapere che<br>entra nel lavoro.</h1><p>Documenti, email e sistemi aziendali.<br>Risposte da comprendere e verificare.</p><div class="downloads"><a href="askmydocs-presentazione-prodotto.pdf">Presentazione PDF</a><a href="#panoramica">Il prodotto in una pagina</a><a href="#guida">Guida alle funzionalità</a></div></div><img class="cover-art" src="{data_image("public/editorial/knowledge-day.webp")}" alt="Illustrazione umanistica AskMyDocs: due persone condividono la conoscenza in una biblioteca"><div class="hero-meta"><div><b>Conoscenza, fonti e persone</b><span>Una presentazione del prodotto, dal valore alle funzioni</span></div></div></header><main>']
    for i,a in enumerate(OPENING,2):
        parts.append(html_article(a,'panoramica' if i==2 else 'introduzione-'+str(i)))
        if i==2:
            parts.append('<nav class="chapter" aria-label="Indice della presentazione"><h3>Il percorso di lettura</h3><ol class="toc"><li><a href="#caso-1">Assistenza e memoria dei casi</a></li><li><a href="#caso-2">Ordini e dati correnti</a></li><li><a href="#caso-3">Archivio email e triage</a></li><li><a href="#caso-4">Onboarding e decisioni</a></li><li><a href="#caso-5">Lavorare dai client AI</a></li><li><a href="#prova">Il primo progetto</a></li><li><a href="#guida">Le funzionalità, sezione per sezione</a></li><li><a href="#disponibilita">Condizioni di utilizzo</a></li></ol></nav>')
    for index,(case,layout) in enumerate(zip(CASES,CASE_LAYOUT),1):
        e=case['editorial']
        parts.append(f'<section class="chapter" id="caso-{index}"><span class="eyebrow">Caso {index:02d} / Scenario dimostrativo</span><h2>{layout["title"].replace(chr(10)," ")}</h2><p class="deck"><b>La domanda.</b> {case["question"]}</p><img class="case-art" src="{data_image(layout["image"])}" alt="Illustrazione narrativa: {html.escape(case["subtitle"],quote=True)}"><p class="caption">{case["subtitle"]}. Scenario dimostrativo, non una testimonianza cliente.</p><div class="cols"><div><h3>Il lavoro da ricostruire</h3><p>{e["opening"]}</p><p>{e["mechanism"]}</p></div><div><h3>Che cosa ottiene il team</h3><p>{e["payoff"]}</p><p><b>Condizioni.</b> {CASE_CONDITIONS[index-1]}</p></div></div><aside class="callout">{case["practical_value"]}</aside></section>')
    parts.append(html_article(PILOT,'prova'))
    parts.append('<section class="chapter" id="guida"><span class="eyebrow">Seconda parte</span><h2>Le funzionalità. Sezione per sezione.</h2><p class="deck">Un percorso negli ambienti del prodotto: dal lavoro quotidiano alla cura delle fonti, fino al governo dell\'installazione.</p><ol class="toc">')
    for n,a in enumerate(GUIDE,1):
        parts.append(f'<li><a href="#funzioni-{n}"><small>{n:02d}</small>{a["kicker"].split(" / ")[-1].capitalize()}</a></li>')
    parts.append('</ol></section>')
    for n,a in enumerate(GUIDE,1):parts.append(html_article(a,'funzioni-'+str(n)))
    parts.append('<section class="chapter" id="disponibilita"><span class="eyebrow">Dal prodotto all\'installazione</span><h2>Ogni capacità, le sue condizioni.</h2><p class="deck">Le capacità implementate e le funzioni disponibili nell\'ambiente operativo vanno distinte. Configurazione, servizi collegati e revisioni dei pacchetti ne definiscono il perimetro.</p>'+base.html_table(['Capacità','Condizioni di utilizzo'],AVAILABILITY)+'</section>')
    section_rows=[]
    for section in SECTIONS:
        page=next(p for p in mapping['pages'] if section['id'] in p.get('section_ids',[]))
        section_rows.append((section['group_label'],section['label'],page['page'],page['title']))
    parts.append('<section class="chapter" id="mappa-sezioni"><span class="eyebrow">Indice di consultazione</span><h2>Le sezioni dell\'interfaccia.</h2><p class="deck">47 voci ricondotte alle pagine della presentazione. La visibilità effettiva segue ruoli, permessi e capacità dell\'installazione.</p>'+base.html_table(['Gruppo','Sezione','Pagina PDF','Tema'],section_rows)+'</section>')
    parts.append('<section class="chapter" id="catalogo"><span class="eyebrow">Catalogo verificabile</span><h2>Trovare una capacità.</h2><p class="deck">58 capacità implementate, comprese quelle opzionali. Le fonti sono fissate alla revisione del prodotto usata per questa edizione.</p><div class="filters"><label>Cerca nel catalogo<input id="feature-query" type="search" placeholder="Per esempio: email, permessi, OCR, report"></label></div><p id="feature-count" class="result-count" aria-live="polite"></p><div id="features">')
    for f in CATALOG['features']:
        if f['status']!='active':continue
        search=html.escape(' '.join([f['name'],f['summary'],f['id']]).casefold(),quote=True)
        parts.append(f'<article class="function-card" data-search="{search}"><h3>{html.escape(f["name"])}</h3><p>{html.escape(f["summary"])}</p><details><summary>Fonti e condizioni</summary>')
        limits=f.get('limits',[])
        if limits:parts.append('<p>'+html.escape(' '.join(limits))+'</p>')
        for e in f.get('evidence',[]):
            url=f'https://github.com/lopadova/AskMyDocs/blob/{e.get("revision",REVISION)}/{e["path"]}'
            parts.append(f'<p><a href="{url}">{html.escape(e["path"])}</a></p>')
        parts.append('</details></article>')
    parts.append('</div></section><section class="chapter"><span class="eyebrow">Note dell\'edizione</span><h2>Una base verificabile.</h2><p>I casi d\'uso sono dimostrativi. Non sono testimonianze cliente o risultati misurati. Le illustrazioni hanno funzione narrativa e non rappresentano schermate o prove di un\'esecuzione reale.</p><p>Lo snapshot di prodotto è il commit <code>'+REVISION+'</code> del 29 ottobre 2026. Il catalogo censisce le capacità implementate e ne conserva prerequisiti e limiti. I lavori non operativi sul consumer, fra cui Zendesk e il trasporto PACT, sono esclusi dalla presentazione delle capacità disponibili.</p><p class="colophon">L\'analisi usa codice e documentazione versionata. Non sono stati attivati account o integrazioni, eseguiti test applicativi o collaudi live per questo documento. La lettura del sito pubblico non è stata completata: il posizionamento si basa sui materiali del progetto verificati nel repository.</p></section>')
    parts.append('<section class="chapter"><span class="eyebrow">Il prossimo passo</span><h2>Partiamo da una domanda che conta.</h2><p class="deck">Scegliete un compito ricorrente. Raccogliete le fonti ammesse. Individuate chi può valutarle. Poi seguite la risposta fino alla sua prova.</p><img class="chapter-art" src="'+data_image('public/editorial/connections-night.webp')+'" alt="Illustrazione di archivi collegati da ponti"><aside class="callout">Il vostro patrimonio è ciò che l\'organizzazione ha imparato. Il progetto è renderlo utilizzabile, ogni giorno.</aside></section></main><footer>AskMyDocs / Presentazione del prodotto / Edizione ottobre 2026<br>Visione, cinque casi d\'uso e guida alle funzionalità.<br>Illustrazioni: asset editoriali AskMyDocs e due nuove scene generate con image_gen; scenari dimostrativi.</footer><script>const q=document.getElementById("feature-query"),cards=[...document.querySelectorAll(".function-card")],count=document.getElementById("feature-count");function filter(){let n=0;const value=q.value.toLowerCase().trim();for(const card of cards){card.hidden=value&&!card.dataset.search.includes(value);if(!card.hidden)n++;}count.textContent=n+" di "+cards.length+" capacità visualizzate";}q.addEventListener("input",filter);filter();</script></body></html>')
    HTML.write_text(''.join(parts))


if __name__=='__main__':
    EVIDENCE.mkdir(parents=True,exist_ok=True)
    mapping=coverage()
    pdf()
    html_document(mapping)
    print(json.dumps({'pdf':str(PDF),'html':str(HTML),'pages':32,'implemented_features':mapping['implemented_features_covered'],'sidebar_sections':mapping['sidebar_sections_covered'],'cases':5},ensure_ascii=False))
