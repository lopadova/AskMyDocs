#!/usr/bin/env python3
"""Render an independent, selectable-text editorial product PDF from JSON.

Usage: python render_editorial.py --input content.json --project-root /project
       --output /project/marketing/product.pdf --qa-dir /project/marketing/qa
       [--overwrite]
Dependencies: reportlab, Pillow, pypdf. No network, source-builder imports or
product-specific assets are used. All logo/image/font paths resolve INSIDE
project-root, including symlinks. Output is replaced atomically only after QA.

Practical schema (see assets/example-content.json for all eight layouts):
  document: {title, brand_name, author?, subject?, edition?, header?,
             footer_note?, source_ids?: [str], feature_ids?: [str]}
  theme: {
    palette: {paper, cream, ink, primary, accent, muted, line, pale: '#RRGGBB'},
    palette_source?: str,  # identify this project's landing/design tokens
    fonts: {heading: font_spec, body: font_spec},
    logo?: image_spec|null, folio_format?: '{page:02d}',
    images?: {jpeg_quality: 88, max_dpi: 220},
    typography?: {title:31.5, title_leading:37, body:10.3, body_leading:15}}
  font_spec: {regular?: project-relative TTF, bold?: TTF, italic?: TTF,
              bold_italic?: TTF, fallback?: explicit standard PDF family}
    Supported fallbacks: Times-Roman, Helvetica, Courier. No implicit fallback.
    A missing regular file uses fallback ONLY when declared and reports it.
    Omitted custom variants reuse regular and are reported; no synthetic style.
  image_spec: {path: project-relative asset, fit?: 'cover'|'contain',
               focus?: [x,y], height?: points, caption?: str}
    focus is the source point (0..1 each axis) placed near the crop centre.
    Proportions are preserved. Images are EXIF-corrected, downsampled and JPEG
    compressed; transparent assets composite over their intended background.
  pages: [{id?, layout, section?, kicker?, title, deck?, source_ids?,
           feature_ids?, ...layout fields...}]
  block: {heading?: str, text?: str|[str]}; strings are plain text, not HTML.
  callout: str|{text, height?:80}; image and callout may coexist if they fit.

Layout fields:
  cover: display_name?, image?, band_title?, band_subtitle?, show_folio?:bool.
  article: columns: [[block],[block]], image? (default strip 105pt), callout?.
  journey: steps:[{number?,heading,text}], callout?.
  case: image? (152pt), columns:[[block],[block]], callout?.
  divider: image? (270pt), quote?.
  table: headers:[str], rows:[[str]], column_weights?:[number], note?,
         table_font?:8.8, padding?:6.
  references: intro?, items:[{id?,title,detail?,url?}], callout?.
  closing: image? (260pt), quote?.

A4 is fixed at 595.276 x 841.89pt, margins 45pt, two columns, 28pt gap.
Layout is explicit, not auto-paginated: overflow, missing glyphs, invalid assets,
or semantic-box collisions raise an error instead of shrinking or cutting text.
Decorative backgrounds/rules are excluded from collision checks; table cells
are checked within their own container. Folios use the actual page count.

QA outputs: page-map.json (source/feature lineage), layout.json (all measured
boxes, crop/compression data, font choices), render-report.json (pass/fail,
selectable text and JPEG checks). Page source_ids/feature_ids also appear in
custom PDF page metadata. QA does not replace visual inspection: render every
page with Poppler and inspect before delivery. No PDF operation marker is run
inside this script: the calling skill runs its required marker once per task.
"""
from __future__ import annotations

import argparse
import hashlib
import html
import json
import math
import os
import re
import sys
import uuid
from io import BytesIO
from pathlib import Path
from urllib.parse import urlparse

from PIL import Image, ImageOps
from pypdf import PdfReader, PdfWriter
from pypdf.generic import ArrayObject, NameObject, TextStringObject
from reportlab.lib import colors
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas
from reportlab.platypus import Paragraph, Table, TableStyle

W, H, M, GAP = 595.276, 841.89, 45.0, 28.0
WIDTH = W - 2*M
CW = (WIDTH-GAP)/2
PALETTES = ('paper','cream','ink','primary','accent','muted','line','pale')
LAYOUTS = {'cover','article','journey','case','divider','table','references','closing'}
FALLBACKS = {
    'Times-Roman': ('Times-Roman','Times-Bold','Times-Italic','Times-BoldItalic'),
    'Helvetica': ('Helvetica','Helvetica-Bold','Helvetica-Oblique','Helvetica-BoldOblique'),
    'Courier': ('Courier','Courier-Bold','Courier-Oblique','Courier-BoldOblique'),
}


class EditorialError(ValueError):
    pass


def safe(value):
    return str(value).replace('\u2011','-').replace('\u2013','-').replace('\u2014','-')


def flat(value):
    return re.sub(r'\s+','',safe(value))


def positive(value, name, low=.1, high=2000):
    if isinstance(value, bool) or not isinstance(value,(int,float)) or not math.isfinite(value) or not low<=value<=high:
        raise EditorialError(f'{name} must be a finite number in {low}..{high}')
    return float(value)


def ids(value, name):
    if not isinstance(value,list) or any(not isinstance(v,str) or not v.strip() for v in value):
        raise EditorialError(f'{name} must be a list of non-empty strings')
    return list(dict.fromkeys(value))


class Renderer:
    def __init__(self, data, root, qa):
        self.data, self.root, self.qa = data, root.resolve(), qa
        self.doc, self.theme = data['document'], data['theme']
        self.pages = data['pages']
        self.boxes, self.map, self.warnings, self.findings = [], [], [], []
        self.current, self.page_id, self.c = 0, '', None
        self.fonts, self.font_records = {}, {}
        self.validate()
        self.color = {k:colors.HexColor(self.theme['palette'][k]) for k in PALETTES}
        self.register_fonts()

    def validate(self):
        for name in ('title','brand_name'):
            if not isinstance(self.doc.get(name),str) or not self.doc[name].strip():
                raise EditorialError(f'document.{name} is required')
        if not isinstance(self.pages,list) or not self.pages:
            raise EditorialError('pages must contain at least one explicit page')
        palette=self.theme.get('palette',{})
        for key in PALETTES:
            if not re.fullmatch(r'#[0-9a-fA-F]{6}',str(palette.get(key,''))):
                raise EditorialError(f'theme.palette.{key} must be #RRGGBB from the project identity')
        used=set()
        for i,p in enumerate(self.pages,1):
            if p.get('layout') not in LAYOUTS:raise EditorialError(f'Page {i}: unknown layout')
            if not isinstance(p.get('title'),str) or not p['title'].strip():raise EditorialError(f'Page {i}: title is required')
            pid=p.get('id',f'page-{i:03d}')
            if not isinstance(pid,str) or not re.fullmatch(r'[A-Za-z0-9_.-]+',pid) or pid in used:
                raise EditorialError(f'Page {i}: id must be unique and use letters, numbers, . _ -')
            used.add(pid)
            ids(p.get('source_ids',[]),f'Page {i}.source_ids');ids(p.get('feature_ids',[]),f'Page {i}.feature_ids')
        for key in ('source_ids','feature_ids'):ids(self.doc.get(key,[]),'document.'+key)

    def asset(self, value):
        if not isinstance(value,str) or not value:raise EditorialError('Asset path must be non-empty')
        candidate=(self.root/value).resolve()
        if not candidate.is_relative_to(self.root):raise EditorialError(f'Asset escapes project-root: {value}')
        return candidate

    def register_fonts(self):
        for role in ('heading','body'):
            spec=self.theme.get('fonts',{}).get(role)
            if not isinstance(spec,dict):raise EditorialError(f'theme.fonts.{role} is required')
            fallback=spec.get('fallback')
            if fallback is not None and fallback not in FALLBACKS:raise EditorialError(f'Unsupported explicit fallback: {fallback}')
            regular_path=self.asset(spec['regular']) if spec.get('regular') else None
            if regular_path is None or not regular_path.is_file():
                if fallback is None:raise EditorialError(f'{role}: supply a project regular font or an explicit fallback')
                family=FALLBACKS[fallback]
                self.warnings.append(f'{role}: explicit fallback {fallback} selected'+(f'; regular file missing: {spec["regular"]}' if regular_path else ''))
                self.font_records[role]={'mode':'explicit_fallback','family':fallback,'files':{}}
            else:
                family=[];files={}
                for variant in ('regular','bold','italic','bold_italic'):
                    font_path=self.asset(spec[variant]) if spec.get(variant) else regular_path
                    if not font_path.is_file():raise EditorialError(f'Missing declared {role}.{variant}: {font_path}')
                    if variant!='regular' and not spec.get(variant):self.warnings.append(f'{role}.{variant}: regular project font reused')
                    name='Project_'+role+'_'+variant+'_'+hashlib.sha256(str(font_path).encode()).hexdigest()[:10]
                    try:pdfmetrics.registerFont(TTFont(name,str(font_path)))
                    except Exception as e:raise EditorialError(f'Cannot load {role}.{variant} font: {e}') from e
                    family.append(name);files[variant]=str(font_path.relative_to(self.root))
                family=tuple(family)
                self.font_records[role]={'mode':'project_files','files':files,'fallback_declared':fallback}
            alias='EditorialHeading' if role=='heading' else 'EditorialBody'
            pdfmetrics.registerFontFamily(alias,normal=family[0],bold=family[1],italic=family[2],boldItalic=family[3])
            self.fonts[role]=family

    def glyphs(self,text,font):
        face=pdfmetrics.getFont(font).face
        visible=safe(text).replace('\n','').replace('\t','')
        if any(ord(ch)<32 for ch in visible):raise EditorialError(f'Page {self.current}: unsupported control character in text')
        if hasattr(face,'charToGlyph'):
            missing=sorted({ch for ch in visible if ord(ch) not in face.charToGlyph})
        else:
            missing=[]
            for ch in visible:
                try:ch.encode('cp1252')
                except UnicodeEncodeError:missing.append(ch)
        if missing:raise EditorialError(f'Page {self.current}: font {font} lacks glyphs {missing[:12]}')

    def paragraph(self,text,width,role='body',size=10.3,leading=15,color='ink',variant=0):
        size=positive(size,'font size',5,100);leading=positive(leading,'leading',size,140)
        width=positive(width,'paragraph width',8,W)
        font=self.fonts[role][variant];value=safe(text)
        self.glyphs(value,font)
        style=ParagraphStyle('editorial',fontName=font,fontSize=size,leading=leading,
            textColor=self.color[color],splitLongWords=True,allowWidows=0,allowOrphans=0,
            spaceBefore=0,spaceAfter=0)
        p=Paragraph(html.escape(value).replace('\n','<br/>'),style)
        _,height=p.wrap(width,H*100)
        return p,height

    def box(self,kind,x,bottom,width,height,*,text=None,zone='content',container=None,extra=None):
        if any(not math.isfinite(v) for v in (x,bottom,width,height)) or width<0 or height<0:
            raise EditorialError(f'Page {self.current}: invalid {kind} geometry')
        record={'page':self.current,'page_id':self.page_id,'kind':kind,'x':round(x,3),'y':round(bottom,3),
                'width':round(width,3),'height':round(height,3),'top':round(bottom+height,3),'zone':zone}
        if text is not None:record['text']=safe(text)
        if container:record['container']=container
        if extra:record.update(extra)
        if x<-.1 or bottom<-.1 or x+width>W+.1 or bottom+height>H+.1:
            self.findings.append({'type':'bounds','box':record})
            raise EditorialError(f'Page {self.current}: {kind} leaves A4 bounds')
        if zone=='content' and (x<M-.1 or x+width>W-M+.1 or bottom<74-.1 or bottom+height>H-66+.1):
            self.findings.append({'type':'content_bounds','box':record})
            raise EditorialError(f'Page {self.current}: {kind} leaves the content area (45pt margins, footer/header reserve)')
        # Table cells are children of a checked table, not separate competing boxes.
        if not container:
            for other in self.boxes:
                if other['page']!=self.current or other.get('container'):continue
                ix=min(x+width,other['x']+other['width'])-max(x,other['x'])
                iy=min(bottom+height,other['y']+other['height'])-max(bottom,other['y'])
                if ix>.25 and iy>.25:
                    self.findings.append({'type':'collision','a':record,'b':other})
                    raise EditorialError(f'Page {self.current}: collision between {kind} and {other["kind"]}')
        self.boxes.append(record)
        return record

    def text(self,value,x,top,width,*,size=10.3,leading=15,role='body',color='ink',variant=0,
             bottom=74,kind='paragraph',zone='content',one_line=False):
        if value is None or value=='':return top
        if not isinstance(value,str):raise EditorialError(f'Page {self.current}: text must be a string')
        p,height=self.paragraph(value,width,role,size,leading,color,variant)
        if one_line and height>leading+.1:raise EditorialError(f'Page {self.current}: {kind} must fit one line')
        if top-height<bottom-.1:
            self.findings.append({'type':'overflow','page':self.current,'kind':kind,'required_pt':height,'available_pt':top-bottom,'text':safe(value)})
            raise EditorialError(f'Page {self.current}: {kind} overflow; needs {height:.2f}pt, has {top-bottom:.2f}pt')
        self.box(kind,x,top-height,width,height,text=value,zone=zone,
                 extra={'font':self.fonts[role][variant],'font_size':size,'leading':leading})
        p.drawOn(self.c,x,top-height)
        return top-height

    def rect(self,x,y,w,h,color):
        self.c.setFillColor(self.color[color]);self.c.rect(x,y,w,h,fill=1,stroke=0)

    def rule(self,x,y,width,color='line',weight=.5):
        self.c.setStrokeColor(self.color[color]);self.c.setLineWidth(weight);self.c.line(x,y,x+width,y)

    def image(self,spec,x,y,width,height,*,kind='image',zone='content',background='paper'):
        if spec is None:return
        if isinstance(spec,str):spec={'path':spec}
        if not isinstance(spec,dict):raise EditorialError('image must be an object, path string or null')
        source=self.asset(spec.get('path'));fit=spec.get('fit','cover')
        if not source.is_file():raise EditorialError(f'Missing image: {source}')
        if fit not in ('cover','contain'):raise EditorialError(f'Invalid image fit: {fit}')
        focus=spec.get('focus',[.5,.5])
        if not isinstance(focus,list) or len(focus)!=2:raise EditorialError('Image focus must be [x,y]')
        fx,fy=(positive(n,'focus',0,1) for n in focus)
        positive(width,'image width');positive(height,'image height')
        try:
            with Image.open(source) as raw:im=ImageOps.exif_transpose(raw).copy()
        except Exception as e:raise EditorialError(f'Cannot decode image {source}: {e}') from e
        iw,ih=im.size;crop=(0,0,iw,ih)
        if fit=='cover':
            ratio=width/height;crop_w=min(iw,ih*ratio);crop_h=min(ih,iw/ratio)
            left=max(0,min(iw-crop_w,fx*iw-crop_w/2));top=max(0,min(ih-crop_h,fy*ih-crop_h/2))
            crop=(left,top,left+crop_w,top+crop_h);im=im.crop(tuple(round(n) for n in crop))
            dx,dy,dw,dh=x,y,width,height
        else:
            scale=min(width/iw,height/ih);dw,dh=iw*scale,ih*scale;dx,dy=x+(width-dw)/2,y+(height-dh)/2
        setting=self.theme.get('images',{})
        dpi=positive(setting.get('max_dpi',220),'max_dpi',72,600)
        quality=int(positive(setting.get('jpeg_quality',88),'jpeg_quality',40,95))
        im.thumbnail((max(1,math.ceil(dw/72*dpi)),max(1,math.ceil(dh/72*dpi))),Image.Resampling.LANCZOS)
        if im.mode=='RGBA' or 'transparency' in im.info:
            rgba=im.convert('RGBA');bg=Image.new('RGB',rgba.size,self.theme['palette'][background]);bg.paste(rgba,mask=rgba.getchannel('A'));im=bg
        else:im=im.convert('RGB')
        encoded=BytesIO();im.save(encoded,format='JPEG',quality=quality,optimize=True);encoded.seek(0)
        self.box(kind,dx,dy,dw,dh,zone=zone,extra={'asset':str(source.relative_to(self.root)),'fit':fit,
             'focus':[fx,fy],'source_pixels':[iw,ih],'crop_pixels':[round(v,2) for v in crop],
             'embedded_pixels':list(im.size),'jpeg_bytes':len(encoded.getvalue()),'jpeg_quality':quality})
        self.c.drawImage(ImageReader(encoded),dx,dy,width=dw,height=dh)

    def callout_spec(self,value):
        return {'text':value,'height':80} if isinstance(value,str) else value

    def callout(self,value,*,y=93,height=None):
        spec=self.callout_spec(value)
        if not isinstance(spec,dict) or not isinstance(spec.get('text'),str):raise EditorialError('callout requires text')
        h=positive(height or spec.get('height',80),'callout height',35,300)
        self.rect(M,y,WIDTH,h,'pale');self.rect(M,y,3,h,'primary')
        self.text(spec['text'],M+17,y+h-14,WIDTH-34,role='heading',variant=2,size=11.3,
                  leading=16,color='primary',bottom=y+10,kind='callout')
        return y+h

    def frame(self,p):
        self.rect(0,0,W,H,'paper')
        header=self.doc.get('header') or f'{self.doc["brand_name"]} / {self.doc["title"]}'
        self.text(header.upper(),M,H-24,WIDTH,size=8,leading=10,variant=1,zone='header',bottom=H-44,one_line=True,kind='running_header')
        self.rule(M,H-45,WIDTH);self.rule(M,43,WIDTH)
        self.text(p.get('section',self.doc['brand_name']).upper(),M,34,WIDTH-65,size=7.3,leading=9,variant=1,zone='footer',bottom=22,one_line=True,kind='running_section')
        folio=self.folio();self.text(folio,W-M-55,35,55,role='heading',size=10,leading=12,zone='footer',bottom=22,one_line=True,kind='folio')
        self.text(self.doc.get('footer_note',''),M,65,WIDTH,size=7.15,leading=10,color='muted',zone='footer',bottom=44,kind='edition_note')

    def folio(self):
        try:return self.theme.get('folio_format','{page:02d}').format(page=self.current,total=len(self.pages))
        except (ValueError,KeyError,AttributeError,IndexError) as e:raise EditorialError(f'Invalid folio_format: {e}') from e

    def title(self,p):
        self.text(p.get('kicker',''),M,H-73,WIDTH,size=8.2,leading=10,variant=1,color='accent',kind='kicker')
        typo=self.theme.get('typography',{})
        y=self.text(p['title'],M,H-106,WIDTH,role='heading',size=typo.get('title',31.5),leading=typo.get('title_leading',37),kind='title')
        y=self.text(p.get('deck',''),M,y-17,WIDTH,size=11.5,leading=17,color='muted',kind='deck')
        self.rule(M,y-20,WIDTH)
        return y-43

    def blocks(self,items,x,top,width,bottom):
        if not isinstance(items,list):raise EditorialError('columns must contain lists of blocks')
        typo=self.theme.get('typography',{});y=top
        for b in items:
            if not isinstance(b,dict):raise EditorialError('block must be an object')
            if b.get('heading'):
                y=self.text(b['heading'],x,y,width,role='heading',size=14.8,leading=19,bottom=bottom,kind='block_heading')-10
            content=b.get('text','');content='\n\n'.join(content) if isinstance(content,list) else content
            y=self.text(content,x,y,width,size=typo.get('body',10.3),leading=typo.get('body_leading',15),bottom=bottom)-25
        return y

    def columns(self,p,top,bottom):
        cols=p.get('columns')
        if not isinstance(cols,list) or len(cols)!=2:raise EditorialError(f'Page {self.current}: columns must contain exactly two lists')
        for i,blocks in enumerate(cols):self.blocks(blocks,M+i*(CW+GAP),top,CW,bottom)

    def article(self,p):
        self.frame(p);top=self.title(p);reserved=173
        if p.get('callout'):reserved=self.callout(p['callout'])
        if p.get('image'):
            im=p['image'] if isinstance(p['image'],dict) else {'path':p['image']}
            h=positive(im.get('height',105),'article image height',30,400)
            bottom=reserved+35 if p.get('callout') else 93
            self.image(im,M,bottom,WIDTH,h)
            self.text(im.get('caption',''),M,bottom-10,WIDTH,size=7.5,leading=10,color='muted',bottom=74,kind='image_caption')
            reserved=bottom+h
        self.columns(p,top,reserved+25)

    def cover(self,p):
        self.rect(0,0,W,H,'cream')
        if self.theme.get('logo'):
            logo=self.theme['logo'];logo={'path':logo} if isinstance(logo,str) else dict(logo)
            logo['fit']='contain';self.image(logo,M-3,H-98,190,55,kind='logo',zone='cover',background='cream')
        self.text(p.get('kicker',''),W-226,H-50,181,size=8,leading=10,color='primary',variant=1,zone='cover',bottom=H-65,one_line=True,kind='cover_kicker')
        self.text(self.doc.get('edition',''),W-226,H-66,181,size=8,leading=10,color='primary',variant=1,zone='cover',bottom=H-81,one_line=True,kind='cover_edition')
        self.text(p.get('display_name',self.doc['brand_name']),M,H-132,WIDTH,role='heading',size=55,leading=66,color='primary',zone='cover',kind='display_name')
        self.text(p['title'],M,H-204,WIDTH,role='heading',size=39,leading=44,zone='cover',kind='cover_title')
        self.text(p.get('deck',''),M+2,H-313,WIDTH-2,size=13.3,leading=19,color='muted',bottom=468,zone='cover',kind='cover_deck')
        if p.get('image'):self.image(p['image'],0,66,W,382,zone='cover',background='cream')
        self.rect(0,0,W,66,'primary')
        self.text(p.get('band_title',''),M,47,WIDTH,role='body',variant=1,size=9.5,leading=12,color='cream',zone='cover',bottom=34,one_line=True,kind='cover_band_title')
        self.text(p.get('band_subtitle',''),M,29,WIDTH-65,size=7.5,leading=10,color='cream',zone='cover',bottom=14,kind='cover_band_subtitle')
        if p.get('show_folio',True):self.text(self.folio(),W-M-55,30,55,role='heading',size=10,leading=12,color='cream',zone='cover',bottom=14,one_line=True,kind='folio')

    def journey(self,p):
        self.frame(p);y=self.title(p);bottom=90
        if p.get('callout'):bottom=self.callout(p['callout'],y=89)+25
        steps=p.get('steps')
        if not isinstance(steps,list) or not steps:raise EditorialError('journey.steps must not be empty')
        for i,step in enumerate(steps,1):
            self.rect(M,y-33,36,36,'pale')
            self.text(str(step.get('number',f'{i:02d}')),M+6,y-2,30,role='heading',size=17,leading=20,color='primary',bottom=bottom,kind='step_number',one_line=True)
            by=self.text(step['heading'],M+54,y+2,WIDTH-54,role='heading',size=16,leading=21,bottom=bottom,kind='step_heading')
            by=self.text(step['text'],M+54,by-8,WIDTH-54,size=10.6,leading=15,bottom=bottom,kind='step_body')
            y=min(y-76,by-18)

    def case(self,p):
        self.frame(p);y=self.title(p);bottom=89
        if p.get('callout'):bottom=self.callout(p['callout'],y=89)+25
        if p.get('image'):
            im=p['image'] if isinstance(p['image'],dict) else {'path':p['image']}
            h=positive(im.get('height',152),'case image height',30,400)
            self.image(im,M,y-h,WIDTH,h)
            self.text(im.get('caption',''),M,y-h-10,WIDTH,size=7.6,leading=10,color='muted',bottom=bottom,kind='image_caption')
            y-=h+43
        self.columns(p,y,bottom)

    def divider(self,p):
        self.frame(p)
        self.text(p.get('kicker',''),M,H-92,WIDTH,size=8.2,leading=10,color='accent',variant=1,kind='kicker')
        y=self.text(p['title'],M,H-139,WIDTH,role='heading',size=37,leading=43,kind='title')
        image=p.get('image');height=positive(image.get('height',270),'divider image height',30,400) if isinstance(image,dict) else 270
        self.text(p.get('deck',''),M,y-20,WIDTH,size=12,leading=18,color='muted',bottom=190+height+20 if image else 190,kind='deck')
        if image:self.image(image,M,190,WIDTH,height)
        self.text(p.get('quote',''),M,152,WIDTH,role='heading',variant=2,size=12,leading=17,bottom=90,kind='quote')

    def table(self,p):
        self.frame(p);y=self.title(p)+4
        headers,rows=p.get('headers'),p.get('rows')
        if not isinstance(headers,list) or not headers or any(not isinstance(h,str) for h in headers):raise EditorialError('table.headers must be strings')
        if not isinstance(rows,list) or any(not isinstance(r,list) or len(r)!=len(headers) or any(not isinstance(v,str) for v in r) for r in rows):raise EditorialError('table.rows must match header count and contain strings')
        weights=p.get('column_weights',[1]*len(headers))
        if not isinstance(weights,list) or len(weights)!=len(headers):raise EditorialError('column_weights must match headers')
        weights=[positive(n,'column weight') for n in weights];widths=[WIDTH*n/sum(weights) for n in weights]
        size=positive(p.get('table_font',8.8),'table font',7,20);pad=positive(p.get('padding',6),'table padding',2,20)
        cells=[]
        for row_index,row in enumerate([headers]+rows):
            line=[]
            for j,cell in enumerate(row):line.append(self.paragraph(cell,widths[j]-2*pad,size=size,leading=size+3.3,color='cream' if row_index==0 else 'ink',variant=1 if row_index==0 else 0)[0])
            cells.append(line)
        table=Table(cells,colWidths=widths,hAlign='LEFT')
        table.setStyle(TableStyle([('BACKGROUND',(0,0),(-1,0),self.color['primary']),('VALIGN',(0,0),(-1,-1),'TOP'),('LEFTPADDING',(0,0),(-1,-1),pad),('RIGHTPADDING',(0,0),(-1,-1),pad),('TOPPADDING',(0,0),(-1,-1),pad),('BOTTOMPADDING',(0,0),(-1,-1),pad),('ROWBACKGROUNDS',(0,1),(-1,-1),[self.color['cream'],self.color['paper']]),('LINEBELOW',(0,1),(-1,-1),.35,self.color['line'])]))
        _,h=table.wrap(W,H*100)
        note_height=self.paragraph(p['note'],WIDTH,size=8.7,leading=12.5,color='muted')[1] if p.get('note') else 0
        if y-h<max(90,77+note_height+17):raise EditorialError(f'Page {self.current}: table overflow, needs {h:.2f}pt')
        self.box('table',M,y-h,WIDTH,h,extra={'rows':len(rows),'columns':len(headers),'row_heights':table._rowHeights})
        table.drawOn(self.c,M,y-h)
        cy=y
        for i,row in enumerate([headers]+rows):
            xx=M
            for j,value in enumerate(row):
                self.box('table_cell',xx,cy-table._rowHeights[i],widths[j],table._rowHeights[i],text=value,container=f'table-{self.current}')
                xx+=widths[j]
            cy-=table._rowHeights[i]
        self.text(p.get('note',''),M,y-h-17,WIDTH,size=8.7,leading=12.5,color='muted',bottom=77,kind='table_note')

    def references(self,p):
        self.frame(p);y=self.title(p);bottom=90
        if p.get('callout'):bottom=self.callout(p['callout'],height=72)+25
        if p.get('intro'):y=self.text(p['intro'],M,y,WIDTH,size=10.3,leading=15,bottom=bottom,kind='references_intro')-28
        items=p.get('items')
        if not isinstance(items,list):raise EditorialError('references.items must be a list')
        split=math.ceil(len(items)/2)
        for col,entries in enumerate((items[:split],items[split:])):
            cy=y;x=M+col*(CW+GAP)
            for entry in entries:
                top=cy;cy=self.text(entry['title'],x,cy,CW,role='heading',size=11.3,leading=15,color='primary',bottom=bottom,kind='reference_title')
                if entry.get('url'):
                    if urlparse(entry['url']).scheme not in ('http','https','mailto'):raise EditorialError('Reference URL requires http, https or mailto scheme')
                    self.c.linkURL(entry['url'],(x,cy,x+CW,top),relative=0,thickness=0)
                if entry.get('detail'):cy=self.text(entry['detail'],x,cy-5,CW,size=7.8,leading=10.7,color='muted',bottom=bottom,kind='reference_detail')
                cy-=19

    def closing(self,p):
        self.frame(p)
        self.text(p.get('kicker',''),M,H-84,WIDTH,size=8.2,leading=10,color='accent',variant=1,kind='kicker')
        y=self.text(p['title'],M,H-125,WIDTH,role='heading',size=36,leading=43,kind='title')
        image=p.get('image');height=positive(image.get('height',260),'closing image height',30,400) if isinstance(image,dict) else 260
        self.text(p.get('deck',''),M,y-26,WIDTH,size=13,leading=20,color='muted',bottom=168+height+20 if image else 190,kind='deck')
        if image:self.image(image,M,168,WIDTH,height)
        self.text(p.get('quote',''),M,137,WIDTH,role='heading',variant=2,size=12.5,leading=18,bottom=78,kind='quote')

    def render(self,path):
        self.c=canvas.Canvas(str(path),pagesize=(W,H),pageCompression=1)
        self.c.setTitle(self.doc['title']);self.c.setAuthor(self.doc.get('author',self.doc['brand_name']));self.c.setSubject(self.doc.get('subject',''))
        for number,p in enumerate(self.pages,1):
            self.current=number;self.page_id=p.get('id',f'page-{number:03d}')
            self.map.append({'page':number,'id':self.page_id,'layout':p['layout'],'title':p['title'],
                'section':p.get('section',''),'source_ids':ids(p.get('source_ids',[]),'source_ids'),
                'feature_ids':ids(p.get('feature_ids',[]),'feature_ids')})
            self.c.bookmarkPage(self.page_id);self.c.addOutlineEntry(safe(p.get('section',p['title'])).replace('\n',' '),self.page_id,0)
            getattr(self,p['layout'])(p);self.c.showPage()
        self.c.save()

    def qa_write(self,status,error=None,audit=None):
        self.qa.mkdir(parents=True,exist_ok=True)
        mapping={'document':self.doc,'page_count':len(self.pages),'pages':self.map}
        layout={'status':status,'page_size_pt':[W,H],'margins_pt':M,'column_width_pt':CW,'column_gap_pt':GAP,
            'coordinate_origin':'bottom-left','fonts':self.font_records,'palette_source':self.theme.get('palette_source'),
            'warnings':self.warnings,'findings':self.findings,'boxes':self.boxes}
        report={'status':status,'error':error,'page_count':len(self.pages),'pages_processed':len(self.map),
                'collision_count':len([f for f in self.findings if f['type']=='collision']),'warnings':self.warnings,
                'requires_visual_review':True,'audit':audit}
        for name,data in [('page-map.json',mapping),('layout.json',layout),('render-report.json',report)]:
            (self.qa/name).write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n')


def finalize_pdf(raw,staged,renderer):
    reader=PdfReader(str(raw));writer=PdfWriter();writer.clone_document_from_reader(reader)
    all_sources=ids(renderer.doc.get('source_ids',[]),'document.source_ids')
    all_features=ids(renderer.doc.get('feature_ids',[]),'document.feature_ids')
    for page,meta in zip(writer.pages,renderer.map):
        for key,field in [('/EditorialSourceIds','source_ids'),('/EditorialFeatureIds','feature_ids')]:
            page[NameObject(key)]=ArrayObject([TextStringObject(v) for v in meta[field]])
        page[NameObject('/EditorialPageId')]=TextStringObject(meta['id'])
        page[NameObject('/EditorialLayout')]=TextStringObject(meta['layout'])
        all_sources+=meta['source_ids'];all_features+=meta['feature_ids']
    writer.add_metadata({'/EditorialSourceIds':json.dumps(sorted(set(all_sources))),
                         '/EditorialFeatureIds':json.dumps(sorted(set(all_features))),
                         '/EditorialRenderer':'product-editorial-pdf/v1'})
    with staged.open('wb') as stream:writer.write(stream)
    final=PdfReader(str(staged));audit=[]
    if len(final.pages)!=len(renderer.pages):raise EditorialError('Final PDF page count mismatch')
    for number,page in enumerate(final.pages,1):
        extracted=page.extract_text() or ''
        for box in renderer.boxes:
            if box['page']==number and box.get('text') and flat(box['text']) not in flat(extracted):
                raise EditorialError(f'Page {number}: selectable text check failed for {box["kind"]}: {box["text"][:60]}')
        media=page.get('/Resources',{}).get('/XObject',{})
        jpegs=0
        for item in media.values():
            obj=item.get_object()
            if obj.get('/Subtype')=='/Image':
                filters=obj.get('/Filter',[]);filters=filters if isinstance(filters,list) else [filters]
                if '/DCTDecode' not in filters:raise EditorialError('An embedded raster is not JPEG compressed')
                jpegs+=1
        audit.append({'page':number,'selectable_text_characters':len(extracted),'jpeg_images':jpegs,'status':'pass'})
    return {'status':'pass','sha256':hashlib.sha256(staged.read_bytes()).hexdigest(),'pages':audit,
            'page_lineage_embedded':True,'all_text_selectable':True}


def main():
    parser=argparse.ArgumentParser(description=__doc__,formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--input',type=Path,required=True);parser.add_argument('--project-root',type=Path,required=True)
    parser.add_argument('--output',type=Path,required=True);parser.add_argument('--qa-dir',type=Path,required=True)
    parser.add_argument('--overwrite',action='store_true');args=parser.parse_args()
    renderer=None;temp=[]
    try:
        root=args.project_root.resolve(strict=True)
        if not root.is_dir():raise EditorialError('project-root must be a directory')
        source=args.input.resolve(strict=True);output=args.output.resolve();qa=args.qa_dir.resolve()
        if output==source:raise EditorialError('Output cannot replace the JSON input')
        if output.exists() and not args.overwrite:raise EditorialError('Output exists; use a new path or --overwrite')
        if output.suffix.lower()!='.pdf':raise EditorialError('Output extension must be .pdf')
        data=json.loads(source.read_text())
        if not isinstance(data,dict) or not isinstance(data.get('document'),dict) or not isinstance(data.get('theme'),dict):
            raise EditorialError('JSON requires document/theme objects and a pages list')
        renderer=Renderer(data,root,qa)
        output.parent.mkdir(parents=True,exist_ok=True)
        raw=output.parent/f'.{output.stem}.{uuid.uuid4().hex}.raw.pdf';staged=raw.with_suffix('.checked.pdf');temp=[raw,staged]
        renderer.render(raw);audit=finalize_pdf(raw,staged,renderer)
        os.replace(staged,output);audit['output']=str(output)
        renderer.qa_write('pass',audit=audit)
        print(json.dumps({'status':'pass','output':str(output),'qa_dir':str(qa),'pages':len(renderer.pages),'sha256':audit['sha256']}))
        return 0
    except Exception as e:
        if renderer:renderer.qa_write('fail',error=str(e))
        else:
            args.qa_dir.mkdir(parents=True,exist_ok=True)
            (args.qa_dir/'render-report.json').write_text(json.dumps({'status':'fail','error':str(e)},ensure_ascii=False,indent=2)+'\n')
        print(f'ERROR: {e}',file=sys.stderr);return 2
    finally:
        for file in temp:file.unlink(missing_ok=True)


if __name__=='__main__':raise SystemExit(main())
