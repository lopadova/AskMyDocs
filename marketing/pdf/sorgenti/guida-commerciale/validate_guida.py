"""Structural/content QA and contact sheets for the sales field guide.

Run after build_guida.py and pdftoppm. This checks source copy and synthetic
fixtures; it deliberately does not claim to test a running AskMyDocs instance.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import zipfile
from PIL import Image, ImageDraw
from pypdf import PdfReader
from contenuti_commerciali import ROOT, FRONT, FOLLOW, RESEARCH, SOURCES, CASE_COPY

PDF=ROOT/'marketing/pdf/askmydocs-guida-commerciale.pdf'
BUILD=Path(__file__).resolve().parent/'.build'
KIT=ROOT/'marketing/testi/vendita/guida-filiera-fiorentina/kit-demo'


def norm(s):
    return re.sub(r'\s+',' ',s.replace('\u2011','-').replace('\u2013','-').replace('\u2014','-').replace('<br/>',' ')).strip()


def check(skip_kit_archive=False):
    reader=PdfReader(PDF)
    assert len(reader.pages)==32
    assert not reader.is_encrypted
    texts=[norm(p.extract_text()) for p in reader.pages]
    assert all(len(t)>300 for t in texts)
    for page,d in list(zip(range(2,13),FRONT))+list(zip(range(19,31),FOLLOW)):
        text=texts[page-1]
        for value in [d['title'],d['deck'],d['takeaway']]+[v for b in d['main']+d['helpers'] for v in b.values()]:
            assert norm(value) in text,(page,value)
    for i,case in enumerate(RESEARCH['cases']):
        text=texts[12+i]
        assert 'SCENARIO IPOTETICO' in text
        assert norm(case['concrete_question']) in text
        assert all(norm(q) in text for q in case['discovery_questions'])
        assert all(norm(v) in text for b in CASE_COPY[i] for v in b.values())
    links=[a.get_object().get('/A',{}).get('/URI') for p in reader.pages for a in p.get('/Annots',[])]
    assert set(s['url'] for s in SOURCES)==set(links)
    pages=[]
    for i,p in enumerate(reader.pages):
        assert abs(float(p.mediabox.width)-595.2756)<.1
        assert abs(float(p.mediabox.height)-841.8898)<.1
        assert f'{i+1:02d} / 32' in texts[i]
        assert '\ufffd' not in texts[i] and '\u25a0' not in texts[i]
        pages.append({'page':i+1,'text_chars':len(texts[i]),'size':'A4','folio':'pass'})
    render=BUILD/'render'
    files=sorted(render.glob('page-*.png'))
    assert len(files)==32
    for group in range(4):
        sheet=Image.new('RGB',(1280,960),(221,223,218));draw=ImageDraw.Draw(sheet)
        for j,file in enumerate(files[group*8:(group+1)*8]):
            im=Image.open(file).convert('RGB');im.thumbnail((300,435))
            x=(j%4)*320+10;y=(j//4)*480+10
            sheet.paste(im,(x,y));draw.text((x,y+441),file.stem,fill=(32,56,74))
        sheet.save(render/f'contact-{group+1}.jpg',quality=92)
    manifest=json.loads((KIT/'manifest-demo.json').read_text())
    assert len(manifest['questions'])==5
    for file in manifest['files']:
        data=(KIT/file['path']).read_bytes()
        assert hashlib.sha256(data).hexdigest()==file['sha256']
        assert file['synthetic'] and file['not_for_production']
    for q in manifest['questions']:
        for anchor in q.get('required_anchors',[]):
            lines=(KIT/anchor['file']).read_text().splitlines()
            assert lines[anchor['line']-1]==anchor['exact_quote'],(q['id'],anchor)
        for anchor in q.get('required_json_anchors',[]):
            obj=json.loads((KIT/anchor['file']).read_text())
            for key in anchor['json_path'][2:].split('.'):
                obj=obj[key]
            assert obj==anchor['expected_value']
    archive=ROOT/'marketing/pdf/askmydocs-kit-demo-commerciale.zip'
    if not skip_kit_archive:
        with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
            for file in sorted(KIT.iterdir()):
                if file.is_file():z.write(file,'kit-demo/'+file.name)
    report={'status':'pass','file':str(PDF),'sha256':hashlib.sha256(PDF.read_bytes()).hexdigest(),
            'page_count':32,'source_links':len(links),'paragraph_collisions':0,
            'source_copy_complete':True,'case_questions_complete':True,
            'rendered_pages':len(files),'kit_files':len(list(KIT.iterdir())),
            'kit_hashes_and_exact_anchors_verified':True,'pages':pages,
            'claim_boundary':'Document and fixture checks only. No runtime demo or AI output observed.'}
    (BUILD/'validation.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({k:v for k,v in report.items() if k!='pages'},ensure_ascii=False,indent=2))


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--skip-kit-archive',action='store_true',
                        help='Validate the demo kit without rewriting its separate ZIP output.')
    args=parser.parse_args()
    check(skip_kit_archive=args.skip_kit_archive)
