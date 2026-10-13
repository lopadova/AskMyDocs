// Editable widescreen edition for Keynote. Run a copy from the private build
// directory with the bundled runtime node_modules available.
import fs from 'node:fs/promises';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import { FileBlob, Presentation, PresentationFile } from '@oai/artifact-tool';

const root = path.resolve(process.argv[2] ?? process.cwd());
const source = path.join(root, 'docs/presentations/nencini-sport');
const build = path.join(root, 'storage/app/presentation-build/nencini-sport-keynote');
const output = path.join(root, 'storage/app/presentations/nencini-sport-keynote');
const skill = '/Users/marco/.codex/plugins/cache/openai-primary-runtime/presentations/26.1011.11113/skills/presentations';
const python = '/Users/marco/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/bin/python3';
const content = JSON.parse(await fs.readFile(path.join(source, 'contenuti.json'), 'utf8'));
const { finalizePresentation } = await import(pathToFileURL(path.join(skill, 'container_tools/artifact_tool_utils.mjs')).href);
const edition = process.argv[3] ?? '';
const stem = `AskMyDocs-Nencini-Sport-Keynote${edition}`;
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
  s.speakerNotes.text=`${data.range}\n\n${data.speech}\n\nREGIA\n${data.action}${data.prompt?`\n\nPROMPT\n${data.prompt}\n\nSEGUITO\n${data.followup}`:''}\n\nFONTI\n${data.sources.join('\n')}\n\nIMMAGINI\nIllustrazioni concettuali generate con Imagegen. Non raffigurano strutture reali di Nencini Sport o Regolo.`;
  copy(s,String(i+1).padStart(2,'0'),1190,675,38,24,17,dark?colors.muted:colors.gray,false,'slide-number');
  return s;
}

{
  const s=await slide(0,true,'sport-conoscenza.png');
  copy(s,'AskMyDocs',64,115,660,130,96,'#FFFFFF',true,'heading');
  copy(s,'per Nencini Sport',66,273,590,76,47,colors.cyan,false,'client');
  copy(s,'La conoscenza del team,\na portata di domanda',68,433,535,123,35,'#FFFFFF',false,'intro');
  copy(s,'Demo Freshdesk',68,611,500,40,25,colors.muted,false,'demo');
}
{
  const s=await slide(1,false,'cambio-taglia.png');
  copy(s,'Il sapere\ndel servizio\nclienti',64,94,600,316,75,colors.black,true,'heading');
  copy(s,'Cambi taglia, resi, spedizioni.\nEsperienze già nei ticket Freshdesk.',66,478,570,127,29,colors.gray,false,'examples');
}
{
  const s=await slide(2,false,'conoscenza.png');
  copy(s,'Come funziona',64,74,1100,96,64,colors.black,true,'heading');
  const rows=[['01','Freshdesk','Ticket e articoli selezionati'],['02','Ricerca','Informazioni pertinenti alla domanda'],['03','Risposta AI','Riepilogo con riferimenti alle fonti'],['04','Operatore','Verifica la fonte e decide']];
  rows.forEach(([num,heading,body],i)=>{
    const y=233+i*98;
    copy(s,num,64,y,80,63,44,'#6B55C5',true,`number-${i}`);
    copy(s,heading,164,y+1,414,45,32,colors.black,true,`step-${i}`);
    copy(s,body,164,y+46,434,41,23,colors.gray,false,`description-${i}`);
  });
}
{
  const s=await slide(3,true);
  copy(s,'La demo: un cambio taglia',64,68,1152,88,49,'#FFFFFF',true,'heading');
  copy(s,'«Cerca casi risolti\ndi cambio taglia.»',64,230,1140,228,79,'#FFFFFF',true,'prompt');
  copy(s,'Passaggi seguiti, esito documentato e ticket di origine',64,514,1145,63,31,colors.cyan,false,'evidence');
  copy(s,'Poi: una bozza da verificare prima di rispondere al cliente',64,610,1150,52,27,colors.muted,false,'followup');
}
{
  const s=await slide(4,false);
  copy(s,'Esempi d’uso',64,70,1145,94,64,colors.black,true,'heading');
  [['Spedizioni','Reclami simili e soluzioni documentate',64,238],['Assistenza prodotto','Sintesi del caso e informazioni mancanti',667,238],['Nuovi colleghi','Casi reali per imparare dal team',64,458],['FAQ del team','Bozze da rivedere e approvare',667,458]].forEach(([heading,body,x,y],i)=>{
    copy(s,heading,x,y,550,66,43,colors.black,true,`use-${i}`);
    copy(s,body,x,y+96,538,92,28,colors.gray,false,`use-description-${i}`);
  });
}
{
  const s=await slide(5,true);
  copy(s,'Risposte verificabili',64,64,1152,80,49,'#FFFFFF',true,'heading');
  copy(s,'La risposta\nsi controlla\nnella fonte',64,209,720,303,76,'#FFFFFF',true,'source-claim');
  copy(s,'Ticket di origine\nEsito documentato\nProcedura attuale',831,250,405,237,32,colors.cyan,false,'checks');
  copy(s,'L’AI può sbagliare. L’operatore verifica prima di usare la risposta.',64,608,1150,55,27,colors.muted,false,'human-verification');
}
{
  const s=await slide(6,true,'ai-in-italia.png');
  copy(s,'Regolo.ai',64,72,600,110,80,'#FFFFFF',true,'heading');
  copy(s,'AI in Italia',66,222,535,98,64,colors.cyan,true,'residency');
  copy(s,'Il modello si sceglie',68,360,510,52,32,'#FFFFFF',true,'choice');
  copy(s,'Qwen 3.5 122B\nMistral Small 4 119B\nGPT OSS 120B',68,433,515,159,30,'#FFFFFF',false,'examples');
  copy(s,'Anche modelli compatibili su risorse dedicate',68,624,570,55,22,colors.muted,false,'custom');
}
{
  const s=await slide(7,false);
  copy(s,'Protezione dei dati',64,66,1145,92,64,colors.black,true,'heading');
  copy(s,'Regolo dichiara',64,234,550,67,40,'#6B55C5',true,'provider');
  copy(s,'AI elaborata in Italia\nPrompt e risposte senza conservazione\nContenuti esclusi dall’addestramento',64,335,550,215,29,colors.black,false,'provider-controls');
  copy(s,'Produzione AskMyDocs',667,234,552,67,40,colors.black,true,'application');
  copy(s,'KB sotto il controllo aziendale\nAccessi per ruolo e dati minimizzati\nConservazione e audit configurati',667,335,550,215,29,colors.black,false,'application-controls');
  copy(s,'Il perimetro completo comprende hosting, backup e servizi accessori.\nLa residenza Freshdesk richiede una conferma separata.',64,607,1136,65,23,colors.gray,false,'boundary');
}
{
  const s=await slide(8,true);
  copy(s,'AI Act',64,67,1145,96,72,'#FFFFFF',true,'heading');
  copy(s,'Demo',64,244,535,85,58,colors.violet,true,'demo');
  copy(s,'Freshdesk collegato\nVerifica del funzionamento\nRegole non tutte attivate',64,364,548,211,32,'#FFFFFF',false,'demo-state');
  copy(s,'Produzione',667,244,550,85,58,colors.cyan,true,'production');
  copy(s,'Regolo e moduli applicabili\nControlli sui dati e revisione umana\nGovernance, audit e verifiche',667,364,550,211,31,'#FFFFFF',false,'production-state');
  copy(s,'La conformità dipende dall’uso concreto, dai controlli e dalle procedure aziendali.',64,616,1145,55,25,colors.muted,false,'scope');
}
{
  const s=await slide(9,true,'sport-conoscenza.png');
  copy(s,'Il prossimo passo',64,82,650,86,49,'#FFFFFF',true,'heading');
  copy(s,'La conoscenza\ndel team,\nquando serve',64,224,670,298,67,colors.cyan,true,'closing');
  copy(s,'Un pilota con gli operatori.\nQualità delle risposte verificata.',68,578,590,87,29,'#FFFFFF',false,'next-step');
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
await fs.copyFile(path.join(root,'storage/app/presentations/nencini-sport/Testo-relatore-e-prompt.txt'),path.join(output,'Testo-relatore-e-prompt.txt'));
console.log(JSON.stringify({pptx:deckPath,pdf:path.join(output,`${stem}.pdf`),slides:10,durationSeconds:550}));
