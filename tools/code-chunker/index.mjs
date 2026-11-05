#!/usr/bin/env node
/**
 * AskMyDocs code-chunker helper (ADR 0035 D7).
 *
 * Contract (the PHP side — TreeSitterBreakPointDriver — depends on exactly this):
 *
 *   node index.mjs --probe
 *       -> stdout: {"ok":true,"helper":1,"grammars":["php","typescript",...]}
 *
 *   node index.mjs --language <token>      (source on stdin, UTF-8)
 *       -> stdout: {"ok":true,"language":"php","breakpoints":[
 *            {"line":12,"end_line":40,"score":100,"type":"class","symbol":"Invoice","depth":0}, ...]}
 *
 * Break points are 1-based LINE numbers: a chunk may START at that line. Byte
 * offsets are deliberately not part of the contract (UTF-16 offsets in JS and
 * byte offsets in PHP disagree on every multi-byte character). The helper
 * never prints source text.
 *
 * Exit codes:  0 ok · 2 usage · 3 grammar unavailable · 4 parse failure ·
 *              5 input too large.
 * Sandbox: no network, no filesystem writes; it only READS its own grammars.
 */
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { existsSync } from 'node:fs';

const require = createRequire(import.meta.url);
const MAX_INPUT_BYTES = 2 * 1024 * 1024;
const MAX_BREAKPOINTS = 20000;

// AskMyDocs language token -> grammar file stem in tree-sitter-wasms/out.
const GRAMMARS = {
  php: 'php',
  typescript: 'typescript',
  tsx: 'tsx',
  javascript: 'javascript',
  python: 'python',
  go: 'go',
  rust: 'rust',
  java: 'java',
  csharp: 'c_sharp',
  ruby: 'ruby',
  bash: 'bash',
};

// Per-language node classification. `containers` push the symbol stack,
// `functions` are leaves, `imports` form header runs.
const COMMON_WRAPPERS = new Set([
  'program', 'module', 'source_file', 'translation_unit', 'compilation_unit', 'export_statement',
  'decorated_definition', 'declaration_list', 'class_body', 'interface_body', 'enum_body',
  'body', 'block', 'field_declaration_list', 'namespace_declaration', 'file_scoped_namespace_declaration',
  'enum_member_declaration_list', 'class_declaration_body',
]);
const SPEC = {
  php: {
    containers: ['class_declaration', 'interface_declaration', 'trait_declaration', 'enum_declaration'],
    functions: ['function_definition', 'method_declaration'],
    imports: ['namespace_use_declaration', 'namespace_definition', 'require_expression', 'include_expression'],
  },
  typescript: {
    containers: ['class_declaration', 'abstract_class_declaration', 'interface_declaration', 'enum_declaration', 'internal_module', 'module'],
    functions: ['function_declaration', 'generator_function_declaration', 'method_definition', 'method_signature', 'abstract_method_signature'],
    imports: ['import_statement'],
    arrows: true,
  },
  javascript: {
    containers: ['class_declaration'],
    functions: ['function_declaration', 'generator_function_declaration', 'method_definition'],
    imports: ['import_statement'],
    arrows: true,
  },
  python: {
    containers: ['class_definition'],
    functions: ['function_definition'],
    imports: ['import_statement', 'import_from_statement'],
  },
  go: {
    containers: [],
    functions: ['function_declaration', 'method_declaration'],
    imports: ['import_declaration', 'package_clause'],
    typeDecls: ['type_declaration'],
  },
  rust: {
    containers: ['impl_item', 'trait_item', 'struct_item', 'enum_item', 'mod_item'],
    functions: ['function_item'],
    imports: ['use_declaration', 'extern_crate_declaration'],
  },
  java: {
    containers: ['class_declaration', 'interface_declaration', 'enum_declaration', 'record_declaration'],
    functions: ['method_declaration', 'constructor_declaration'],
    imports: ['import_declaration', 'package_declaration'],
  },
  csharp: {
    containers: ['class_declaration', 'interface_declaration', 'struct_declaration', 'enum_declaration', 'record_declaration'],
    functions: ['method_declaration', 'constructor_declaration'],
    imports: ['using_directive', 'namespace_declaration_header'],
  },
  ruby: {
    containers: ['class', 'module'],
    functions: ['method', 'singleton_method'],
    imports: [],
  },
  bash: {
    containers: [],
    functions: ['function_definition'],
    imports: [],
  },
};
SPEC.tsx = SPEC.typescript;

const SCORE = { container: 100, topFunction: 90, member: 80, imports: 60, statement: 40 };

function fail(code, message) {
  process.stdout.write(JSON.stringify({ ok: false, code, error: message }) + '\n');
  process.exit(code);
}

function grammarPath(stem) {
  const pkg = dirname(require.resolve('tree-sitter-wasms/package.json'));
  return join(pkg, 'out', `tree-sitter-${stem}.wasm`);
}

function availableGrammars() {
  return Object.entries(GRAMMARS)
    .filter(([, stem]) => existsSync(grammarPath(stem)))
    .map(([token]) => token);
}

async function readStdin() {
  const chunks = [];
  let size = 0;
  for await (const chunk of process.stdin) {
    size += chunk.length;
    if (size > MAX_INPUT_BYTES) fail(5, 'input exceeds helper limit');
    chunks.push(chunk);
  }
  return Buffer.concat(chunks).toString('utf8');
}

function nameOf(node) {
  const named = node.childForFieldName('name');
  if (named) return named.text;
  const typed = node.childForFieldName('type'); // rust impl
  if (typed) return typed.text;
  return null;
}

// First line of a declaration, pulled upward over adjacent comments / attributes
// / decorators so the doc block stays attached to the code it documents.
function startLineWithLeading(node) {
  let start = node.startPosition.row;
  let prev = node.previousSibling;
  while (prev && /comment|attribute|decorator|annotation/.test(prev.type) && prev.endPosition.row >= start - 1) {
    start = prev.startPosition.row;
    prev = prev.previousSibling;
  }
  return start + 1;
}

function arrowName(node) {
  // `const foo = () => {}` / `export const foo = function () {}`
  const decl = node.type === 'export_statement' ? node.namedChildren.find((c) => /lexical_declaration|variable_declaration/.test(c.type)) : node;
  if (!decl || !/lexical_declaration|variable_declaration/.test(decl.type)) return null;
  for (const d of decl.namedChildren) {
    if (d.type !== 'variable_declarator') continue;
    const value = d.childForFieldName('value');
    if (value && /arrow_function|function_expression|^function$/.test(value.type)) {
      return d.childForFieldName('name')?.text ?? null;
    }
  }
  return null;
}

function extract(rootNode, spec) {
  const out = [];
  const containers = new Set(spec.containers);
  const functions = new Set(spec.functions);
  const imports = new Set(spec.imports);
  const typeDecls = new Set(spec.typeDecls ?? []);

  function push(node, score, type, stack, depth, symbolName) {
    if (out.length >= MAX_BREAKPOINTS) return;
    out.push({
      line: startLineWithLeading(node),
      end_line: node.endPosition.row + 1,
      score,
      type,
      symbol: symbolName ? [...stack, symbolName].join(' > ') : stack.length ? stack.join(' > ') : null,
      depth,
    });
  }

  function visit(parent, stack, depth) {
    let inImportRun = false;
    for (const node of parent.namedChildren) {
      const t = node.type;

      if (/comment/.test(t)) continue; // attached to the next declaration instead

      if (imports.has(t)) {
        if (!inImportRun) push(node, SCORE.imports, 'imports', stack, depth, null);
        inImportRun = true;
        continue;
      }
      inImportRun = false;

      if (containers.has(t)) {
        const name = nameOf(node) ?? t;
        push(node, SCORE.container, 'class', stack, depth, name);
        visit(node, [...stack, name], depth + 1);
        continue;
      }
      if (functions.has(t)) {
        const name = nameOf(node) ?? t;
        push(node, depth === 0 ? SCORE.topFunction : SCORE.member, depth === 0 ? 'function' : 'method', stack, depth, name);
        continue;
      }
      if (typeDecls.has(t)) {
        const spec1 = node.namedChildren.find((c) => /type_spec/.test(c.type));
        push(node, SCORE.container, 'type', stack, depth, spec1 ? nameOf(spec1) ?? 'type' : 'type');
        continue;
      }
      if (spec.arrows) {
        const an = arrowName(node);
        if (an) {
          push(node, depth === 0 ? SCORE.topFunction : SCORE.member, 'function', stack, depth, an);
          continue;
        }
      }
      if (COMMON_WRAPPERS.has(t)) {
        // export_statement / decorated_definition wrap the real declaration:
        // classify through them so `export class X` is a class break point.
        const inner = node.namedChildren.find((c) => containers.has(c.type) || functions.has(c.type) || typeDecls.has(c.type));
        if ((t === 'export_statement' || t === 'decorated_definition') && inner) {
          const name = nameOf(inner) ?? inner.type;
          if (containers.has(inner.type)) {
            out.push({ line: startLineWithLeading(node), end_line: node.endPosition.row + 1, score: SCORE.container, type: 'class', symbol: [...stack, name].join(' > '), depth });
            visit(inner, [...stack, name], depth + 1);
          } else {
            out.push({ line: startLineWithLeading(node), end_line: node.endPosition.row + 1, score: depth === 0 ? SCORE.topFunction : SCORE.member, type: depth === 0 ? 'function' : 'method', symbol: [...stack, name].join(' > '), depth });
          }
          continue;
        }
        visit(node, stack, depth);
        continue;
      }
      if (depth === 0) push(node, SCORE.statement, 'statement', stack, depth, null);
    }
  }

  visit(rootNode, [], 0);

  // Deduplicate by line (keep the highest score), sort ascending.
  const byLine = new Map();
  for (const bp of out) {
    const cur = byLine.get(bp.line);
    if (!cur || bp.score > cur.score) byLine.set(bp.line, bp);
  }
  return [...byLine.values()].sort((a, b) => a.line - b.line);
}

async function main() {
  const args = process.argv.slice(2);
  if (args.includes('--probe')) {
    process.stdout.write(JSON.stringify({ ok: true, helper: 1, grammars: availableGrammars() }) + '\n');
    return;
  }
  const li = args.indexOf('--language');
  const token = li >= 0 ? String(args[li + 1] ?? '').toLowerCase() : '';
  if (!token) fail(2, 'usage: index.mjs --language <token> | --probe');
  const stem = GRAMMARS[token];
  if (!stem || !existsSync(grammarPath(stem))) fail(3, `grammar unavailable: ${token}`);

  const source = await readStdin();
  let tree;
  try {
    const { Parser, Language } = await import('web-tree-sitter');
    await Parser.init();
    const parser = new Parser();
    parser.setLanguage(await Language.load(grammarPath(stem)));
    tree = parser.parse(source);
  } catch (e) {
    fail(4, `parse failure: ${e?.constructor?.name ?? 'Error'}`);
  }
  if (!tree) fail(4, 'parse failure: empty tree');
  const breakpoints = extract(tree.rootNode, SPEC[token]);
  process.stdout.write(JSON.stringify({ ok: true, language: token, breakpoints }) + '\n');
}

main().catch((e) => fail(4, `unexpected: ${e?.constructor?.name ?? 'Error'}`));
