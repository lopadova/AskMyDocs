// Editable widescreen edition for Keynote. Run a copy from the private build
// directory with the bundled runtime node_modules available.
import fs from 'node:fs/promises';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import { FileBlob, Presentation, PresentationFile } from '@oai/artifact-tool';

const root = path.resolve(process.argv[2] ?? process.cwd());
const source = path.join(root, 'docs/presentations/nencini-sport');
const build = path.join(root, 'storage/app/presentation-build/nencini-sport-keynote-v2');
const output = path.join(root, 'storage/app/presentations/nencini-sport-keynote-v2');
const skill = '/Users/marco/.codex/plugins/cache/openai-primary-runtime/presentations/26.1011.11113/skills/presentations';
const python = '/Users/marco/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/bin/python3';
const content = JSON.parse(await fs.readFile(path.join(source, 'contenuti-keynote-v2.json'), 'utf8'));
const { finalizePresentation } = await import(pathToFileURL(path.join(skill, 'container_tools/artifact_tool_utils.mjs')).href);
const edition = process.argv[3] ?? '';
const stem = `AskMyDocs-Nencini-Sport-v2${edition}`;
const deckPath = path.join(output, `${stem}.pptx`);
await fs.mkdir(path.join(build, 'preview'), { recursive: true });
await fs.mkdir(output, { recursive: true });
const deck = Presentation.create({ slideSize: { width: 1280, height: 720 } });
const colors = { black: '#101A2D', white: '#F6F5F2', cyan: '#22D3EE', violet: '#A994FF', muted: '#CED9E9', gray: '#566075' };
const font = 'Helvetica Neue';

function copy(slide, value, x, y, w, h, size, color, bold = false, name = 'copy') {
  const t = slide.shapes.add({ geometry: 'textbox', name, position: { left:x, top:y, width:w, height:h }, fill:'none', line:{ style:'solid', fill:'none', width:0 } });
  t.text = value;
  t.text.style = { typeface:font, fontSize:size, color, bold, insets:0, wrap:'square', autoFit:'none', alignment:'left', verticalAlignment:'top' };
  return t;
}

async function image(slide, filename) {
  slide.images.add({ blob:await fs.readFile(path.join(output, 'immagini', filename)), contentType:'image/png', fit:'cover', position:{left:0,top:0,width:1280,height:720}, alt:'Illustrazione concettuale, non fotografia di sedi o infrastrutture reali' });
}

async function slide(i, dark, filename) {
  const s=deck.slides.add();
  s.background.fill=dark?colors.black:colors.white;
  if(filename) await image(s,filename);
  const data=content.slides[i];
  s.speakerNotes.text=`${data.range}\n\nPUNTI DA DIRE\n${data.cues.map(c=>`- ${c}`).join('\n')}\n\nTESTO SUGGERITO\n${data.speech}\n\nREGIA\n${data.action}${data.prompt?`\n\nPROMPT\n${data.prompt}\n\nSEGUITO\n${data.followup}`:''}\n\nFONTI\n${data.sources.join('\n')}\n\nIMMAGINI\nIllustrazioni concettuali generate con Imagegen. Non raffigurano strutture reali di Nencini Sport o Regolo.`;
  copy(s,String(i+1).padStart(2,'0'),1190,675,38,24,17,dark?colors.muted:colors.gray,false,'slide-number');
  return s;
}

{
  const s=await slide(0,true,'sport-conoscenza.png');
  copy(s,'AskMyDocs',64,115,660,130,96,'#FFFFFF',true,'heading');
  copy(s,'per Nencini Sport',66,273,590,76,47,colors.cyan,false,'client');
  copy(s,'La conoscenza del team,\na portata di domanda',68,433,535,123,35,'#FFFFFF',false,'intro');
  copy(s,'Demo Freshdesk. Poi documenti e Gescat.',68,611,555,65,25,colors.muted,false,'demo');
}
{
  const s=await slide(1,false);
  copy(s,'Dove trova le risposte',64,74,1152,96,61,colors.black,true,'heading');
  const columns=[
    [64,'Freshdesk','Nella demo','Ticket\nConversazioni\nCasi storici'],
    [465,'Documentazione\ninterna','Da collegare','Regole approvate\nProcedure\nDocumenti di gestione'],
    [877,'Gescat','Da collegare','Clienti e ordini\nSpedizioni\nDati dell’e-commerce'],
  ];
  columns.forEach(([x,heading,status,body],i)=>{
    copy(s,heading,x,229,345,125,41,colors.black,true,`source-${i}`);
    copy(s,status,x,387,345,49,26,i===0?'#18758A':'#6B55C5',true,`status-${i}`);
    copy(s,body,x,457,345,161,27,colors.gray,false,`details-${i}`);
  });
  copy(s,'Regole operative attive. Nuove fonti da collegare e mappare.',64,642,1130,45,23,colors.gray,false,'rules');
}
{
  const s=await slide(2,true);
  copy(s,'Freshdesk e Gescat insieme',64,70,1152,90,55,'#FFFFFF',true,'heading');
  copy(s,'«Il cliente segnala\nuna consegna mancante.\nChe cosa è successo\ne come interveniamo?»',64,237,742,259,44,'#FFFFFF',true,'question');
  [['Freshdesk','Segnalazione e storico'],['Gescat','Cliente, ordine e spedizione'],['Documenti interni','Procedura applicabile']].forEach(([heading,body],i)=>{
    const y=227+i*123;
    copy(s,heading,861,y,352,53,31,colors.cyan,true,`join-source-${i}`);
    copy(s,body,861,y+51,352,64,25,colors.muted,false,`join-body-${i}`);
  });
  copy(s,'Scenario previsto dopo il collegamento di Gescat e dei documenti',64,622,1148,52,25,colors.muted,false,'future-scope');
}
{
  const s=await slide(3,false,'cambio-taglia.png');
  copy(s,'Triage intelligente\ndelle email',64,67,710,174,54,colors.black,true,'heading');
  copy(s,'«Il corriere mi dice\nche non trova il pacco.\nAspetto da una settimana!»',64,280,650,181,36,colors.black,true,'illustrative-email');
  copy(s,'Esempio illustrativo',64,477,600,40,21,colors.gray,false,'example-label');
  copy(s,'Categorie configurabili:\nreclamo, spedizione segnalata come smarrita',64,539,675,98,27,'#6B55C5',true,'classification');
  copy(s,'Il significato del messaggio diventa ricercabile',64,648,1148,41,24,colors.gray,false,'meaning');
}
{
  const s=await slide(4,false);
  copy(s,'Le altre fonti aziendali',64,70,1145,94,61,colors.black,true,'heading');
  [['Drive e documenti','Regole, procedure e file aziendali',64,224],['Email','Triage e classificazione configurabili',667,224],['API e server MCP','Dati e servizi collegati',64,426],['Archivi FTP','Importazione tramite integrazione dedicata',667,426]].forEach(([heading,body,x,y],i)=>{
    copy(s,heading,x,y,550,68,43,colors.black,true,`source-type-${i}`);
    copy(s,body,x,y+90,538,94,27,colors.gray,false,`source-description-${i}`);
  });
  copy(s,'Contenuti, categorie, mapping e accessi secondo le vostre necessità',64,640,1145,45,24,colors.gray,false,'mapping');
}
{
  const s=await slide(5,false,'conoscenza.png');
  copy(s,'Il catalogo\ndei casi risolti',64,64,742,178,56,colors.black,true,'heading');
  copy(s,'Implementato, in test',64,264,680,52,31,'#6B55C5',true,'test-status');
  copy(s,'Problema e causa documentata\nIntervento eseguito\nEsito e ticket di origine',64,361,656,182,32,colors.black,false,'case-fields');
  copy(s,'Un esempio riuscito, riutilizzabile dal team',64,593,730,53,28,colors.gray,false,'reuse');
  copy(s,'Freshdesk: connettore creato per questa demo',64,654,1050,36,21,colors.gray,false,'new-connector');
}
{
  const s=await slide(6,true);
  copy(s,'La demo con Freshdesk',64,68,1152,88,56,'#FFFFFF',true,'heading');
  copy(s,'«Cerca casi risolti\ndi cambio taglia.»',64,229,1140,228,78,'#FFFFFF',true,'prompt');
  copy(s,'Problema, intervento, esito e fonti',64,513,1145,58,33,colors.cyan,false,'evidence');
  copy(s,'La scheda del caso è una funzione ancora in test',64,612,1150,55,27,colors.muted,false,'test-boundary');
}
{
  const s=await slide(7,true,'ai-in-italia.png');
  copy(s,'Regolo.ai',64,72,600,110,80,'#FFFFFF',true,'heading');
  copy(s,'AI in Italia',66,219,535,98,61,colors.cyan,true,'residency');
  copy(s,'Qwen, Mistral, GPT OSS\nModelli scelti secondo il caso d’uso',68,367,555,130,28,'#FFFFFF',false,'choice');
  copy(s,'Regolo dichiara:\ncontenuti senza conservazione\ne senza uso per addestramento',68,524,553,110,26,colors.muted,false,'privacy');
  copy(s,'Assetto di produzione previsto',68,650,570,33,21,colors.muted,false,'scope');
}
{
  const s=await slide(8,true);
  copy(s,'Protezione dei dati e AI Act',64,68,1145,96,55,'#FFFFFF',true,'heading');
  copy(s,'Demo',64,235,535,85,54,colors.violet,true,'demo');
  copy(s,'Freshdesk collegato\nRegole operative attive\nSchede dei casi in test',64,352,548,198,31,'#FFFFFF',false,'demo-state');
  copy(s,'Produzione',667,235,550,85,54,colors.cyan,true,'production');
  copy(s,'Regolo e moduli applicabili\nAccessi, dati personali e audit\nRevisione umana e procedure',667,352,550,198,30,'#FFFFFF',false,'production-state');
  copy(s,'Conformità da verificare sull’uso concreto e sull’intero percorso.\nIl perimetro include hosting, backup e residenza delle fonti.',64,602,1145,76,24,colors.muted,false,'scope');
}
{
  const s=await slide(9,true,'sport-conoscenza.png');
  copy(s,'Il percorso per\nNencini Sport',64,69,660,160,52,'#FFFFFF',true,'heading');
  copy(s,'Freshdesk e casi risolti\nDocumenti interni e Gescat\nEmail e categorie del team',64,296,655,207,36,colors.cyan,true,'roadmap');
  copy(s,'Un pilota con gli operatori,\nsu risposte e fonti verificabili',68,593,620,90,28,'#FFFFFF',false,'next-step');
}

const draft=path.join(build,`candidate${edition}.pptx`);
await (await PresentationFile.exportPptx(deck)).save(draft);
await finalizePresentation({workspaceDir:root,candidatePath:draft,finalPath:deckPath,pythonExecutable:python,integrityValidatorPath:path.join(skill,'container_tools/inspect_presentation_package_integrity.py'),layoutValidatorPath:path.join(skill,'container_tools/inspect_presentation_layout_geometry.py'),layoutArgs:['--expected-slide-size-emu','12192000,6858000','--validate-heading-fit'],fontPolicy:{basis:'design',families:[font]},requiredNativeTableOwnerSlides:[],requiredNativeChartOwnerSlides:[],verifyArtifactToolImport:true,receiptPath:path.join(build,`validation${edition}.json`)});
const final=await PresentationFile.importPptx(await FileBlob.load(deckPath));
for(const [i,s] of final.slides.items.entries()){
  const png=await final.export({slide:s,format:'png',scale:1.5});
  await fs.writeFile(path.join(build,'preview',`slide-${String(i+1).padStart(2,'0')}.png`),new Uint8Array(await png.arrayBuffer()));
}
execFileSync(python,['-c',`
import sys
from pathlib import Path
from reportlab.pdfgen import canvas
from pypdf import PdfReader
preview,destination=map(Path,sys.argv[1:])
images=sorted(preview.glob('slide-*.png'))
assert len(images)==10
c=canvas.Canvas(str(destination),pagesize=(960,540))
c.setTitle('AskMyDocs per Nencini Sport - Keynote edition')
for image in images:
    c.drawImage(str(image),0,0,width=960,height=540)
    c.showPage()
c.save()
assert len(PdfReader(destination).pages)==10
`,path.join(build,'preview'),path.join(output,`${stem}.pdf`)]);
const script=[content.title.toUpperCase(),'Durata prevista: 8 minuti e 30 secondi, inclusa la demo. Margine fino a dieci minuti.',...content.slides.flatMap((s,i)=>[`\n${i+1}. ${s.title.toUpperCase()} (${s.range})`,`PUNTI DA DIRE\n${s.cues.map(c=>`- ${c}`).join('\n')}`,s.speech,`REGIA\n${s.action}`,...(s.prompt?[`PROMPT\n${s.prompt}`,`SEGUITO\n${s.followup}`]:[])]),'\nALTRI ESEMPI DI DOMANDE',...content.extraPrompts.map(p=>`${p.use}\n${p.prompt}`),'\nPREPARAZIONE',...content.preparation].join('\n\n');
await fs.writeFile(path.join(output,'Testo-relatore-e-prompt.txt'),script);
await fs.writeFile(path.join(output,'Guida-iPhone-Keynote-Remote.txt'),['KEYNOTE CON IPHONE COME TELECOMANDO','Note e transizioni al clic',...content.remoteGuide.map((s,i)=>`${i+1}. ${s}`),'FONTI APPLE',...content.remoteSources].join('\n\n'));
console.log(JSON.stringify({pptx:deckPath,pdf:path.join(output,`${stem}.pdf`),slides:10,durationSeconds:content.slides.reduce((n,s)=>n+s.durationSeconds,0)}));
