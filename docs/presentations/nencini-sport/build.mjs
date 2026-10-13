// Run a copy of this file from a private build directory linked to the bundled
// @oai/artifact-tool node_modules. Generated outputs belong in storage/app.
import fs from "node:fs/promises";
import { execFileSync } from "node:child_process";
import path from "node:path";
import { pathToFileURL } from "node:url";
import { FileBlob, Presentation, PresentationFile } from "@oai/artifact-tool";

const workspaceDir = path.resolve(process.argv[2] ?? process.cwd());
const sourceDir = path.join(workspaceDir, "docs/presentations/nencini-sport");
const buildDir = path.join(workspaceDir, "storage/app/presentation-build/nencini-sport");
const outputDir = path.join(workspaceDir, "storage/app/presentations/nencini-sport");
const skillDir = process.env.NENCINI_PRESENTATIONS_SKILL_DIR ?? "/Users/marco/.codex/plugins/cache/openai-primary-runtime/presentations/26.1011.11113/skills/presentations";
const runtimePython = process.env.NENCINI_RUNTIME_PYTHON ?? "/Users/marco/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/bin/python3";
const content = JSON.parse(await fs.readFile(path.join(sourceDir, "contenuti.json"), "utf8"));
const { finalizePresentation } = await import(pathToFileURL(path.join(skillDir, "container_tools/artifact_tool_utils.mjs")).href);
const revision = process.argv[3] ?? "";
const finalStem = `AskMyDocs-Nencini-Sport${revision}`;
const finalPath = path.join(outputDir, `${finalStem}.pptx`);
const font = "Helvetica Neue";
const ink = "#101A2D";
const paper = "#F4F6F8";
const cyan = "#22D3EE";
const violet = "#A994FF";
const gray = "#526078";
const pale = "#CED9E9";
await fs.mkdir(path.join(buildDir, "preview"), { recursive: true });
await fs.mkdir(outputDir, { recursive: true });
const presentation = Presentation.create({ slideSize: { width: 1280, height: 720 } });

function text(slide, value, x, y, w, h, size = 28, color = ink, bold = false, name = "copy") {
  const shape = slide.shapes.add({
    geometry: "textbox", name,
    position: { left: x, top: y, width: w, height: h },
    fill: "none", line: { fill: "none", width: 0, style: "solid" },
  });
  shape.text = value;
  shape.text.style = {
    typeface: font, fontSize: size, color, bold,
    alignment: "left", verticalAlignment: "top", insets: 0,
    autoFit: "none", wrap: "square",
  };
  return shape;
}

async function picture(slide, filename, x = 0, y = 0, w = 1280, h = 720, fit = "cover", alt = "") {
  const bytes = await fs.readFile(path.join(outputDir, "immagini", filename));
  return slide.images.add({
    blob: bytes, contentType: "image/png", fit, alt,
    position: { left: x, top: y, width: w, height: h },
  });
}

function base(i, dark = false) {
  const slide = presentation.slides.add();
  slide.background.fill = dark ? ink : paper;
  const item = content.slides[i];
  slide.speakerNotes.text = `${item.range} (${item.durationSeconds} secondi)\n\n${item.speech}\n\nREGIA\n${item.action}${item.prompt ? `\n\nPROMPT\n${item.prompt}\n\nSEGUITO\n${item.followup}` : ""}\n\nFONTI\n${item.sources.join("\n")}\n\nLe immagini decorative sono illustrazioni concettuali generate con Imagegen, non fotografie di strutture reali.`;
  return slide;
}

function footer(slide, i, dark = false) {
  text(slide, "AskMyDocs / Nencini Sport", 64, 675, 550, 24, 17, dark ? pale : gray, false, "footer");
  text(slide, String(i + 1).padStart(2, "0"), 1170, 675, 45, 24, 17, dark ? pale : gray, false, "page");
}

function title(slide, value, dark = false, w = 1152) {
  text(slide, value, 64, 62, w, 114, 48, dark ? "#FFFFFF" : ink, true, "heading");
}

// 1. Minimal cover with a generated sports still life and editable title.
{
  const s = base(0, true);
  await picture(s, "sport-conoscenza.png", 0, 0, 1280, 720, "cover", "Illustrazione concettuale di una scarpa da running e una racchetta");
  text(s, "AskMyDocs", 64, 138, 570, 104, 86, "#FFFFFF", true, "heading");
  text(s, "per Nencini Sport", 64, 256, 580, 78, 48, cyan, false, "client");
  text(s, "La conoscenza del team,\na portata di domanda", 68, 389, 540, 115, 34, "#FFFFFF", false, "subtitle");
  text(s, "Demo con Freshdesk", 68, 578, 520, 44, 26, pale, false, "demo");
  footer(s, 0, true);
}

// 2. Flat editorial layout, one central customer-service question.
{
  const s = base(1);
  title(s, "Il sapere del servizio clienti");
  text(s, "«Abbiamo già risolto\nun caso simile?»", 64, 212, 940, 174, 62, ink, true, "central-question");
  text(s, "Cambi taglia e resi", 64, 467, 525, 50, 31, ink, true, "example-left");
  text(s, "Spedizioni e assistenza prodotto", 638, 467, 580, 70, 31, ink, true, "example-right");
  text(s, "Il sapere c’è. Il valore è ritrovarlo quando serve.", 64, 580, 1110, 58, 30, gray, false, "takeaway");
  footer(s, 1);
}

// 3. The actual flow is presented as editable numbered text, without mock UI.
{
  const s = base(2);
  title(s, "Come funziona");
  const rows = [
    ["01", "Freshdesk", "Ticket e articoli selezionati"],
    ["02", "Base di conoscenza", "Contenuti indicizzati\ne sincronizzati"],
    ["03", "Domanda e risposta AI", "Ricerca delle fonti e risposta\ncon riferimenti"],
    ["04", "Operatore", "Verifica la fonte e decide\ncome usare la risposta"],
  ];
  rows.forEach(([num, heading, body], i) => {
    const y = 192 + i * 105;
    text(s, num, 64, y, 88, 62, 45, "#6552BC", true, `step-${i + 1}`);
    text(s, heading, 180, y + 3, 450, 53, 33, ink, true, `step-title-${i + 1}`);
    text(s, body, 666, y + 6, 550, 76, 27, gray, false, `step-body-${i + 1}`);
  });
  footer(s, 2);
}

// 4. A real prompt to run, not an invented screenshot or customer result.
{
  const s = base(3, true);
  title(s, "La demo: un cambio taglia", true);
  text(s, "«Cerca casi risolti di cambio taglia.\nRiassumi i passaggi e l’esito.\nCita i ticket di origine.»", 64, 200, 1130, 235, 49, "#FFFFFF", true, "live-prompt");
  text(s, "Poi: una bozza di risposta al cliente", 64, 499, 1120, 65, 33, cyan, true, "followup");
  text(s, "L’operatore verifica. Un precedente resta un caso storico.", 64, 589, 1110, 48, 26, pale, false, "boundary");
  footer(s, 3, true);
}

// 5. Four examples in editorial columns, without UI cards.
{
  const s = base(4);
  title(s, "Altri usi, stessa conoscenza");
  const examples = [
    [64, 207, "Spedizioni", "Ricostruire reclami simili\ne le soluzioni documentate"],
    [654, 207, "Assistenza prodotto", "Riassumere il caso\ne le informazioni mancanti"],
    [64, 422, "Nuovi colleghi", "Studiare casi reali\ncon riferimenti allo storico"],
    [654, 422, "FAQ del team", "Preparare una bozza\nda rivedere e approvare"],
  ];
  examples.forEach(([x, y, heading, body], i) => {
    text(s, heading, x, y, 520, 52, 35, ink, true, `use-${i + 1}`);
    text(s, body, x, y + 72, 525, 111, 28, gray, false, `use-body-${i + 1}`);
  });
  footer(s, 4);
}

// 6. A single large statement with an explicit human verification boundary.
{
  const s = base(5, true);
  title(s, "Risposte con fonti verificabili", true);
  text(s, "La fonte rende\nla risposta controllabile", 64, 211, 1128, 182, 64, "#FFFFFF", true, "central-claim");
  text(s, "Ticket di origine", 64, 457, 430, 52, 32, cyan, true, "source");
  text(s, "Esito documentato", 644, 457, 550, 52, 32, violet, true, "outcome");
  text(s, "L’AI può sbagliare. L’operatore controlla prima di usare la risposta.", 64, 582, 1115, 65, 27, pale, false, "verification");
  footer(s, 5, true);
}

// 7. Model names come from the public Regolo /models response on 2026-10-13.
{
  const s = base(6, true);
  await picture(s, "ai-in-italia.png", 0, 0, 1280, 720, "cover", "Illustrazione concettuale di server, non una fotografia di data center Regolo");
  text(s, "Regolo.ai", 64, 78, 530, 86, 65, "#FFFFFF", true, "heading");
  text(s, "Scelta del modello.\nElaborazione in Italia.", 64, 207, 545, 123, 39, cyan, true, "residency");
  text(s, "Qwen 3.5 122B\nMistral Small 4 119B\nGPT OSS 120B", 64, 370, 540, 155, 32, "#FFFFFF", false, "model-examples");
  text(s, "Anche modelli compatibili\nsu infrastruttura dedicata", 64, 566, 510, 73, 26, pale, false, "custom-models");
  footer(s, 6, true);
}

// 8. Separate provider promises from the planned application configuration.
{
  const s = base(7);
  title(s, "Protezione dei dati");
  text(s, "Regolo dichiara", 64, 201, 520, 51, 35, "#6552BC", true, "provider");
  text(s, "Elaborazione AI in Italia\nNessuna conservazione di prompt e risposte\nNessun uso dei contenuti per addestramento", 64, 280, 535, 235, 28, ink, false, "provider-controls");
  text(s, "Produzione prevista", 655, 201, 550, 51, 35, ink, true, "production");
  text(s, "KB sotto il controllo aziendale\nAccessi per ruolo\nMinimizzazione dei dati personali\nRegole di conservazione e audit", 655, 280, 550, 235, 28, ink, false, "application-controls");
  text(s, "Il perimetro completo include hosting, backup e servizi accessori.\nLa residenza Freshdesk va confermata separatamente.", 64, 574, 1115, 78, 24, gray, false, "full-boundary");
  footer(s, 7);
}

// 9. Keep the distinction requested by Marco visible in the audience copy.
{
  const s = base(8, true);
  title(s, "AI Act: l’assetto di produzione", true);
  text(s, "Oggi, nel test", 64, 202, 475, 57, 35, violet, true, "test");
  text(s, "Freshdesk collegato\nVerifica del funzionamento\nRegole non tutte attivate", 64, 289, 475, 190, 30, "#FFFFFF", false, "test-status");
  text(s, "Uso in produzione", 650, 202, 560, 57, 35, cyan, true, "planned");
  text(s, "Regolo e controlli applicabili\nDati personali e autorizzazioni\nTrasparenza, audit e revisione umana\nGestione di rischi e incidenti", 650, 289, 555, 214, 28, "#FFFFFF", false, "planned-controls");
  text(s, "La conformità richiede controlli configurati,\nprocedure aziendali e verifica dell’uso concreto.", 64, 579, 1115, 76, 26, pale, false, "compliance-scope");
  footer(s, 8, true);
}

// 10. The cover artwork is deliberately reused only as a background.
{
  const s = base(9, true);
  await picture(s, "sport-conoscenza.png", 0, 0, 1280, 720, "cover", "Illustrazione concettuale sportiva");
  text(s, "Il prossimo passo", 64, 139, 590, 90, 59, "#FFFFFF", true, "heading");
  text(s, "Casi reali.\nQualità verificata.", 64, 277, 590, 163, 55, cyan, true, "closing");
  text(s, "Un pilota con gli operatori\ne i controlli di produzione", 64, 501, 550, 92, 31, "#FFFFFF", false, "next-step");
  footer(s, 9, true);
}

const candidatePath = path.join(buildDir, "candidate.pptx");
await (await PresentationFile.exportPptx(presentation)).save(candidatePath);
const validation = await finalizePresentation({
  workspaceDir, candidatePath, finalPath,
  pythonExecutable: runtimePython,
  integrityValidatorPath: path.join(skillDir, "container_tools/inspect_presentation_package_integrity.py"),
  layoutValidatorPath: path.join(skillDir, "container_tools/inspect_presentation_layout_geometry.py"),
  layoutArgs: ["--expected-slide-size-emu", "12192000,6858000", "--validate-bullet-geometry", "--validate-heading-fit"],
  fontPolicy: { basis: "design", families: [font] },
  requiredNativeTableOwnerSlides: [], requiredNativeChartOwnerSlides: [],
  verifyArtifactToolImport: true,
  receiptPath: path.join(buildDir, `validation${revision}.json`),
});

const validatedPresentation = await PresentationFile.importPptx(await FileBlob.load(finalPath));
for (const [i, slide] of validatedPresentation.slides.items.entries()) {
  const png = await validatedPresentation.export({ slide, format: "png", scale: 1.5 });
  await fs.writeFile(path.join(buildDir, "preview", `slide-${String(i + 1).padStart(2, "0")}.png`), new Uint8Array(await png.arrayBuffer()));
}
// Assemble the reviewed slide images into a projection PDF. The bundled native
// PDF exporter currently lacks its standard-font module. PPTX remains editable.
execFileSync(runtimePython, ["-c", `
import sys
from pathlib import Path
from reportlab.pdfgen import canvas
from pypdf import PdfReader
preview, destination = map(Path, sys.argv[1:])
images = sorted(preview.glob('slide-*.png'))
assert len(images) == 10
pdf = canvas.Canvas(str(destination), pagesize=(960, 540))
pdf.setTitle('AskMyDocs per Nencini Sport')
pdf.setAuthor('AskMyDocs')
for image in images:
    pdf.drawImage(str(image), 0, 0, width=960, height=540)
    pdf.showPage()
pdf.save()
assert len(PdfReader(destination).pages) == 10
`, path.join(buildDir, "preview"), path.join(outputDir, `${finalStem}.pdf`)]);
const script = [
  "ASKMYDOCS PER NENCINI SPORT", "Testo relatore e regia della demo", "Durata prevista: 9 minuti e 10 secondi, inclusa una demo live di 2 minuti.",
  "\nAPERTURA BREVE", content.slides[0].speech,
  "\nPRESENTAZIONE COMPLETA",
  ...content.slides.flatMap((s, i) => [
    `\n${String(i + 1).padStart(2, "0")}. ${s.title.toUpperCase()} (${s.range})`,
    s.speech, `\nREGIA: ${s.action}`,
    ...(s.prompt ? [`\nPROMPT DA INCOLLARE\n${s.prompt}`, `\nSEGUITO FACOLTATIVO\n${s.followup}`] : []),
  ]),
  "\nALTRI PROMPT PRONTI", ...content.extraPrompts.map(p => `\n${p.use}\n${p.prompt}`),
  "\nPRIMA DELLA DEMO", ...content.preparation.slice(0, 5).map((p, i) => `${i + 1}. ${p}`),
  "\nPRIMA DELLA PRODUZIONE", content.preparation[5],
  "\nFORMULA SUI DATI", "La produzione prevista userà Regolo per elaborare l’AI in Italia. La permanenza dell’intero flusso in Italia richiede la conferma di hosting, backup, servizi accessori e residenza Freshdesk. La demo attuale non rappresenta l’assetto finale di conformità.",
  "\nIMMAGINI", content.imageDisclosure,
  "\nFONTI ESTERNE (VERIFICATE IL 13 OTTOBRE 2026)",
  ...new Set(content.slides.flatMap(s => s.sources).filter(s => s.startsWith("https://"))),
].join("\n\n");
await fs.writeFile(path.join(outputDir, "Testo-relatore-e-prompt.txt"), script);
await fs.writeFile(path.join(outputDir, "immagini", "Prompt-immagini.txt"), `${content.imageDisclosure}\n\n${content.imagePrompts.map((p, i) => `${i + 1}. ${p}`).join("\n\n")}`);
await fs.writeFile(path.join(buildDir, "presentation.json"), JSON.stringify(presentation.toProto()));
console.log(JSON.stringify({ outputDir, slides: presentation.slides.items.length, durationSeconds: content.slides.reduce((a, s) => a + s.durationSeconds, 0), validation }, null, 2));
