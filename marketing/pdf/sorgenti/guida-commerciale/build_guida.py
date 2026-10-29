"""Build the fashion-atelier sales field guide from reviewed editorial content.

Run with the bundled Python runtime. All source paths are repository-relative.
The generated PDF, page maps and QA files remain local delivery artifacts.
"""
from pathlib import Path
from html import escape
from io import BytesIO
import hashlib
import json
import re
from urllib.parse import urlparse

from PIL import Image
from reportlab.pdfgen import canvas
from reportlab.lib.colors import HexColor, Color
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import Paragraph
from reportlab.lib.pagesizes import A4

from contenuti_commerciali import (ROOT, REVISION, TITLE, SUBTITLE, FRONT,
    FOLLOW, CASE_TITLES, CASE_IMAGES, CASE_COPY, LIMIT_ROWS, RESEARCH, SOURCES)

OUT = ROOT / "marketing/pdf/askmydocs-guida-commerciale.pdf"
QA = ROOT / "marketing/pdf/sorgenti/guida-commerciale/.build"
W, H = A4
M = 44
CW = W - 2 * M
MW = 320
GAP = 24
SX = M + MW + GAP
SW = CW - MW - GAP
PAPER = HexColor("#f6f1e7")
INK = HexColor("#20384a")
RUST = HexColor("#a95032")
PALE = HexColor("#e4eaf0")
LINE = HexColor("#c9c7bc")
MUTED = HexColor("#54636a")
WHITE = HexColor("#fffdf7")

FONT_DIR = Path("/System/Library/Fonts/Supplemental")
for name, filename in [("Body", "Arial.ttf"), ("BodyB", "Arial Bold.ttf"),
                        ("BodyI", "Arial Italic.ttf"), ("Display", "Georgia.ttf"),
                        ("DisplayI", "Georgia Italic.ttf"), ("DisplayB", "Georgia Bold.ttf")]:
    pdfmetrics.registerFont(TTFont(name, str(FONT_DIR / filename)))

PAGE_MAP = []
BOXES = []
CURRENT_PAGE = 0


def clean(s):
    return s.replace("\u2011", "-").replace("\u2013", "-").replace("\u2014", "-")


def para(c, text, x, top, width, size=10.4, leading=15.7,
         font="Body", color=INK, min_bottom=0, label="text"):
    safe = escape(clean(text)).replace("\n", "<br/>")
    safe = safe.replace("&lt;br/&gt;", "<br/>")
    style = ParagraphStyle("p", fontName=font, fontSize=size, leading=leading,
                           textColor=color, alignment=TA_LEFT, allowWidows=0,
                           allowOrphans=0, splitLongWords=False)
    obj = Paragraph(safe, style)
    _, height = obj.wrap(width, H)
    bottom = top - height
    if bottom < min_bottom - .1:
        raise ValueError(f"Page {CURRENT_PAGE} {label}: bottom {bottom:.1f} < {min_bottom}")
    if x < 0 or x + width > W + .1 or top > H + .1:
        raise ValueError(f"Page {CURRENT_PAGE} {label}: outside canvas")
    obj.drawOn(c, x, bottom)
    BOXES.append({"page":CURRENT_PAGE,"label":label,"x":round(x,2),
                  "y":round(bottom,2),"w":round(width,2),"h":round(height,2)})
    return bottom


def line(c, x1, y1, x2, y2, color=LINE, width=.6):
    c.setStrokeColor(color)
    c.setLineWidth(width)
    c.line(x1, y1, x2, y2)


def image_cover(c, rel, x, y, width, height, focus=.5, yfocus=.5):
    """Crop images proportionally; never stretch pixels."""
    im = Image.open(ROOT / rel).convert("RGB")
    iw, ih = im.size
    encoded = BytesIO()
    im.save(encoded, format="JPEG", quality=92)
    encoded.seek(0)
    scale = max(width / iw, height / ih)
    dw, dh = iw * scale, ih * scale
    c.saveState()
    p = c.beginPath()
    p.rect(x, y, width, height)
    c.clipPath(p, stroke=0)
    c.drawImage(ImageReader(encoded), x + (width-dw)*focus, y + (height-dh)*yfocus,
                width=dw, height=dh, mask="auto")
    c.restoreState()


def base(c, page, section, title):
    global CURRENT_PAGE
    CURRENT_PAGE = page
    c.setFillColor(PAPER)
    c.rect(0, 0, W, H, fill=1, stroke=0)
    c.setFillColor(INK)
    c.setFont("BodyB", 8)
    c.drawString(M, H-35, "ASKMYDOCS / QUADERNO COMMERCIALE")
    c.setFont("Body", 7.5)
    c.drawRightString(W-M, H-35, "TESSILE · MODA · PELLETTERIA")
    line(c, M, H-45, W-M, H-45)
    line(c, M, 46, W-M, 46)
    c.setFillColor(MUTED)
    c.setFont("Body", 7.1)
    c.drawString(M, 31, "EDIZIONE 29 OTTOBRE 2026 / USO INTERNO COMMERCIALE")
    c.setFillColor(INK)
    c.setFont("Display", 10)
    c.drawRightString(W-M, 29, f"{page:02d} / 32")
    c.bookmarkPage(f"p{page}")
    c.addOutlineEntry(title.replace("\n", " "), f"p{page}", level=0, closed=False)
    PAGE_MAP.append({"page":page,"section":section,"title":title.replace("\n", " ")})


def heading(c, d, title_size=28):
    para(c, d["kicker"], M, H-72, CW, 8.4, 10.5, "BodyB", RUST, label="kicker")
    y = para(c, d["title"], M, H-100, CW, title_size, 34, "Display", label="title")
    return para(c, d["deck"], M, y-17, CW, 11.1, 16.4, color=MUTED, label="deck") - 24


def blocks(c, items, x, top, width, min_bottom=180, size=10.4, leading=15.7):
    for n, item in enumerate(items):
        top = para(c, item["title"], x, top, width, 13.1, 17.5, "DisplayB",
                   min_bottom=min_bottom, label=f"block {n+1} heading")-10
        top = para(c, item["text"], x, top, width, size, leading,
                   min_bottom=min_bottom, label=f"block {n+1} text")-22
    return top


def helpers(c, items, top, min_bottom=180, heading_text="APPUNTI SUL CAMPO"):
    top = para(c, heading_text, SX, top+1, SW, 8.1, 10.5, "BodyB", RUST,
               label="sidebar label")-14
    for n, item in enumerate(items):
        style = ParagraphStyle("measure", fontName="Body", fontSize=9.3, leading=13.5)
        obj = Paragraph(escape(clean(item["text"])).replace("&lt;br/&gt;", "<br/>"), style)
        _, bh = obj.wrap(SW-22, H)
        style_h = ParagraphStyle("measureh", fontName="BodyB", fontSize=9.2, leading=12)
        _, hh = Paragraph(escape(clean(item["title"])), style_h).wrap(SW-22, H)
        boxh = hh + bh + 28
        if top-boxh < min_bottom:
            raise ValueError(f"Page {CURRENT_PAGE}: helper {n} overflow {top-boxh}")
        c.setFillColor(PALE)
        c.rect(SX, top-boxh, SW, boxh, fill=1, stroke=0)
        line(c, SX, top, SX+28, top, RUST, 1.6)
        y = para(c, item["title"], SX+11, top-11, SW-22, 9.2, 12,
                 "BodyB", label=f"helper {n+1} heading")-7
        para(c, item["text"], SX+11, y, SW-22, 9.3, 13.5,
             min_bottom=top-boxh+8, label=f"helper {n+1} text")
        top -= boxh+13
    return top


def takeaway(c, text, source_ids=()):
    line(c, M, 166, W-M, 166, RUST, 1.2)
    para(c, "DA TENERE A MENTE", M, 151, 126, 8, 10, "BodyB", RUST, label="takeaway label")
    para(c, text, M+139, 153, CW-139, 10.8, 15.5, "DisplayI", min_bottom=79,
         label="takeaway")
    if source_ids:
        para(c, "FONTI DI CONTESTO: " + " · ".join(source_ids) + " / RIFERIMENTI A PAGINA 31",
             M, 67, CW, 6.8, 8.3, color=MUTED, label="source refs")


def standard(c, page, d):
    base(c, page, d["kicker"], d["title"])
    top = heading(c, d)
    if d.get("kind") == "limits":
        limit_table(c, top)
    else:
        end = blocks(c, d["main"], M, top, MW)
        helpers(c, d["helpers"], top)
        if page == 2:
            line(c, M, end-2, M+MW, end-2)
            y = para(c, "PERCORSI DI LETTURA", M, end-17, MW, 8, 11, "BodyB", RUST)-10
            para(c, "03-08 / Territorio, presentazione e primo incontro\n"
                 "09-12 / Approcci per settore e tipo d'impresa\n"
                 "13-18 / Sei casi applicativi ipotetici\n"
                 "19-30 / Demo, pilot, proposta e obiezioni",
                 M, y, MW, 9.2, 14.5, min_bottom=180, label="reading routes")
    takeaway(c, d["takeaway"], d.get("source_ids", []))
    c.showPage()


def cover(c):
    base(c, 1, "Copertina", "Dalla domanda alla proposta")
    c.setFillColor(INK)
    c.rect(0, H-405, W, 405, fill=1, stroke=0)
    c.drawImage(str(ROOT / "public/brand/askmydocs-wordmark-dark.png"), M, H-82,
                width=150, height=31, preserveAspectRatio=True, mask="auto", anchor="sw")
    para(c, "IL QUADERNO PER I COMMERCIALI", M, H-116, CW, 9.2, 12, "BodyB", HexColor("#d4ad83"))
    para(c, "Dalla domanda\nalla proposta.", M, H-153, CW, 37, 44, "Display", WHITE)
    para(c, "Come proporre AskMyDocs a tessile, moda e pelletteria nell'area fiorentina.",
         M, H-260, 450, 15.2, 21.5, "Display", WHITE)
    para(c, "Approcci, domande, casi applicativi e prove da costruire con il cliente.",
         M, H-323, 430, 11.1, 16.4, color=HexColor("#d6dee1"))
    image_cover(c, "marketing/immagini/guida-commerciale/atelier-tessile.png",
                M, 119, CW, 327)
    para(c, "Una mappa per la conversazione, con i piedi nel lavoro dell'impresa.",
         M, 97, CW, 10, 14, "DisplayI")
    para(c, "Illustrazioni simboliche. I sei casi sono scenari ipotetici e compositi, non testimonianze cliente.",
         M, 70, CW, 7.6, 10.5, color=MUTED)
    c.showPage()


def case_page(c, page, i, case):
    base(c, page, "Casi applicativi", CASE_TITLES[i])
    deck = "REFERENTE / " + case["primary_reference"] + ". Scenario ipotetico e composito."
    d = {"kicker":f"CASO {i+1:02d} / SCENARIO IPOTETICO", "title":CASE_TITLES[i],"deck":deck}
    top = heading(c, d, 26.5)
    image_h = 143
    image_cover(c, CASE_IMAGES[i], M, top-image_h, CW, image_h,
                yfocus=.93 if i in (2,4) else .5)
    para(c, "ILLUSTRAZIONE SIMBOLICA / NON DOCUMENTA UN'INSTALLAZIONE CLIENTE",
         M, top-image_h-7, CW, 6.5, 8.3, color=MUTED, label="image caption")
    top -= image_h + 34
    # The actual discovery question sits in a separate full-width evidence band.
    qstyle = ParagraphStyle("q", fontName="DisplayI", fontSize=10.5, leading=14.6)
    qp = Paragraph(escape(clean(case["concrete_question"])), qstyle)
    _, qh = qp.wrap(CW-24, H)
    c.setFillColor(PALE)
    c.rect(M, top-qh-20, CW, qh+20, fill=1, stroke=0)
    para(c, case["concrete_question"], M+12, top-10, CW-24, 10.5, 14.6, "DisplayI", label="case question")
    top -= qh+40
    end = blocks(c, CASE_COPY[i], M, top, MW, min_bottom=83, size=10.05, leading=14.4)
    sy = para(c, "CINQUE DOMANDE DA FARE", SX, top, SW, 8, 10.2, "BodyB", RUST)-12
    for j, q in enumerate(case["discovery_questions"]):
        sy = para(c, f"{j+1:02d}", SX, sy, 20, 9.3, 12, "DisplayB", RUST)-1
        sy = para(c, q, SX+23, sy+13, SW-23, 9.05, 12.7, min_bottom=83,
                  label=f"discovery question {j+1}")-14
    refs = " · ".join(case["territory_source_ids"])
    para(c, "CONTESTO: " + refs + " / LE FONTI DESCRIVONO LA FILIERA, NON UN BISOGNO ACCERTATO.",
         M, 67, CW, 6.6, 8.5, color=MUTED, label="source refs")
    c.showPage()


def limit_table(c, top):
    # Fixed, readable body text; row heights derive from both text cells.
    cols = [155, CW-155]
    top -= 1
    c.setFillColor(INK)
    c.rect(M, top-28, CW, 28, fill=1, stroke=0)
    para(c, "ARGOMENTO", M+10, top-8, cols[0]-20, 8.5, 11, "BodyB", WHITE)
    para(c, "CONDIZIONE DA ESPLICITARE", M+cols[0]+10, top-8, cols[1]-20, 8.5, 11, "BodyB", WHITE)
    top -= 28
    for i, (left, right) in enumerate(LIMIT_ROWS):
        a = Paragraph(escape(clean(left)), ParagraphStyle("a", fontName="BodyB", fontSize=9, leading=12.6))
        b = Paragraph(escape(clean(right)), ParagraphStyle("b", fontName="Body", fontSize=9.1, leading=12.6))
        ah = a.wrap(cols[0]-20,H)[1]
        bh = b.wrap(cols[1]-20,H)[1]
        rh = max(ah,bh)+16
        if top-rh < 180:
            raise ValueError(f"Limit table overflow at row {i}: {top-rh}")
        c.setFillColor(WHITE if i%2==0 else PALE)
        c.rect(M, top-rh, CW, rh, fill=1, stroke=0)
        para(c, left, M+10, top-8, cols[0]-20, 9, 12.6, "BodyB", label=f"limit {i+1} title")
        para(c, right, M+cols[0]+10, top-8, cols[1]-20, 9.1, 12.6, label=f"limit {i+1} condition")
        top -= rh


def sources_page(c):
    base(c,31,"Fonti","Le fonti del territorio e del prodotto")
    d={"kicker":"RIFERIMENTI / 29 OTTOBRE 2026", "title":"Le fonti della guida.",
       "deck":"Fonti primarie consultate per qualificare il contesto e il lessico della filiera. Le descrizioni aziendali non sono referenze AskMyDocs."}
    top=heading(c,d,26)
    width=(CW-26)/2
    for col, items in enumerate([SOURCES[:7], SOURCES[7:]]):
        x=M+col*(width+26)
        y=top
        for s in items:
            date=s.get("published_at") or "s.d. / letta il 29.10.2026"
            publisher=s["publisher"].replace(", Centro Studi", "")
            y=para(c,f'{s["id"]} / {publisher}',x,y,width,8.3,11.2,"BodyB",RUST,label="source publisher")-3
            y=para(c,s["title"],x,y,width,9.2,12.5,"Display",label="source title")-4
            domain=urlparse(s["url"]).hostname.removeprefix("www.")
            y=para(c,date+" · "+domain,x,y,width,7.7,10.2,color=MUTED,label="source metadata")-4
            # Use a legible human link label; preserve the full URL in the PDF annotation.
            c.setFillColor(INK)
            c.setFont("BodyB",7.7)
            c.drawString(x,y-8,"Apri la fonte")
            c.linkURL(s["url"],(x,y-10,x+64,y+2),relative=0,thickness=0)
            y-=23
        if y<143:
            raise ValueError(f"Sources col {col} overflow {y}")
    line(c,M,135,W-M,135,RUST,1.2)
    para(c,"IL RIFERIMENTO PRODOTTO",M,122,CW,8,10,"BodyB",RUST)
    para(c,"Funzioni e condizioni sono riferite all'analisi del codice e della documentazione AskMyDocs, snapshot "
         +REVISION[:12]+". Le schede di ricerca e il registro delle prove sono conservati nei sorgenti marketing. "
         "Le indagini 2023-2024 sono contesto storico: non sono stime del mercato 2026. Nessuna efficacia commerciale è stata misurata nei sei scenari.",
         M,103,CW,8.5,12,color=MUTED,min_bottom=52)
    c.showPage()


def closing(c):
    base(c,32,"Prossimo passo","Portare una domanda vera")
    para(c,"IL PROSSIMO PASSO",M,H-73,CW,8.4,11,"BodyB",RUST)
    para(c,"Portare\nuna domanda vera.",M,H-106,CW,35,42,"Display")
    para(c,"Una fonte riconoscibile. Una persona che la conosce.\nUna prova da valutare insieme.",
         M,H-216,CW,14,20,"DisplayI",MUTED)
    image_cover(c,"marketing/immagini/guida-commerciale/atelier-confronto-sera.png",M,211,CW,300)
    para(c,"01 / Ricostruire l'episodio",M,185,CW,12,16,"DisplayB")
    para(c,"02 / Scegliere le fonti ammesse",M,155,CW,12,16,"DisplayB")
    para(c,"03 / Concordare il criterio della prova",M,125,CW,12,16,"DisplayB")
    para(c,"Questo quaderno accompagna la conversazione. Il progetto comincia quando problema, perimetro e responsabilità diventano chiari per tutti.",
         M,89,CW,10.5,15,"DisplayI",min_bottom=52)
    c.showPage()


def check_boxes():
    """Check actual paragraph rectangles, excluding deliberate decorative layers."""
    conflicts=[]
    for i,a in enumerate(BOXES):
        if a["h"]<=0: continue
        for b in BOXES[i+1:]:
            if a["page"]!=b["page"]: continue
            ox=min(a["x"]+a["w"],b["x"]+b["w"])-max(a["x"],b["x"])
            oy=min(a["y"]+a["h"],b["y"]+b["h"])-max(a["y"],b["y"])
            if ox>.8 and oy>.8:
                conflicts.append({"page":a["page"],"a":a["label"],"b":b["label"],"overlap":[ox,oy]})
    if conflicts:
        raise ValueError("Paragraph collisions: "+json.dumps(conflicts,ensure_ascii=False))
    return conflicts


def main():
    OUT.parent.mkdir(parents=True,exist_ok=True)
    QA.mkdir(parents=True,exist_ok=True)
    c=canvas.Canvas(str(OUT),pagesize=A4,pageCompression=1)
    c.setTitle(TITLE)
    c.setAuthor("AskMyDocs / Marketing")
    c.setSubject(SUBTITLE)
    c.setCreator("AskMyDocs marketing / ReportLab / sorgenti editoriali verificati")
    cover(c)
    page=2
    for d in FRONT:
        standard(c,page,d)
        page+=1
    for i,case in enumerate(RESEARCH["cases"]):
        case_page(c,page,i,case)
        page+=1
    for d in FOLLOW:
        standard(c,page,d)
        page+=1
    assert page==31,page
    sources_page(c)
    closing(c)
    check_boxes()
    c.save()
    (QA/"page-map.json").write_text(json.dumps(PAGE_MAP,ensure_ascii=False,indent=2)+"\n")
    (QA/"layout-boxes.json").write_text(json.dumps(BOXES,ensure_ascii=False,indent=2)+"\n")
    print(json.dumps({"output":str(OUT),"pages":len(PAGE_MAP),"paragraphs":len(BOXES),
        "paragraph_collisions":0,"sha256":hashlib.sha256(OUT.read_bytes()).hexdigest()},indent=2))


if __name__=="__main__":
    main()
