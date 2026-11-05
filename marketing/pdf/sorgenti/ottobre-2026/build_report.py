"""Render the frozen October editorial report as PDF and self-contained HTML.

Usage: the bundled Python runtime + this script. Does not access the network,
the application database, credentials, or any mailbox.
"""
from __future__ import annotations

import base64
import csv
import html
import json
import math
import re
import subprocess
from collections import Counter
from datetime import datetime
from io import BytesIO
from pathlib import Path
from zoneinfo import ZoneInfo

from PIL import Image
from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas
from reportlab.platypus import Paragraph, Table, TableStyle

from contenuti import (ARTICLES, BASE, OUTSIDE, PACKAGE_CONNECTORS, SNAPSHOT,
                       SOURCES, SUBTITLE, TITLE, block)

ROOT = Path(__file__).resolve().parents[4]
OUT = ROOT / "marketing/pdf"
EVIDENCE = ROOT / "marketing/fonti/ottobre-2026"
AUDIT = json.loads((EVIDENCE / "audit.json").read_text())
PDF = OUT / "askmydocs-ottobre-2026.pdf"
HTML = OUT / "askmydocs-ottobre-2026.html"
W, H = 595.276, 841.89
M, CW, GAP = 45, 238.638, 28
INK = colors.HexColor("#23312d")
GREEN = colors.HexColor("#224c40")
CREAM = colors.HexColor("#f4f0e7")
PAPER = colors.HexColor("#fbf8f1")
MUTED = colors.HexColor("#647168")
LINE = colors.HexColor("#d4d8ca")
RUST = colors.HexColor("#a35539")
PALE = colors.HexColor("#e5ebdf")
FONTROOT = Path("/System/Library/Fonts/Supplemental")

for name, file in [("Editorial", "Georgia.ttf"), ("Editorial-Bold", "Georgia Bold.ttf"),
                   ("Editorial-Italic", "Georgia Italic.ttf"),
                   ("Body", "Arial.ttf"), ("Body-Bold", "Arial Bold.ttf"),
                   ("Body-Italic", "Arial Italic.ttf")]:
    pdfmetrics.registerFont(TTFont(name, str(FONTROOT / file)))
pdfmetrics.registerFontFamily("Body", normal="Body", bold="Body-Bold", italic="Body-Italic", boldItalic="Body-Bold")
pdfmetrics.registerFontFamily("Editorial", normal="Editorial", bold="Editorial-Bold", italic="Editorial-Italic", boldItalic="Editorial-Bold")

DEFAULT_NOTE = "Codice o documentazione versionata; lettura, senza riesecuzione del flusso."
SOURCE_BY_ID = {s["id"]: s for s in SOURCES}
LAYOUT = []


def safe(text):
    return text.replace("\u2011", "-").replace("\u2013", "-").replace("\u2014", "-")


def para(c, text, x, y, width, font="Body", size=10.8, leading=16, color=INK, bottom=60):
    style = ParagraphStyle("p", fontName=font, fontSize=size, leading=leading,
                           textColor=color, alignment=TA_LEFT, splitLongWords=True,
                           allowWidows=0, allowOrphans=0)
    p = Paragraph(safe(text).replace("\n", "<br/>"), style)
    _, h = p.wrap(width, H)
    if y - h < bottom:
        raise ValueError(f"Overflow on page {c.getPageNumber()}: {text[:80]} ends at {y-h:.1f}")
    p.drawOn(c, x, y - h)
    LAYOUT.append({"page": c.getPageNumber(), "text": re.sub("<[^>]+>", "", text)[:70],
                   "x": x, "top": y, "bottom": y-h, "width": width})
    return y - h


def rect(c, x, y, width, height, fill, stroke=None):
    c.setFillColor(fill)
    c.setStrokeColor(stroke or fill)
    c.rect(x, y, width, height, fill=1, stroke=bool(stroke))


def image_fit(c, path, x, y, width, height, cover=False):
    im = Image.open(path)
    if im.mode not in ("RGB", "RGBA"):
        im = im.convert("RGB")
    iw, ih = im.size
    scale = max(width/iw, height/ih) if cover else min(width/iw, height/ih)
    dw, dh = iw*scale, ih*scale
    c.saveState()
    clip = c.beginPath()
    clip.rect(x, y, width, height)
    c.clipPath(clip, stroke=0)
    c.drawImage(ImageReader(im), x + (width-dw)/2, y+(height-dh)/2, dw, dh, mask="auto")
    c.restoreState()


def label(c, text, x, y, size=8.2, color=MUTED):
    c.setFillColor(color)
    c.setFont("Body-Bold", size)
    c.drawString(x, y, safe(text))


def page_base(c, section, number, sources=None, dark=False):
    bg, fg = (GREEN, CREAM) if dark else (PAPER, INK)
    rect(c, 0, 0, W, H, bg)
    label(c, "ASKMYDOCS   /   OTTOBRE 2026", M, H-31, 8, fg)
    c.setStrokeColor(colors.HexColor("#6b877b") if dark else LINE)
    c.setLineWidth(.5)
    c.line(M, H-45, W-M, H-45)
    c.line(M, 43, W-M, 43)
    label(c, section.upper(), M, 26, 7.3, fg)
    c.setFillColor(fg)
    c.setFont("Editorial", 10)
    c.drawRightString(W-M, 25, f"{number:02d}")
    if sources:
        text = "FONTI  " + "  /  ".join(sources) + "   |   Registro alle pp. 21-23"
        para(c, text, M, 65, W-2*M, size=7.3, leading=10, color=MUTED, bottom=44)


def page_title(c, kicker, title, deck):
    label(c, kicker, M, H-81, color=RUST)
    y = para(c, title, M, H-106, W-2*M, font="Editorial", size=31.5, leading=37)
    y = para(c, deck, M, y-17, W-2*M, size=11.5, leading=17, color=MUTED)
    c.setStrokeColor(LINE)
    c.line(M, y-20, W-M, y-20)
    return y-43


def blocks(c, items, x, y, width, size=10.3, leading=15, min_bottom=180):
    for b in items:
        y = para(c, b["title"], x, y, width, font="Editorial", size=14.8, leading=19, bottom=min_bottom)
        y = para(c, b["text"], x, y-10, width, size=size, leading=leading, bottom=min_bottom)
        y -= 25
    return y


def callout(c, text, y=93, height=80):
    rect(c, M, y, W-2*M, height, PALE)
    rect(c, M, y, 3, height, GREEN)
    para(c, text, M+17, y+height-14, W-2*M-34, font="Editorial-Italic", size=11.3, leading=16, bottom=y+10)


def article(c, a, number):
    page_base(c, a["kicker"].split(" / ")[-1], number, a["sources"])
    y = page_title(c, a["kicker"], a["title"], a["deck"])
    bottom = 249 if a.get("image_strip") else 198
    blocks(c, a["left"], M, y, CW, min_bottom=bottom)
    blocks(c, a["right"], M+CW+GAP, y, CW, min_bottom=bottom)
    if a.get("image_strip"):
        image_fit(c, ROOT/"public/editorial/themes/cartographic/knowledge-day.webp", M, 111, CW, 119, True)
        image_fit(c, ROOT/"public/editorial/themes/semantic-orbits/knowledge-night.webp", M+CW+GAP, 111, CW, 119, True)
        para(c, "Asset del prodotto: mondo cartografico / orbite semantiche. Illustrazioni editoriali, non schermate.", M, 98, W-2*M, size=8, leading=11, color=MUTED, bottom=70)
    else:
        callout(c, a["callout"])
    c.showPage()


def cover(c):
    rect(c, 0, 0, W, H, CREAM)
    image_fit(c, ROOT/"public/brand/askmydocs-wordmark-light.png", M-3, H-98, 190, 55)
    label(c, "DIARIO DI PRODOTTO  /  N. 10", W-226, H-58, 8.3, GREEN)
    label(c, "1-29 OTTOBRE 2026", W-226, H-74, 8.3, GREEN)
    para(c, "Ottobre.", M, H-132, W-2*M, font="Editorial", size=61, leading=66, color=GREEN)
    para(c, "Il mese delle\nconnessioni.", M, H-204, W-2*M, font="Editorial", size=39, leading=44)
    para(c, "Più fonti. Risposte verificabili.\nUn'esperienza che trova la sua forma.", M+2, H-313, 450, size=13.3, leading=19, color=MUTED)
    image_fit(c, ROOT/"public/editorial/connections.webp", 0, 66, W, 382, True)
    rect(c, 0, 0, W, 66, GREEN)
    label(c, "248 COMMIT SU DEVELOP", M, 38, 9.5, CREAM)
    label(c, "APP + ECOSISTEMA DEI PACCHETTI", M, 21, 7.5, colors.HexColor("#c3cebe"))
    c.setFillColor(CREAM)
    c.setFont("Body", 8)
    c.drawRightString(W-M, 24, "Snapshot del 29 ottobre 2026")
    c.showPage()


def editorial(c):
    page_base(c, "Editoriale", 2, ["S01", "S26"])
    y = page_title(c, "L'EDITORIALE", "Un archivio che impara\na collegare il lavoro.",
        "La lettura del mese: quattro evoluzioni si incontrano nello stesso prodotto.")
    left = [
        block("La posta e i sistemi operativi", "A ottobre AskMyDocs amplia il punto d'ingresso delle informazioni: Gmail e Microsoft Mail affiancano IMAP, Freshdesk porta il lavoro dell'assistenza e Zendesk apre una nuova linea di sviluppo autonoma. Le configurazioni API diventano trasferibili e le sequenze di chiamate acquistano una struttura versionata."),
        block("La responsabilità della risposta", "Il lavoro sull'agente è altrettanto sostanziale. Ricerca parallela, conservazione degli originali, verifica dei fatti e lettura dei limiti fanno convergere contenuti e dati live. La chat può mostrare risultati verificati mentre la ricerca continua, mantenendo il legame con le prove."),
    ]
    right = [
        block("Il prodotto si riconosce", "La nuova direzione editoriale attraversa interfaccia, landing e desktop. Navigazione, lingua, temi, controlli condivisi e marchio danno continuità a parti che durante il mese crescono rapidamente. Anche impostazioni, diagnostica e consenso diventano più guidati."),
        block("Come leggere questo numero", "Le prossime pagine raccontano il cambiamento per area. Due registri distinguono versioni consumate e lavoro dei pacchetti; una pagina raccoglie i rami ancora separati. L'edizione HTML e il CSV allegato conservano tutte le voci della ricostruzione: il racconto editoriale resta sostenuto dalla cronologia."),
    ]
    blocks(c, left, M, y, CW, size=10.6, leading=15.7, min_bottom=245)
    blocks(c, right, M+CW+GAP, y, CW, size=10.6, leading=15.7, min_bottom=245)
    rect(c, M, 101, W-2*M, 109, GREEN)
    para(c, "In questo numero", M+16, 193, 450, font="Editorial", size=15, leading=20, color=CREAM)
    para(c, "04-07  Ricerca, fonti ed email     /     08-11  Helpdesk, API e OAuth<br/>12-16  Esperienza, client, prestazioni e verifica<br/>17-20  Pacchetti, rami separati e metodo     /     21-23  Registro delle fonti", M+16, 162, 465, size=9.4, leading=16.5, color=CREAM)
    c.showPage()


def numbers(c):
    page_base(c, "Il mese in numeri", 3, ["S01", "S26"])
    y = page_title(c, "IL MESE IN NUMERI", "Il ritmo\ndella trasformazione.",
        "Conteggi sulla cronologia integrata in develop, fra la baseline di settembre e lo snapshot del 29 ottobre.")
    metrics = [("248", "commit integrati"), ("62", "commit feat"), ("131", "commit fix"), ("3", "nuovi connettori nel lock")]
    width = (W-2*M-3*12)/4
    for i, (value, caption) in enumerate(metrics):
        x = M+i*(width+12)
        rect(c, x, y-97, width, 97, PALE)
        para(c, value, x+11, y-11, width-20, font="Editorial", size=34, leading=39, color=GREEN)
        para(c, caption, x+11, y-62, width-20, size=8.5, leading=12)
    y -= 133
    para(c, "Cinque passaggi nel mese", M, y, 450, font="Editorial", size=20, leading=26)
    y -= 48
    timeline = [
        ("01-07", "3", "OAuth esterno, PKCE e consenso"),
        ("08-14", "108", "Freshdesk, IMAP, triage e qualità delle risposte"),
        ("15-21", "48", "Email API, identità editoriale, MCP e pacchetti API"),
        ("22-28", "83", "Profili modelli, tool composti, client e ricerca per ondate"),
        ("29", "6", "Worker Cloud, lookup esatto e scadenze della ricerca"),
    ]
    for dates, count, text in timeline:
        label(c, dates+" OTT", M, y, 9, GREEN)
        label(c, count, M+83, y, 12, RUST)
        para(c, text, M+126, y+9, 377, size=10, leading=14)
        c.setStrokeColor(LINE)
        c.line(M, y-22, W-M, y-22)
        y -= 48
    para(c, "18 docs  /  14 test  /  8 merge  /  15 altri commit", M, 154, 500, font="Body-Bold", size=10.7, leading=15, color=GREEN)
    para(c, "Il diff attraversa 1.814 file, inclusi asset e localizzazioni. La dimensione del diff e i prefissi dei commit descrivono il lavoro versionato: non misurano ore, produttività o numero di funzioni. Il quarto nuovo pacchetto, Zendesk, resta esterno al lockfile.", M, 124, W-2*M, size=9.3, leading=13, color=MUTED, bottom=75)
    c.showPage()


def draw_table(c, headers, rows, widths, x, y, font=9.0, padding=8, bottom=76):
    style = ParagraphStyle("table", fontName="Body", fontSize=font, leading=font+3.3, textColor=INK)
    hstyle = ParagraphStyle("thead", parent=style, fontName="Body-Bold", textColor=CREAM)
    cells = [[Paragraph(safe(str(t)), hstyle) for t in headers]]
    cells += [[Paragraph(safe(str(t)), style) for t in row] for row in rows]
    table = Table(cells, colWidths=widths, hAlign="LEFT")
    table.setStyle(TableStyle([
        ("BACKGROUND", (0,0), (-1,0), GREEN), ("VALIGN", (0,0), (-1,-1), "TOP"),
        ("LEFTPADDING", (0,0), (-1,-1), padding), ("RIGHTPADDING", (0,0), (-1,-1), padding),
        ("TOPPADDING", (0,0), (-1,-1), padding), ("BOTTOMPADDING", (0,0), (-1,-1), padding),
        ("ROWBACKGROUNDS", (0,1), (-1,-1), [CREAM, PAPER]),
        ("LINEBELOW", (0,1), (-1,-1), .35, LINE),
    ]))
    tw, th = table.wrap(W, H)
    if y-th < bottom:
        raise ValueError(f"Table overflow page {c.getPageNumber()}: bottom={y-th}")
    table.drawOn(c, x, y-th)
    LAYOUT.append({"page": c.getPageNumber(), "text": "TABLE", "top": y, "bottom": y-th})
    return y-th


def connector_ledger(c):
    page_base(c, "Registro / Connettori", 17, ["S14", "S15", "S16", "S26", "S27"])
    y = page_title(c, "REGISTRO DEI PACCHETTI / 1", "Connettori,\nversioni, disponibilità.",
        "Il lockfile del consumer è il riferimento per la versione integrata. Un commit locale di stile o di funzione non cambia quel vincolo.")
    draw_table(c, ["PACCHETTO", "VERSIONE / STATO", "LETTURA DI OTTOBRE"], PACKAGE_CONNECTORS,
               [100, 115, W-2*M-215], M, y+4, font=8.2, padding=5.3, bottom=79)
    c.showPage()


def shared_rows():
    remotes = {p["name"]: p for p in AUDIT["remote_default_branch_snapshots"]}
    rows = []
    for p in AUDIT["locked_packages"]:
        if p["name"].startswith("padosoft/askmydocs"):
            continue
        name = p["name"].removeprefix("padosoft/")
        if name == "agents-full-duplex/laravel-realtime-agent":
            name = "laravel-realtime-agent"
        status = "Nessun commit remoto di ottobre; lock invariato."
        if p["name"] == "padosoft/laravel-evidence-risk-review":
            status = "8 commit remoti: docs, controlli Markdown e dist. Lock invariato."
        elif p["name"].startswith("agents-full-duplex/"):
            status = "Lock invariato; upgrade ElevenLabs nel checkout locale del bridge."
        rows.append((name, p["after"], status))
    return rows


def shared_ledger(c):
    page_base(c, "Registro / Componenti comuni", 18, ["S26", "S28", "S33"])
    y = page_title(c, "REGISTRO DEI PACCHETTI / 2", "Il resto\ndell'ecosistema.",
        "Controllo delle altre dipendenze proprietarie: confronto degli SHA del lockfile e query di ottobre sul ramo remoto predefinito.")
    y = draw_table(c, ["PACCHETTO COMPOSER", "NEL LOCK", "OTTOBRE"], shared_rows(),
               [186, 61, W-2*M-247], M, y+4, font=8.3, padding=5.3, bottom=126)
    para(c, "Inoltre: AgenticFlow, Ui4VocalAgent e laravel-ui4-workbench non hanno commit di ottobre nei checkout locali esaminati. Le varianti admin/UI sono controllate come dipendenze distinte.", M, y-16, W-2*M, size=8.5, leading=12, color=MUTED, bottom=78)
    c.showPage()


def outside_page(c):
    page_base(c, "Fuori dallo snapshot", 19, ["S15", "S32", "S33", "S38", "S39"])
    y = page_title(c, "LAVORI DEL MESE / RAMI SEPARATI", "Il cantiere\nche resta aperto.",
        "Lavoro esistente a ottobre, distinto dalle capacità integrate nel develop verificato.")
    blocks(c, OUTSIDE[:2], M, y, CW, size=10.5, leading=15.8, min_bottom=196)
    blocks(c, OUTSIDE[2:], M+CW+GAP, y, CW, size=10.5, leading=15.8, min_bottom=196)
    callout(c, "Il registro completo conserva 46 commit core non raggiungibili da develop. Contiene anche copie ribasate e varianti: il numero non rappresenta 46 novità indipendenti.")
    c.showPage()


def method_page(c):
    page_base(c, "Metodo e prospettiva", 20, ["S01", "S19", "S26", "S35"])
    y = page_title(c, "COME È STATA RICOSTRUITA L'ATTIVITÀ", "Una fotografia precisa.\nUn mese ancora in corso.",
        "L'analisi arriva al 29 ottobre. I giorni 30 e 31 e il lavoro successivo allo snapshot non sono compresi.")
    left = [
        block("Perimetro e fonti", "La base è il commit cd1e917f7 del 30 settembre; lo snapshot è 8003ca793 del 29 ottobre, ore 09:15:41 in Italia. Il riferimento origin/develop è stato confrontato con l'API GitHub. Esiste anche un tag chiamato develop: per evitare ambiguità è stato usato il riferimento remoto completo."),
        block("Tre livelli distinti", "Sono stati esaminati 22 checkout locali, inclusa l'app, e 34 repository di pacchetto tramite API: 33 accessibili e Zendesk non accessibile. Il registro distingue i 248 commit integrati, gli HEAD locali dei pacchetti e i rami remoti predefiniti. Il lockfile include 33 dipendenze proprietarie, anche di sviluppo. Non vengono conteggiati due volte gli stessi dati per produrre un totale dell'ecosistema."),
    ]
    right = [
        block("Ciò che il report dimostra", "La cronologia prova le modifiche versionate; il codice e i contratti chiariscono il comportamento implementato. I registri storici descrivono i test e i flussi osservati in quelle revisioni. Non sono stati letti segreti o mailbox, rieseguiti test applicativi o modificati ambienti durante questa analisi. Le modifiche non committate sono escluse."),
        block("I prossimi passaggi più concreti", "La lettura editoriale suggerisce tre priorità: allineare le revisioni dei pacchetti alle estensioni che si vogliono usare; verificare end-to-end i flussi OAuth e l'attivazione dei tool negli ambienti destinatari; chiudere le verifiche e i requisiti della nuova beta iOS. Sono indicazioni ricavate dai limiti documentati, non una nuova roadmap approvata."),
    ]
    blocks(c, left, M, y, CW, size=10.5, leading=15.6, min_bottom=190)
    blocks(c, right, M+CW+GAP, y, CW, size=10.5, leading=15.6, min_bottom=190)
    callout(c, "Ottobre consegna una piattaforma più connessa e più leggibile. Il passo successivo è portare le singole capacità attraverso gli stessi confini di verifica.")
    c.showPage()


def source_pages(c):
    for chunk in range(3):
        page_base(c, "Fonti / Registro", 21+chunk)
        y = page_title(c, f"FONTI VERIFICABILI / {chunk+1} DI 3", "Fonti e revisioni.",
            "Riferimenti fissati a revisione. I link rimandano ai sorgenti; le consegne locali riportano il checkout e il ramo nel registro HTML.")
        for s in SOURCES[chunk*13:(chunk+1)*13]:
            link = s["url"]
            title = html.escape(s["title"])
            if link:
                title = f'<link href="{html.escape(link, quote=True)}" color="#224c40">{title}</link>'
            y = para(c, f'<b>{s["id"]}</b>  {title}', M, y, W-2*M, size=9.4, leading=13)
            repo = s["repository"] or "Git locale"
            detail = f'{repo}  /  {s["revision"][:10]}  /  {s["path"]}'
            y = para(c, html.escape(detail), M+28, y-3, W-2*M-28, size=7.6, leading=10.2, color=MUTED, bottom=61)
            if s["note"] != DEFAULT_NOTE:
                y = para(c, html.escape(s["note"]), M+28, y-3, W-2*M-28, size=7.6, leading=10.2, color=MUTED, bottom=61)
            y -= 8
        c.showPage()


def pdf_report():
    c = canvas.Canvas(str(PDF), pagesize=(W, H), pageCompression=1)
    c.setTitle(TITLE + " | AskMyDocs")
    c.setAuthor("AskMyDocs - Diario di prodotto")
    c.setSubject("Analisi di ottobre 2026: develop, pacchetti, integrazioni e miglioramenti")
    c.setCreator("AskMyDocs editorial report / ReportLab")
    cover(c)
    editorial(c)
    numbers(c)
    for number, a in enumerate(ARTICLES, 4):
        article(c, a, number)
    connector_ledger(c)
    shared_ledger(c)
    outside_page(c)
    method_page(c)
    source_pages(c)
    assert c.getPageNumber() == 24
    c.save()


def uri(path):
    path = Path(path)
    mime = "image/webp" if path.suffix == ".webp" else "image/png"
    return f"data:{mime};base64," + base64.b64encode(path.read_bytes()).decode()


def html_table(headers, rows):
    return '<div class="table-scroll"><table><thead><tr>'+''.join(f'<th>{html.escape(str(h))}</th>' for h in headers)+'</tr></thead><tbody>'+''.join('<tr>'+''.join(f'<td>{html.escape(str(t))}</td>' for t in row)+'</tr>' for row in rows)+'</tbody></table></div>'


def html_blocks(items):
    return ''.join(f'<div class="block"><h3>{html.escape(b["title"])}</h3><p>{b["text"]}</p></div>' for b in items)


def html_report():
    cover_img = uri(ROOT/"public/editorial/connections.webp")
    wordmark = uri(ROOT/"public/brand/askmydocs-wordmark-light.png")
    css = """
    :root{--paper:#fbf8f1;--cream:#f4f0e7;--ink:#23312d;--green:#224c40;--muted:#647168;--rust:#a35539;--line:#d4d8ca;--pale:#e5ebdf;color-scheme:light}
    *{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--paper);color:var(--ink);font-family:Arial,sans-serif;line-height:1.65}a{color:var(--green);text-underline-offset:4px}a:hover{color:var(--rust)}
    .masthead{max-width:1200px;margin:auto;display:flex;align-items:center;justify-content:space-between;padding:24px 36px;gap:25px}.brand{width:210px;height:auto}.eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:1.5px;font-weight:bold;color:var(--rust)}
    .cover{background:var(--cream)}.hero{max-width:1128px;margin:auto;padding:45px 0 50px}.hero h1{font-family:Georgia,serif;font-weight:normal;font-size:clamp(46px,7vw,86px);line-height:1.08;letter-spacing:-2px;margin:15px 0 25px;max-width:850px}.hero p{font-size:21px;max-width:600px;color:var(--muted)}.cover-art{display:block;width:100%;height:440px;object-fit:cover}.hero-meta{background:var(--green);color:var(--cream);padding:18px 36px;font-size:14px}.hero-meta>div{max-width:1128px;margin:auto;display:flex;justify-content:space-between;gap:20px}
    main{max-width:1128px;margin:auto}.chapter{padding:65px 0 55px;border-bottom:1px solid var(--line);scroll-margin-top:24px}.chapter h2{font-family:Georgia,serif;font-size:clamp(32px,4vw,49px);line-height:1.15;font-weight:normal;max-width:900px;margin:15px 0 20px}.deck{font-size:20px;color:var(--muted);max-width:850px;margin-bottom:35px}.cols{display:grid;grid-template-columns:1fr 1fr;gap:60px}h3{font-family:Georgia,serif;font-weight:normal;font-size:23px;line-height:1.3;margin:0 0 14px}.block{margin-bottom:30px}.block p{margin:0;font-size:17px}.callout{padding:22px 30px;background:var(--pale);border-left:4px solid var(--green);font-family:Georgia,serif;font-style:italic;font-size:21px;margin:25px 0}.sources{font-size:12px;color:var(--muted);margin:20px 0 0}.sources a{margin:0 5px}
    .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin:35px 0}.stat{background:var(--pale);padding:22px}.stat b{font:48px Georgia,serif;display:block;color:var(--green)}.stat span{font-size:13px}.timeline{display:grid;grid-template-columns:repeat(5,1fr);gap:20px;margin:35px 0}.period{border-top:3px solid var(--green);padding-top:15px}.period b{display:block;font:30px Georgia,serif}.period span{font-size:14px;color:var(--muted)}
    .toc{columns:2;column-gap:45px;padding:0;list-style:none}.toc li{break-inside:avoid;padding:8px 0;border-bottom:1px solid var(--line)}.toc a{text-decoration:none}.toc small{margin-right:12px;color:var(--rust)}.art-strip{display:grid;grid-template-columns:1fr 1fr;gap:25px;margin:30px 0}.art-strip img{width:100%;height:250px;object-fit:cover}.caption,.note{font-size:13px;color:var(--muted)}
    .table-scroll{overflow:auto}table{border-collapse:collapse;width:100%;font-size:14px}thead{background:var(--green);color:var(--cream)}th,td{text-align:left;padding:13px 15px;vertical-align:top;border-bottom:1px solid var(--line)}tbody tr:nth-child(odd){background:var(--cream)}th{font-size:12px;text-transform:uppercase;letter-spacing:.5px}code{font-size:.85em;overflow-wrap:anywhere}td:nth-child(1){font-weight:bold}#commits td:first-child{font-weight:normal}#commits td:last-child{min-width:330px}#commits td:nth-child(3){min-width:200px}.filters{display:flex;gap:18px;align-items:end;margin:30px 0}.filters label{display:flex;flex:1;flex-direction:column;gap:8px;font-size:13px;font-weight:bold}input,select{font:16px Arial,sans-serif;min-height:48px;padding:11px 13px;border:1px solid #9da99f;border-radius:4px;background:var(--paper);color:var(--ink);width:100%}input:focus,select:focus{outline:2px solid var(--green);outline-offset:2px}.result-count{font-size:13px;color:var(--muted)}details{margin:25px 0;padding:18px;background:var(--cream)}summary{cursor:pointer;font-weight:bold}
    .source-grid{display:grid;grid-template-columns:1fr 1fr;gap:25px 45px}.source{border-top:1px solid var(--line);padding-top:15px;scroll-margin-top:30px}.source b{font-size:12px;color:var(--rust)}.source h3{font-size:18px;margin-top:5px}.source p{font-size:12px;color:var(--muted);margin:6px 0;overflow-wrap:anywhere}.downloads{display:flex;gap:20px;flex-wrap:wrap}.downloads a{font-weight:bold}footer{padding:40px 36px;background:var(--green);color:var(--cream);font-size:13px}
    @media(max-width:1199px){.hero,main{padding-left:36px;padding-right:36px}}@media(max-width:700px){.masthead{padding:18px 22px;align-items:flex-start}.masthead .eyebrow{font-size:9px;text-align:right}.brand{width:160px}.hero,main{padding-left:22px;padding-right:22px}.hero{padding-top:28px}.hero h1{letter-spacing:-1px}.cover-art{height:290px}.hero-meta{padding:18px 22px}.hero-meta>div{flex-direction:column;gap:4px}.chapter{padding-top:42px}.cols,.source-grid,.art-strip{grid-template-columns:1fr;gap:0}.stats{grid-template-columns:1fr 1fr;gap:10px}.stat{padding:16px}.stat b{font-size:40px}.timeline{grid-template-columns:1fr;gap:10px}.period{display:flex;align-items:baseline;gap:15px;padding:10px 0}.period b{font-size:25px;min-width:40px}.toc{columns:1}.filters{flex-direction:column;align-items:stretch}.art-strip img{height:auto;margin-bottom:15px}table{font-size:13px}.callout{font-size:19px;padding:20px}.block p{font-size:16px}}
    @media print{.filters,.downloads{display:none}body{font-size:11pt}.chapter{break-before:page}.cover-art{height:260px}.cols{display:block}.source-grid{display:block}a{color:inherit}.table-scroll{overflow:visible}table{font-size:8pt}header,.chapter{padding:20px 0}main,.hero{max-width:none}.block{break-inside:avoid}}
    """
    parts = [f'<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{html.escape(TITLE)} | AskMyDocs</title><style>{css}</style></head><body>',
        f'<header class="cover"><div class="masthead"><img class="brand" src="{wordmark}" alt="AskMyDocs"><div class="eyebrow">Diario di prodotto / N. 10<br>1-29 ottobre 2026</div></div><div class="hero"><span class="eyebrow">App e pacchetti / Il riepilogo del mese</span><h1>Ottobre.<br>Il mese delle connessioni.</h1><p>Più fonti. Risposte verificabili.<br>Un\'esperienza che trova la sua forma.</p><div class="downloads"><a href="askmydocs-ottobre-2026.pdf">Report PDF</a><a href="../fonti/ottobre-2026/registro-commit.csv">Registro CSV</a><a href="../fonti/ottobre-2026/audit.json">Dati e revisioni JSON</a></div></div><img class="cover-art" src="{cover_img}" alt="Illustrazione editoriale originale AskMyDocs: archivi collegati da ponti"><div class="hero-meta"><div><b>248 commit integrati in develop</b><span>Snapshot remoto verificato: 8003ca793 / 29 ottobre 2026, 09:15:41 CET</span></div></div></header><main>',
        '<section class="chapter"><span class="eyebrow">L\'editoriale</span><h2>Un archivio che impara a collegare il lavoro.</h2><p class="deck">Ottobre amplia le fonti, rende più controllabile il percorso della risposta e dà continuità all\'esperienza del prodotto.</p><div class="cols"><p>Gmail e Microsoft Mail affiancano IMAP. Freshdesk porta il lavoro dell\'assistenza, mentre Zendesk apre una linea autonoma. Le configurazioni API diventano trasferibili e le sequenze di chiamate assumono una struttura versionata.</p><p>Ricerca per ondate, originali verificati e risultati progressivi collegano documenti e sistemi live. La nuova direzione editoriale attraversa app, landing e desktop. Le capacità integrate, i pacchetti e il lavoro ancora su rami separati sono distinti nelle pagine che seguono.</p></div><ol class="toc">']
    for index, a in enumerate(ARTICLES, 1):
        parts.append(f'<li><a href="#cap-{index}"><small>{index:02d}</small>{html.escape(a["title"].replace(chr(10), " "))}</a></li>')
    parts += ['<li><a href="#packages"><small>14</small>Registro dei pacchetti</a></li><li><a href="#outside"><small>15</small>Il cantiere sui rami separati</a></li><li><a href="#commits"><small>16</small>Registro completo dei commit</a></li><li><a href="#fonti"><small>17</small>Fonti e revisioni</a></li></ol></section>']
    parts.append('<section class="chapter"><span class="eyebrow">Il mese in numeri</span><h2>Il ritmo della trasformazione.</h2><div class="stats">')
    for value, caption in [("248","commit integrati"),("62","commit feat"),("131","commit fix"),("3","nuovi connettori nel lock")]:
        parts.append(f'<div class="stat"><b>{value}</b><span>{caption}</span></div>')
    parts.append('</div><div class="timeline">')
    for dates, n, desc in [("1-7 ottobre",3,"OAuth e consenso"),("8-14 ottobre",108,"Freshdesk, triage e risposte"),("15-21 ottobre",48,"Email API, UI e MCP"),("22-28 ottobre",83,"Tool, client e ondate"),("29 ottobre",6,"Cloud e scadenze")]:
        parts.append(f'<div class="period"><span>{dates}</span><b>{n}</b><span>{desc}</span></div>')
    parts.append('</div><p class="note">18 docs, 14 test, 8 merge e 15 altri commit completano il totale. Il diff comprende 1.814 file, inclusi asset e localizzazioni. I conteggi non misurano produttività, ore lavorate o numero di funzionalità. Il quarto nuovo pacchetto, Zendesk, è locale e non compare nel lockfile.</p></section>')
    for index, a in enumerate(ARTICLES, 1):
        parts.append(f'<section class="chapter" id="cap-{index}"><span class="eyebrow">{html.escape(a["kicker"])}</span><h2>{html.escape(a["title"]).replace(chr(10)," ")}</h2><p class="deck">{a["deck"]}</p><div class="cols"><div>{html_blocks(a["left"])}</div><div>{html_blocks(a["right"])}</div></div><aside class="callout">{a["callout"]}</aside>')
        if a.get("image_strip"):
            parts.append('<div class="art-strip">'+''.join(f'<img src="{uri(ROOT/p)}" alt="Illustrazione di tema del prodotto: {alt}">' for p,alt in [("public/editorial/themes/cartographic/knowledge-day.webp","mondo cartografico, giorno"),("public/editorial/themes/semantic-orbits/knowledge-night.webp","orbite semantiche, notte")])+'</div><p class="caption">Asset originali del prodotto; funzione decorativa editoriale, non schermate o prove di un flusso.</p>')
        parts.append('<p class="sources">Fonti '+''.join(f'<a href="#{s}">{s}</a>' for s in a['sources'])+'</p></section>')
    parts.append('<section class="chapter" id="packages"><span class="eyebrow">Registro dei pacchetti</span><h2>Le versioni raccontano la disponibilità.</h2><p class="deck">Il confronto degli SHA nel lockfile tiene separati adozione del consumer, release remota e checkout locale.</p>'+html_table(["Pacchetto","Versione / stato","Lettura di ottobre"],PACKAGE_CONNECTORS)+'<h3 style="margin-top:40px">Componenti condivisi</h3>'+html_table(["Pacchetto Composer","Nel lock","Ottobre"],shared_rows()))
    parts.append('<p class="note">AgenticFlow, Ui4VocalAgent e laravel-ui4-workbench: nessun commit di ottobre nei checkout locali. La cartella askmydocs-connector-apib duplica la repository API. Nessuna novità è dedotta dalla sola presenza di una dipendenza.</p><details><summary>Snapshot esatto di tutte le 33 dipendenze proprietarie</summary>'+html_table(["Pacchetto","Prima","Dopo","SHA consumato"],[(p['name'],p['before'] or 'Nuovo',p['after'],p['revision']) for p in AUDIT['locked_packages']])+'</details><details><summary>Copertura delle query GitHub: 34 repository, 33 accessibili</summary>'+html_table(["Repository","Commit di ottobre sul default","Limite"],[(r['repo'],len(r.get('commits',[])) if 'commits' in r else 'Non accessibile',r.get('error','Ramo predefinito; fotografia del 29 ottobre.')) for r in AUDIT['remote_default_branch_snapshots']])+'</details></section>')
    parts.append('<section class="chapter" id="outside"><span class="eyebrow">Lavoro esterno allo snapshot</span><h2>Il cantiere che resta aperto.</h2><div class="cols"><div>'+html_blocks(OUTSIDE[:2])+'</div><div>'+html_blocks(OUTSIDE[2:])+'</div></div><aside class="callout">46 commit core non raggiungibili da develop sono conservati nel registro grezzo. Il gruppo include copie ribasate e varianti: non rappresenta 46 novità indipendenti.</aside><p class="sources">Fonti <a href="#S15">S15</a> <a href="#S32">S32</a> <a href="#S33">S33</a> <a href="#S38">S38</a> <a href="#S39">S39</a></p></section>')
    parts.append('<section class="chapter"><span class="eyebrow">Metodo e prospettiva</span><h2>Una fotografia precisa. Un mese ancora in corso.</h2><div class="cols"><div><h3>Perimetro</h3><p>Baseline <code>'+BASE+'</code>, snapshot <code>'+SNAPSHOT+'</code>, confrontato con l\'API GitHub di develop. È stato usato <code>refs/remotes/origin/develop</code>: esiste anche un tag omonimo. Le date sono quelle di commit/integrazione, riferite al periodo 1-29 ottobre 2026. I giorni 30-31 e il lavoro successivo allo snapshot sono esclusi.</p><p>Sono stati esaminati 22 checkout locali inclusa l\'app e 34 repository di pacchetto remoti. I risultati di app, HEAD locali e rami remoti sono conservati separatamente e possono contenere lo stesso commit.</p></div><div><h3>Tipo di verifica</h3><p>La lettura del codice prova l\'implementazione. I risultati dei test citati sono quelli dei registri storici: non sono stati rieseguiti per il report e non vanno sommati. Nessun accesso a mailbox o segreti, nessuna modifica di ambiente o del prodotto. Le modifiche non committate sono escluse.</p><h3>Priorità ricavate dai limiti</h3><p>Allineare le revisioni dei pacchetti alle estensioni desiderate; verificare OAuth e attivazione dei tool negli ambienti destinatari; completare QA e requisiti per la nuova beta iOS. Queste sono indicazioni editoriali, non una roadmap approvata.</p></div></div></section>')
    with (EVIDENCE/'registro-commit.csv').open(encoding='utf-8-sig',newline='') as f:
        ledger=list(csv.DictReader(f))
    scopes={"develop":"Develop integrato","package_local_head":"Pacchetto / HEAD locale","package_remote_default":"Pacchetto / default remoto","outside_develop_raw":"Core / fuori develop, grezzo"}
    parts.append('<section class="chapter" id="commits"><span class="eyebrow">Appendice completa</span><h2>Ogni modifica ha un riferimento.</h2><p class="deck">372 righe di evidenza. Le stesse revisioni possono comparire nel checkout locale e nella query remota: le righe non sono un totale di commit unici.</p><div class="filters"><label>Cerca per pacchetto, parola o SHA<input id="query" type="search" autocomplete="off" placeholder="Per esempio: Gmail, triage, waves, OAuth"></label><label>Perimetro<select id="scope"><option value="">Tutti i perimetri</option>'+''.join(f'<option value="{key}">{value}</option>' for key,value in scopes.items())+'</select></label></div><p id="count" class="result-count" aria-live="polite"></p><div class="table-scroll"><table><thead><tr><th>Data commit</th><th>Perimetro</th><th>Repository / SHA</th><th>Modifica</th></tr></thead><tbody id="commit-rows">')
    for row in ledger:
        date = datetime.fromisoformat(row['committed'].replace('Z','+00:00')).astimezone(ZoneInfo('Europe/Rome')).date().isoformat()
        sha = row['sha'][:10]
        sha_html = f'<a href="{html.escape(row["url"],quote=True)}"><code>{sha}</code></a>' if row['url'] else f'<code>{sha}</code>'
        searchable=html.escape(' '.join([row['repository'],row['sha'],row['subject'],date]).casefold(),quote=True)
        parts.append(f'<tr data-scope="{row["scope"]}" data-search="{searchable}"><td>{date}</td><td>{scopes[row["scope"]]}</td><td>{html.escape(row["repository"])}<br>{sha_html}</td><td>{html.escape(row["subject"])}</td></tr>')
    parts.append('</tbody></table></div><p class="note">Il gruppo fuori develop include backup e copie ribasate; la non raggiungibilità di uno SHA non dimostra da sola l\'assenza funzionale della modifica. Per repository locali senza remoto, il riferimento è lo SHA nel checkout indicato in audit.json.</p></section>')
    parts.append('<section class="chapter" id="fonti"><span class="eyebrow">Fonti e revisioni</span><h2>Il racconto, le sue prove.</h2><p class="deck">39 riferimenti, controllati il 29 ottobre 2026. I link remoti sono fissati a commit; i percorsi locali richiedono accesso a questo workspace.</p><div class="source-grid">')
    for s in SOURCES:
        target=s['url'] or s['local']
        name=html.escape(s['title'])
        linked=f'<a href="{html.escape(target,quote=True)}">{name}</a>' if target else name
        parts.append(f'<article class="source" id="{s["id"]}"><b>{s["id"]}</b><h3>{linked}</h3><p>{html.escape(s["repository"] or "Git locale")}<br><code>{s["revision"]}</code><br><code>{html.escape(s["path"])}</code></p><p>{html.escape(s["note"])}</p></article>')
    parts.append('</div></section></main><footer>AskMyDocs / Diario di prodotto / Ottobre 2026<br>Edizione locale preparata dalla cronologia del prodotto. Illustrazioni: asset originali AskMyDocs.<br>Snapshot 29 ottobre 2026. Nessuna pubblicazione o modifica del prodotto eseguita per questo report.</footer><script>const rows=[...document.querySelectorAll("#commit-rows tr")],q=document.getElementById("query"),s=document.getElementById("scope"),count=document.getElementById("count");function filter(){const query=q.value.toLowerCase().trim();let n=0;for(const r of rows){const visible=(!s.value||r.dataset.scope===s.value)&&(!query||r.dataset.search.includes(query));r.hidden=!visible;if(visible)n++;}count.textContent=n+" di "+rows.length+" righe visualizzate";}q.addEventListener("input",filter);s.addEventListener("change",filter);filter();</script></body></html>')
    HTML.write_text(''.join(parts))


def finalize_sources():
    # Resolve abbreviated local refs and check every cited path before rendering.
    for s in SOURCES:
        if s['repository'] == 'lopadova/AskMyDocs':
            repo=ROOT
        elif s['repository'] in ['padosoft/askmydocs-connector-gmail-api','padosoft/askmydocs-connector-outlook-api','padosoft/askmydocs-connector-freshdesk']:
            names={'padosoft/askmydocs-connector-gmail-api':'askmydocs-connector-gmail','padosoft/askmydocs-connector-outlook-api':'askmydocs-connector-microsoft-mail','padosoft/askmydocs-connector-freshdesk':'askmydocs-connector-freshdesk'}
            repo=Path('/Users/marco/packages')/names[s['repository']]
        elif s['local']:
            repo=Path(s['local']).parent
            while repo!=repo.parent and not (repo/'.git').exists():
                repo=repo.parent
        else:
            # These references were checked through the connected GitHub tool.
            match=next((r for r in AUDIT['remote_default_branch_snapshots'] if r['repo']==s['repository']),None)
            if match:
                commit=next((c['sha'] for c in match.get('commits',[]) if c['sha'].startswith(s['revision'])),None)
                if commit:s['revision']=commit
            if s['url']:s['url']=f'https://github.com/{s["repository"]}/blob/{s["revision"]}/{s["path"]}'
            continue
        full=subprocess.check_output(['git','-C',str(repo),'rev-parse',s['revision']],text=True).strip()
        subprocess.run(['git','-C',str(repo),'cat-file','-e',full+':'+s['path']],check=True,capture_output=True)
        s['revision']=full
        if s['url']:s['url']=f'https://github.com/{s["repository"]}/blob/{full}/{s["path"]}'
    (EVIDENCE/'fonti.json').write_text(json.dumps({"sources":SOURCES},ensure_ascii=False,indent=2)+'\n')


if __name__ == '__main__':
    OUT.mkdir(parents=True, exist_ok=True)
    finalize_sources()
    pdf_report()
    html_report()
    (EVIDENCE/'layout-verifica.json').write_text(json.dumps(LAYOUT,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({"pdf":str(PDF),"html":str(HTML),"pages":23,"sources":len(SOURCES),"layout_blocks":len(LAYOUT)},ensure_ascii=False))
