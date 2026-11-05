"""Raster proof PDF and contact sheet from final imported PPTX renders."""
from pathlib import Path
import json, subprocess, shutil
from PIL import Image, ImageDraw, ImageFont, ImageChops, ImageStat
from reportlab.pdfgen import canvas
from pypdf import PdfReader

SOURCE_DIR=Path(__file__).resolve().parent
ROOT=SOURCE_DIR.parents[3]
RENDER=ROOT/'marketing/slide/render'
OUTPUT=ROOT/'marketing/slide/anteprima-presentazione.pdf'
images=[RENDER/f'slide-{i:02d}.png' for i in range(1,21)]
assert all(p.exists() for p in images)
c=canvas.Canvas(str(OUTPUT),pagesize=(960,540),pageCompression=1)
c.setTitle('AskMyDocs - Presentazione del prodotto')
c.setAuthor('AskMyDocs')
c.setSubject('Anteprima visiva del PowerPoint modificabile, 20 slide')
for p in images:
    c.drawImage(str(p),0,0,width=960,height=540)
    c.showPage()
c.save()
reader=PdfReader(str(OUTPUT))
assert len(reader.pages)==20
for page in reader.pages:
    assert float(page.mediabox.width)==960 and float(page.mediabox.height)==540

# Re-render the actual proof PDF; compare every page with its source render.
pdf_render=SOURCE_DIR/'.build/pdf-preview'
pdf_render.mkdir(parents=True,exist_ok=True)
poppler=shutil.which('pdftoppm')
assert poppler,'Poppler is required for proof validation'
subprocess.run([poppler,'-png','-scale-to-x','1600','-scale-to-y','900',str(OUTPUT),str(pdf_render/'slide')],check=True)
proof=[]
for i,original in enumerate(images,1):
    page_image=pdf_render/f'slide-{i:02d}.png'
    a=Image.open(original).convert('RGB');b=Image.open(page_image).convert('RGB')
    assert a.size==b.size==(1600,900)
    mean=sum(ImageStat.Stat(ImageChops.difference(a,b)).mean)/3
    # Poppler resamples embedded images at pixel boundaries; allow <2% MAE.
    assert mean<5,(i,mean)
    proof.append({'page':i,'mean_pixel_difference_0_255':mean,'status':'pass'})
(SOURCE_DIR/'.build/preview-pdf-validation.json').write_text(json.dumps({'status':'pass','page_count':20,'all_pages_rendered':True,'mean_pixel_difference_limit_0_255':5,'proof':proof},indent=2)+'\n')

thumb_w,thumb_h=480,270
gutter,label_h=24,30
sheet=Image.new('RGB',(4*thumb_w+5*gutter,5*(thumb_h+label_h)+6*gutter),'#e4e5de')
d=ImageDraw.Draw(sheet)
font=ImageFont.truetype('/System/Library/Fonts/Supplemental/Arial.ttf',18)
for i,p in enumerate(images):
    col,row=i%4,i//4
    x=gutter+col*(thumb_w+gutter);y=gutter+row*(thumb_h+label_h+gutter)
    im=Image.open(p).convert('RGB');im.thumbnail((thumb_w,thumb_h),Image.Resampling.LANCZOS)
    sheet.paste(im,(x,y));d.text((x,y+thumb_h+5),f'{i+1:02d}',fill='#23312d',font=font)
sheet.save(RENDER/'contact-sheet.jpg',quality=92)
print(f'Preview PDF verified: {len(reader.pages)} pages. Contact sheet: {RENDER / "contact-sheet.jpg"}')
