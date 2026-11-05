"""Verify authored copy remains native text and notes survive PPTX export."""
import json, re, sys, zipfile, hashlib
from pathlib import Path
from xml.etree import ElementTree as ET

SOURCE=Path(__file__).resolve().parent
ROOT=SOURCE.parents[3]
PPTX=ROOT/'marketing/slide/presentazione.pptx'
DATA=json.loads((SOURCE/'contenuti-slide.json').read_text())
REVISION=sys.argv[1] if len(sys.argv)>1 else 'r3'
NS={'a':'http://schemas.openxmlformats.org/drawingml/2006/main','p':'http://schemas.openxmlformats.org/presentationml/2006/main'}
def normal(x):
    return re.sub(r'\s+',' ',str(x).replace('\u2011','-').replace('\u2013','-').replace('\u2014','-')).strip()
def strings(x):
    if isinstance(x,str):return [x]
    if isinstance(x,list):return sum((strings(v) for v in x),[])
    if isinstance(x,dict):return sum((strings(v) for v in x.values()),[])
    return []
rows=[]
with zipfile.ZipFile(PPTX) as z:
    slide_parts=sorted((n for n in z.namelist() if re.fullmatch(r'ppt/slides/slide\d+\.xml',n)),key=lambda x:int(re.search(r'(\d+)\.xml',x).group(1)))
    notes_parts=[n for n in z.namelist() if re.fullmatch(r'ppt/notesSlides/notesSlide\d+\.xml',n)]
    assert len(slide_parts)==20 and len(notes_parts)==20
    for i,name in enumerate(slide_parts):
        s=DATA['slides'][i];xml=ET.fromstring(z.read(name))
        text_shapes=[normal(' '.join(t.text or '' for t in sp.findall('.//a:t',NS))) for sp in xml.findall('.//p:sp',NS)]
        expected=[s['title']]+strings(s['visible'])
        missing=[v for v in expected if normal(v) not in text_shapes]
        note=ET.fromstring(z.read(f'ppt/notesSlides/notesSlide{i+1}.xml'))
        note_text=normal(' '.join(t.text or '' for t in note.findall('.//a:t',NS)))
        note_ok=normal(s['speaker_notes'].replace('\\n','\n')) in note_text
        assert not missing,(i+1,missing)
        assert note_ok,f'Notes incomplete on {i+1}'
        rows.append({'slide':i+1,'copy_fields_verified':len(expected),'native_text_shapes':len([s for s in text_shapes if s]),'picture_count':len(xml.findall('.//p:pic',NS)),'speaker_notes_complete':note_ok,'status':'pass'})
    external=[]
    for name in z.namelist():
        if name.endswith('.rels'):
            xml=ET.fromstring(z.read(name))
            external.extend({'part':name,'target':rel.attrib.get('Target')} for rel in xml if rel.attrib.get('TargetMode')=='External')
    assert not external,external
report={'status':'pass','slide_count':20,'notes_count':20,'all_primary_text_native_and_complete':True,'external_relationship_count':len(external),'picture_media_count':len([n for n in z.namelist() if n.startswith('ppt/media/') and not n.endswith('/')]),'sha256':hashlib.sha256(PPTX.read_bytes()).hexdigest(),'slides':rows}
(SOURCE/'.build'/f'content-audit-{REVISION}.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
print(f'Content audit: 20 slides, all main copy native and complete, 20 complete speaker notes, no external relationships.')
