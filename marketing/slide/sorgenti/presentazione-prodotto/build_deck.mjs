import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import {execFileSync} from 'node:child_process';
import {fileURLToPath,pathToFileURL} from 'node:url';

const SOURCE_DIR=path.dirname(fileURLToPath(import.meta.url));
const ROOT=path.resolve(SOURCE_DIR,'../../../..');
const NODE_MODULES='/Users/marco/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules';
process.env.RUNTIME_NODE_MODULES=NODE_MODULES;
process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES=NODE_MODULES;
const SKILL='/Users/marco/.codex/plugins/cache/openai-primary-runtime/presentations/26.1026.10807/skills/presentations';
const PYTHON='/Users/marco/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/bin/python3';
const {Presentation,PresentationFile,FileBlob}=await import(pathToFileURL(path.join(NODE_MODULES,'@oai/artifact-tool/dist/artifact_tool.mjs')).href);
const {Canvas}=await import(pathToFileURL(path.join(NODE_MODULES,'@oai/artifact-tool/node_modules/skia-canvas/lib/index.js')).href);
const {finalizePresentation}=await import(pathToFileURL(path.join(SKILL,'container_tools/artifact_tool_utils.mjs')).href);
const INPUT=JSON.parse(await fs.readFile(path.join(SOURCE_DIR,'contenuti-slide.json'),'utf8'));
const BRIEF=JSON.parse(await fs.readFile(path.join(SOURCE_DIR,'brief.json'),'utf8'));
const REVISION=process.argv.find(a=>a.startsWith('--revision='))?.split('=')[1]??'r1';
const STAGE=process.argv.find(a=>a.startsWith('--stage='))?.split('=')[1]??'full';
const BUILD=path.join(SOURCE_DIR,'.build');
const VALIDATED=path.join(SOURCE_DIR,'.validated');
const RENDER=path.join(ROOT,'marketing/slide/render');
const OUTPUT=path.join(ROOT,'marketing/slide/presentazione.pptx');
const PREVIEW=path.join(ROOT,'marketing/slide/anteprima-presentazione.pdf');
const C={paper:'#fbf8f1',cream:'#f4f0e7',green:'#224c40',rust:'#a35539',ink:'#23312d',muted:'#647168',pale:'#e5ebdf',line:'#d4d8ca'};
const W=1280,H=720,M=60;
const measured=[];
const measureCanvas=new Canvas(16,16),ctx=measureCanvas.getContext('2d');

function normalize(text){return String(text??'').replace(/\u2011|\u2013|\u2014/g,'-');}
function wrap(text,width,size,family='Arial',bold=false){
  ctx.font=`${bold?'bold ':''}${size}px ${family}`;
  return normalize(text).split('\n').map(paragraph=>{
    let lines=[],line='';
    for(const word of paragraph.split(/\s+/).filter(Boolean)){
      const candidate=line?line+' '+word:word;
      if(line&&ctx.measureText(candidate).width>width){lines.push(line);line=word;}else line=candidate;
    }
    lines.push(line);return lines.join('\n');
  }).join('\n');
}
function shape(slide,name,x,y,w,h,fill='none',stroke='none',lineWidth=0){
  return slide.shapes.add({name,geometry:'rect',position:{left:x,top:y,width:w,height:h},fill,
    line:{fill:stroke,style:'solid',width:lineWidth}});
}
function rule(slide,x,y,w,color=C.line,width=1){
  return slide.shapes.add({name:'rule',geometry:'line',position:{left:x,top:y,width:w,height:0},fill:'none',line:{fill:color,style:'solid',width}});
}
function text(slide,name,value,x,y,w,h,{size=28,family='Arial',color=C.ink,bold=false,italic=false,align='left',lineSpacing=1.12,doWrap=true}={}){
  const finalText=doWrap?wrap(value,w,size,family,bold):normalize(value);
  const count=finalText.split('\n').length;
  const required=count*size*lineSpacing;
  if(required>h+4)throw new Error(`Text box too short slide ${slide.number??'?'} ${name}: ${required.toFixed(1)} > ${h}`);
  if(x<-.1||y<-.1||x+w>W+.1||y+h>H+.1)throw new Error(`Text out of bounds: ${name}`);
  const box=slide.shapes.add({name,geometry:'textbox',position:{left:x,top:y,width:w,height:h},fill:'none',line:{fill:'none',width:0}});
  box.text=finalText;
  box.text.style={typeface:family,fontSize:size,color,bold,italic,alignment:align,verticalAlignment:'top',wrap:'none',autoFit:'none',insets:0,lineSpacing};
  measured.push({slide:slide.number,name,text:finalText,left:x,top:y,width:w,height:h,estimatedHeight:required,font:family,size});
  return box;
}
async function bytes(file){const b=await fs.readFile(file);return b.buffer.slice(b.byteOffset,b.byteOffset+b.byteLength);}
async function image(slide,file,x,y,w,h,{contain=false,name='illustration',crop}={}){
  // Concrete image bytes are embedded, with no links to local files in the PPTX.
  const ext=path.extname(file).toLowerCase();
  const types={'.webp':'image/webp','.png':'image/png','.jpg':'image/jpeg','.jpeg':'image/jpeg'};
  return slide.images.add({blob:await bytes(file),contentType:types[ext],alt:name,fit:contain?'contain':'cover',position:{left:x,top:y,width:w,height:h},...(crop?{crop}:{})});
}
function frame(slide,data,{dark=false}={}){
  slide.number=data.number;slide.background.fill=dark?C.green:C.paper;
  const color=dark?C.cream:C.ink;
  text(slide,'brand-footer','ASKMYDOCS / IL SAPERE CHE ENTRA NEL LAVORO',M,682,980,20,{size:13,color,bold:true,doWrap:false});
  text(slide,'folio',String(data.number).padStart(2,'0'),1185,680,35,24,{size:16,family:'Georgia',color,align:'right',doWrap:false});
  rule(slide,M,668,W-2*M,dark?'#708e7b':C.line,.9);
  slide.speakerNotes.text=normalize(data.speaker_notes).replaceAll('\\n','\n')+
    `\n\nFonte principale: ${INPUT.artifact.source_pdf}\nSHA-256 PDF: ${INPUT.artifact.source_pdf_sha256}\nRevisione prodotto: ${INPUT.artifact.product_revision}\nIllustrazioni narrative; non schermate o testimonianze.`;
}
function heading(slide,data,{width=W-2*M,size=52,top=109,maxLines=2}={}){
  text(slide,'kicker',data.kicker,M,63,width,28,{size:15.5,color:C.rust,bold:true,doWrap:false});
  const lines=wrap(data.title,width,size,'Georgia').split('\n').length;
  if(lines>maxLines)throw new Error(`Too many title lines slide ${data.number}`);
  text(slide,'primary-heading',data.title,M,top,width,lines*size*1.06+6,{family:'Georgia',size,lineSpacing:1.06});
  return top+lines*size*1.06+44;
}
function callout(slide,value,{x=M,y=568,w=W-2*M,h=62,size=24,dark=false}={}){
  shape(slide,'callout-fill',x,y,w,h,dark?'#315d4d':C.pale);
  shape(slide,'callout-rule',x,y,4,h,dark?C.cream:C.green);
  text(slide,'callout',value,x+19,y+14,w-38,h-20,{family:'Georgia',italic:true,size,color:dark?C.cream:C.green,lineSpacing:1.1});
}
function columns(slide,cards,{x=M,y=300,w=W-2*M,gap=44,headingSize=34,bodySize=29,bodyHeight=160}={}){
  const width=(w-gap*(cards.length-1))/cards.length;
  cards.forEach((card,i)=>{
    const xx=x+i*(width+gap);
    rule(slide,xx,y-25,width,C.line,1.3);
    text(slide,`card-heading-${i}`,card.heading,xx,y,width,90,{family:'Georgia',size:headingSize,color:C.green,lineSpacing:1.05});
    const headLines=wrap(card.heading,width,headingSize,'Georgia').split('\n').length;
    text(slide,`card-body-${i}`,card.text,xx,y+headLines*headingSize*1.05+20,width,bodyHeight,{size:bodySize,color:C.ink,lineSpacing:1.13});
  });
}
async function makeSlide(presentation,data){
  const s=presentation.slides.add();frame(s,data);
  const v=data.visible,asset=data.visual.asset_path,layout=data.visual.layout;
  if(layout==='cover_split'){
    await image(s,asset,550,0,730,665,{name:'Biblioteca umanistica, illustrazione di copertina'});
    await image(s,path.join(ROOT,'public/brand/askmydocs-wordmark-light.png'),55,38,270,80,{contain:true,name:'Marchio AskMyDocs'});
    text(s,'kicker','PRESENTAZIONE DEL PRODOTTO',60,153,430,27,{size:15.5,color:C.rust,bold:true,doWrap:false});
    text(s,'primary-heading','Il sapere che\nentra nel\nlavoro.',60,216,440,220,{family:'Georgia',size:60,lineSpacing:1.1,doWrap:false,color:C.green});
    text(s,'subtitle',v.subtitle,60,477,425,150,{size:25.5,lineSpacing:1.14,color:C.muted});
  }else if(layout==='five_step_journey'){
    heading(s,data,{size:51});
    const y=330,gap=24,width=(1160-4*gap)/5,boxes=[];
    v.steps.forEach((step,i)=>{
      const x=M+i*(width+gap);
      boxes.push(shape(s,`step-anchor-${i}`,x,y,width,4,C.green));
      text(s,`step-number-${i}`,String(i+1).padStart(2,'0'),x,y-49,width,36,{family:'Georgia',size:26,color:C.rust});
      text(s,`step-heading-${i}`,step.heading,x,y+24,width,50,{family:'Georgia',size:31,color:C.green});
      text(s,`step-body-${i}`,step.text,x,y+91,width,128,{size:27,lineSpacing:1.13});
    });
    for(let i=0;i<boxes.length-1;i++)s.shapes.connect(boxes[i],boxes[i+1],{kind:'straight',fromSide:'right',toSide:'left',line:{fill:C.green,width:1.8},tail:{type:'arrow',width:'med',length:'med'}});
  }else if(layout==='people_roles'){
    heading(s,data,{size:52});
    await image(s,asset,M,270,390,360,{name:'Persone e conoscenza condivisa'});
    v.cards.forEach((card,i)=>{
      const y=275+i*110;
      text(s,`role-${i}`,card.heading,505,y,690,42,{family:'Georgia',size:32,color:C.green});
      text(s,`role-text-${i}`,card.text,505,y+45,690,62,{size:28});
    });
    text(s,'short-callout',v.callout,505,618,690,34,{family:'Georgia',italic:true,size:22,color:C.muted});
  }else if(layout==='case_image_left'){
    await image(s,asset,0,0,675,665,{name:'Caso dimostrativo: assistenza e memoria'});
    const x=721,w=499;
    text(s,'kicker',data.kicker,x,58,w,27,{size:15.5,color:C.rust,bold:true,doWrap:false});
    text(s,'primary-heading',data.title,x,111,w,165,{family:'Georgia',size:44,lineSpacing:1.06});
    text(s,'question',v.quote,x,292,w,96,{family:'Georgia',italic:true,size:30,color:C.green,lineSpacing:1.1});
    text(s,'case-body',v.body[0],x,410,w,124,{size:27,lineSpacing:1.12});
    callout(s,v.body[1],{x,y:551,w,h:87,size:23});
  }else if(layout==='case_rule_and_live'){
    heading(s,data,{size:52});
    columns(s,v.cards,{y:257,headingSize:32,bodySize:28,bodyHeight:92});
    await image(s,asset,M,397,760,240,{name:'Caso dimostrativo: procedure e operations',crop:{left:0,top:.16,right:0,bottom:.3663157894736842}});
    text(s,'case-link',v.callout,864,436,356,190,{family:'Georgia',italic:true,size:26,color:C.green,lineSpacing:1.13});
  }else if(layout==='case_archive_steps'){
    heading(s,data,{size:52});
    await image(s,asset,M,246,475,384,{name:'Caso dimostrativo: archivio di corrispondenza'});
    v.body.forEach((paragraph,i)=>{
      shape(s,`bookmark-${i}`,587,274+i*167,5,99,i?C.green:C.rust);
      text(s,`case-body-${i}`,paragraph,615,268+i*167,575,147,{size:30,lineSpacing:1.14});
    });
  }else if(layout==='case_memory_right'){
    await image(s,asset,710,0,570,665,{name:'Caso dimostrativo: trasmettere conoscenza al team'});
    text(s,'kicker',data.kicker,M,59,590,27,{size:15.5,color:C.rust,bold:true,doWrap:false});
    text(s,'primary-heading',data.title,M,116,590,127,{family:'Georgia',size:49,lineSpacing:1.05});
    text(s,'question',v.quote,M,281,590,100,{family:'Georgia',italic:true,size:30,lineSpacing:1.13,color:C.green});
    text(s,'case-body-0',v.body[0],M,408,590,98,{size:28,lineSpacing:1.12});
    text(s,'case-body-1',v.body[1],M,535,590,110,{size:28,lineSpacing:1.12});
  }else if(layout==='case_bridge_panorama'){
    heading(s,data,{size:52});
    await image(s,asset,570,268,650,364,{name:'Caso dimostrativo: fonti e client AI collegati'});
    v.body.forEach((paragraph,i)=>text(s,`case-body-${i}`,paragraph,M,279+i*150,455,i?175:150,{size:28,lineSpacing:1.15}));
  }else if(layout==='document_pipeline'){
    heading(s,data,{size:52});
    columns(s,v.steps,{y:279,headingSize:32,bodySize:27,bodyHeight:80});
    await image(s,asset,M,449,1160,98,{name:'Flusso di documenti, illustrazione narrativa'});
    callout(s,v.callout,{y:568,h:64,size:23});
  }else if(layout==='three_knowledge_layers'){
    heading(s,data,{size:52});
    columns(s,v.cards,{y:300,headingSize:32,bodySize:29,bodyHeight:145});
    shape(s,'approved-underline',868,468,352,3,C.green);
    callout(s,v.callout,{y:567,h:64,size:25});
  }else if(layout==='research_to_matrix'){
    heading(s,data,{size:52});
    columns(s,v.cards,{y:310,headingSize:33,bodySize:29,bodyHeight:151});
    // Abstract source/criterion/evidence relationships, not simulated report results.
    const y=562,labels=['Documento','Aspetto','Fonte'];
    labels.forEach((label,i)=>{
      shape(s,`matrix-cell-${i}`,M+i*387,y,370,64,i===1?C.pale:C.cream);
      text(s,`matrix-label-${i}`,label,M+18+i*387,y+19,330,34,{size:23,color:C.muted});
    });
  }else if(layout==='four_channels'){
    heading(s,data,{size:52,width:830});
    await image(s,asset,720,282,500,334,{name:'Dialogo, illustrazione narrativa'});
    v.cards.forEach((card,i)=>{
      const col=i%2,row=Math.floor(i/2),x=M+col*320,y=287+row*174;
      rule(s,x,y-20,282,C.line,1.3);
      text(s,`channel-${i}`,card.heading,x,y,282,44,{family:'Georgia',size:32,color:C.green});
      text(s,`channel-text-${i}`,card.text,x,y+57,282,113,{size:27});
    });
  }else if(layout==='connection_roles'){
    heading(s,data,{size:52});
    v.cards.forEach((card,i)=>{
      const y=255+i*91;
      text(s,`connection-heading-${i}`,card.heading,M,y,280,44,{family:'Georgia',size:31,color:C.green});
      text(s,`connection-body-${i}`,card.text,375,y,845,73,{size:28,lineSpacing:1.12});
      if(i<2)rule(s,M,y+69,1160,C.line,.8);
    });
    await image(s,asset,M,544,1160,88,{name:'Ponti fra gli archivi, panorama notturno'});
    // Full condition is retained in the speaker notes; visible line remains editable.
    text(s,'condition',v.callout,M,642,1160,22,{size:17,color:C.muted,lineSpacing:1.05});
  }else if(layout==='governance_boundaries'){
    heading(s,data,{size:52,width:740});
    await image(s,asset,836,251,384,381,{name:'Custodire il patrimonio, illustrazione narrativa'});
    text(s,'governance-body-0',v.body[0],M,277,709,140,{size:30,lineSpacing:1.13});
    text(s,'governance-body-1',v.body[1],M,448,709,112,{size:30,lineSpacing:1.13});
    callout(s,v.callout,{x:M,y:585,w:709,h:61,size:21});
  }else if(layout==='operations_triptych'){
    heading(s,data,{size:52});
    columns(s,v.cards,{y:282,headingSize:32,bodySize:28,bodyHeight:100});
    await image(s,asset,M,481,1160,95,{name:'Osservatorio della conoscenza, illustrazione narrativa'});
    text(s,'operations-callout',v.callout,M,603,1160,40,{family:'Georgia',italic:true,size:24,color:C.green});
  }else if(layout==='closing_pilot'){
    heading(s,data,{size:52});
    columns(s,v.steps,{y:264,headingSize:28,bodySize:26,bodyHeight:83});
    await image(s,asset,M,463,1160,136,{name:'Collegare la conoscenza, panorama conclusivo'});
    text(s,'closing-callout',v.callout,M,623,1160,36,{family:'Georgia',italic:true,size:24,color:C.green,lineSpacing:1.1});
  }else{
    heading(s,data,{size:layout==='models_and_costs'?49:52});
    columns(s,v.cards,{y:313,headingSize:33,bodySize:29,bodyHeight:158});
    if(v.callout)callout(s,v.callout,{y:566,h:64,size:layout==='models_and_costs'?24:25});
  }
  return s;
}

await fs.mkdir(BUILD,{recursive:true});await fs.mkdir(VALIDATED,{recursive:true});await fs.mkdir(RENDER,{recursive:true});
const presentation=Presentation.create({slideSize:{width:W,height:H}});
presentation.theme.defaultFont='Arial';
presentation.theme.colorScheme={name:'AskMyDocs Editoriale',themeColors:{accent1:C.green,accent2:C.rust,accent3:C.muted,accent4:C.pale,accent5:C.ink,accent6:C.line,bg1:C.paper,bg2:C.cream,tx1:C.ink,tx2:C.muted,dk1:C.ink,dk2:C.green,lt1:C.paper,lt2:C.cream,hlink:C.green,folHlink:C.rust}};
const slides=STAGE==='cover'?INPUT.slides.slice(0,1):INPUT.slides;
for(const data of slides)await makeSlide(presentation,data);
if(STAGE==='cover'){
  await fs.writeFile(path.join(RENDER,'cover-draft.png'),new Uint8Array(await (await presentation.export({slide:presentation.slides.getItem(0),format:'png',scale:1})).arrayBuffer()));
  await (await PresentationFile.exportPptx(presentation)).save(path.join(BUILD,'cover-draft.pptx'));
  console.log(JSON.stringify({stage:'cover',slideCount:1,preview:path.join(RENDER,'cover-draft.png')}));
}else{
  const candidate=path.join(BUILD,`candidate-${REVISION}.pptx`),finalPath=path.join(VALIDATED,`presentazione-${REVISION}.pptx`);
  await (await PresentationFile.exportPptx(presentation)).save(candidate);
  const result=await finalizePresentation({workspaceDir:SOURCE_DIR,candidatePath:candidate,finalPath,
    explicitTotalSlideCount:20,requiredNativeTableOwnerSlides:[],requiredNativeChartOwnerSlides:[],
    pythonExecutable:PYTHON,integrityValidatorPath:path.join(SKILL,'container_tools/inspect_presentation_package_integrity.py'),
    layoutValidatorPath:path.join(SKILL,'container_tools/inspect_presentation_layout_geometry.py'),
    layoutArgs:['--expected-slide-size-emu','12192000,6858000','--validate-bullet-geometry','--validate-heading-fit'],
    fontPolicy:{basis:'reference',families:['Georgia','Arial'],referencePath:INPUT.artifact.source_pdf,referenceSha256:INPUT.artifact.source_pdf_sha256},
    verifyArtifactToolImport:true,receiptPath:path.join(BUILD,`validation-${REVISION}.json`)});
  await fs.copyFile(finalPath,OUTPUT);
  const finalPresentation=await PresentationFile.importPptx(await FileBlob.load(OUTPUT));
  for(const [index,slide] of finalPresentation.slides.items.entries()){
    const stem=`slide-${String(index+1).padStart(2,'0')}`;
    await fs.writeFile(path.join(RENDER,stem+'.png'),new Uint8Array(await (await finalPresentation.export({slide,format:'png',scale:1.25})).arrayBuffer()));
    await fs.writeFile(path.join(RENDER,stem+'.layout.json'),await (await slide.export({format:'layout'})).text());
  }
  execFileSync(PYTHON,[path.join(SOURCE_DIR,'build_preview.py')],{stdio:'inherit'});
  execFileSync(PYTHON,[path.join(SOURCE_DIR,'build_audit.py'),REVISION],{stdio:'inherit'});
  await fs.writeFile(path.join(BUILD,`text-geometry-${REVISION}.json`),JSON.stringify(measured,null,2));
  const sha=crypto.createHash('sha256').update(await fs.readFile(OUTPUT)).digest('hex');
  const delivery={id:'askmydocs-powerpoint-product-presentation',status:'produced-awaiting-visual-review',file:OUTPUT,preview:PREVIEW,slide_count:20,product_revision:INPUT.artifact.product_revision,source_pdf:INPUT.artifact.source_pdf,source_pdf_sha256:INPUT.artifact.source_pdf_sha256,sha256:sha,fonts:['Georgia','Arial'],editable_content:true,speaker_notes_on_every_slide:true,validation_report:path.join(BUILD,`validation-${REVISION}.json`),content_audit:path.join(BUILD,`content-audit-${REVISION}.json`),renders:RENDER,contact_sheet:path.join(RENDER,'contact-sheet.jpg'),visual_review:'pending',limitations:['L’anteprima PDF è composta dai rendering delle slide. Il PowerPoint contiene testi nativi modificabili.','Integrità, geometria e import verificati con Artifact Tool. Nessuna apertura in Microsoft PowerPoint è stata effettuata.','Georgia e Arial devono essere disponibili sulla macchina che presenta; i font non sono incorporati.'],finalizer:result};
  await fs.writeFile(path.join(ROOT,'marketing/slide/consegna-pptx.json'),JSON.stringify(delivery,null,2));
  console.log(JSON.stringify({output:OUTPUT,preview:PREVIEW,slideCount:finalPresentation.slides.items.length,sha256:sha,validation:delivery.validation_report}));
}
