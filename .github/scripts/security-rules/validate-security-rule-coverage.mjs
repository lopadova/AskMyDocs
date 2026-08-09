import { readFileSync, statSync } from 'node:fs';

const requiredFiles = [
    '.claude/rules/rule-security-ai-llm.md',
    '.claude/rules/rule-security-ai-actions.md',
    '.claude/rules/rule-security-auth-data-boundaries.md',
    '.claude/rules/rule-security-input-data-protection.md',
    '.claude/rules/rule-security-runtime-browser.md',
    '.claude/rules/rule-security-ci-supply-chain.md',
    '.claude/rules/rule-security-control-coverage.md',
    '.claude/rules/rule-security-instruction-sync.md',
    '.claude/skills/secure-ai-surface/SKILL.md',
    '.claude/skills/security-audit/SKILL.md',
    '.claude/skills/security-pr-check/SKILL.md',
    '.github/instructions/security-ai.instructions.md',
    '.github/instructions/security-actions.instructions.md',
    '.github/instructions/security-baseline.instructions.md',
    'docs/security/LORENZO_SECURITY_EXPERIENCE.md',
    'docs/security/SECURITY_CHECKLIST.md',
    'docs/security/THREAT_MODEL.md',
    'docs/security/AUDIT-FINDINGS-2026-08.md',
];

const securityControls = [
    'rule-ai-agent-actions', 'rule-ai-llm-security', 'ajax-route-hardening',
    'api-login-gates', 'api-token-lifecycle', 'audit-trail-integrity',
    'auth-hardening', 'aws-iam-sigv4', 'backoffice-exposure', 'blocking-ci-gate',
    'ci-workflow-permissions', 'client-ip-identity', 'edge-credential-leak-protection',
    'content-security-policy', 'control-coverage', 'cors-config',
    'db-least-privilege', 'dependency-security', 'deserialization', 'dns-dangling',
    'dormant-access', 'effective-security-population', 'email-auth-dns',
    'env-gate-fail-closed', 'export-formula-injection',
    'external-response-validation', 'fail-closed-security-controls',
    'frontend-secrets', 'gdpr-data-inventory', 'http-client-service',
    'tenant-object-authorization', 'key-management', 'logging-security',
    'multi-surface-security-gates', 'password-breach-check', 'password-policy',
    'path-containment', 'pii-encryption', 'public-flow-throttle',
    'race-conditions', 'raw-sql-inventory', 'redis-production-posture', 'resource-limits',
    'secret-settings-naming', 'security-boundaries', 'security-checklist',
    'security-inventory', 'security-setting-shape', 'shell-command-array',
    'signed-commits', 'signed-url-tokens', 'ssrf-outbound',
    'multi-repository-security-coverage', 'supply-chain-ci', 'sync-ai-instructions',
    'tls-hsts', 'upload-hardening', 'webhook-verify-before-effects', 'xml-parsing',
    'rule-build-verification', 'accepted-security-debt',
    'admin-authorization-granularity', 'ai-data-flow', 'ai-initiating-user',
    'ai-provider-supply-chain', 'appkey-rotation', 'backoffice-no-remember',
    'circuit-breaker', 'csp-nonce-cache', 'csp-report-collection',
    'dependency-regression-gate', 'deploy-credentials', 'dev-dump-anonymization',
    'exposed-public-files', 'frontend-tenant-architecture', 'gdpr-consent-and-access',
    'http-surface-inventory', 'log-retention-and-siem', 'postmessage-origin',
    'processor-register', 'production-posture',
    'request-correlation', 'response-headers', 'route-exposure-regression-gate',
    'sast-regression-gate', 'session-device-binding', 'sri-pinned-cdn',
    'third-party-resources',
];

const requiredIds = [
    'SEC-LLM-001', 'SEC-AI-ACT-001', 'SEC-AJAX-001', 'SEC-TOKEN-001',
    'SEC-IDOR-001', 'SEC-BOUNDARY-001', 'SEC-CORS-001', 'SEC-CSP-001',
    'SEC-ENV-001', 'SEC-LOG-001', 'SEC-SSRF-001', 'SEC-UPLOAD-001',
    'SEC-WEBHOOK-001', 'SEC-FAILCLOSED-001', 'SEC-COVERAGE-001',
    'SEC-RACE-001', 'SEC-DEPS-001', 'SEC-CI-PERM-001', 'SYNC-AI-001',
];

const aiHardeningLessons = [
    'immutable initiating identity',
    'provider/model/base-URL policy',
    'atomic spend',
    'Idempotency uniqueness',
    'tool results are data',
    'real decoding, MIME and size checks',
    'runtime-blocked in production',
    'checked in both directions',
];

const failures = [];
const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const regexEscapeProbe = 'control.*+?^${}()|[]\\';
try {
    const probeExpression = new RegExp(`^${escapeRegExp(regexEscapeProbe)}$`);
    if (!probeExpression.test(regexEscapeProbe)) {
        failures.push('validator RegExp escaping self-check failed');
    }
} catch (error) {
    failures.push(`validator RegExp escaping is invalid (${error.message})`);
}

const read = (path) => {
    try {
        return readFileSync(path, 'utf8');
    } catch (error) {
        failures.push(`${path}: missing or unreadable (${error.code ?? error.message})`);
        return '';
    }
};

const contentByFile = new Map(requiredFiles.map((path) => [path, read(path)]));
const canonical = [...contentByFile.entries()]
    .filter(([path]) => path.startsWith('.claude/'))
    .map(([, content]) => content)
    .join('\n');
const matrix = contentByFile.get('docs/security/LORENZO_SECURITY_EXPERIENCE.md') ?? '';

for (const id of requiredIds) {
    if (!canonical.includes(id)) {
        failures.push(`required security identifier is absent from canonical rules/skills: ${id}`);
    }
}

for (const lesson of aiHardeningLessons) {
    if (!canonical.toLowerCase().includes(lesson.toLowerCase())) {
        failures.push(`AI hardening lesson is absent: ${lesson}`);
    }
}

const controlSet = new Set(securityControls);

// Forward: every declared control has a disposition row in the matrix.
for (const slug of securityControls) {
    const expression = new RegExp(`^\\| ${escapeRegExp(slug)} \\|`, 'm');
    if (!expression.test(matrix)) {
        failures.push(`security control has no explicit disposition: ${slug}`);
    }
}

// Reverse: every disposition row in the matrix is a declared control. Catches a
// stale/orphan matrix row that the forward check alone would silently accept
// (the bidirectional contract required by rule-security-control-coverage §2).
const dispositionRowPattern =
    /^\| ([a-z][a-z0-9-]+) \| (Adopted|Adapted|Infrastructure|N\/A)/gm;
for (const match of matrix.matchAll(dispositionRowPattern)) {
    const slug = match[1];
    if (!controlSet.has(slug)) {
        failures.push(`matrix disposition row is not a declared control (stale/orphan): ${slug}`);
    }
}

// Parity: SECURITY_CHECKLIST.md §1 must reference every control slug, so the
// checklist and the disposition matrix can never drift apart.
const checklist = contentByFile.get('docs/security/SECURITY_CHECKLIST.md') ?? '';
for (const slug of securityControls) {
    const cell = new RegExp(`\\| ${escapeRegExp(slug)} \\|`);
    if (!cell.test(checklist)) {
        failures.push(`SECURITY_CHECKLIST.md §1 is missing control row: ${slug}`);
    }
}

const mirrors = requiredFiles.filter((path) => path.endsWith('.instructions.md'));
for (const path of mirrors) {
    try {
        const bytes = statSync(path).size;
        if (bytes > 4_000) {
            failures.push(`${path}: ${bytes} bytes exceeds the 4000-byte mirror budget`);
        }
    } catch {
        // Missing files were already reported by read().
    }
}

const packageJson = read('package.json');
try {
    const parsed = JSON.parse(packageJson);
    const expected = 'node .github/scripts/security-rules/validate-security-rule-coverage.mjs';
    if (parsed.scripts?.['security:rules'] !== expected) {
        failures.push(`package.json: security:rules must equal "${expected}"`);
    }
} catch (error) {
    failures.push(`package.json: invalid JSON (${error.message})`);
}

const workflow = read('.github/workflows/tests.yml');
if (!workflow.includes('npm run security:rules')) {
    failures.push('.github/workflows/tests.yml: security-rule validator is not wired into CI');
}

const criticWrapper = read('scripts/local-critic-loop.sh');
const unquotedHeredocPatterns = [
    /<<([A-Za-z_][A-Za-z0-9_]*)\r?\n([\s\S]*?)\r?\n\1(?:\r?\n|$)/g,
    /<<-([A-Za-z_][A-Za-z0-9_]*)\r?\n([\s\S]*?)\r?\n\t*\1(?:\r?\n|$)/g,
];
for (const pattern of unquotedHeredocPatterns) {
    for (const match of criticWrapper.matchAll(pattern)) {
        const substitutions = [];
        if (match[2].includes('`')) {
            substitutions.push('backticks');
        }
        if (match[2].includes('$(')) {
            substitutions.push('$()');
        }
        if (substitutions.length > 0) {
            failures.push(
                'scripts/local-critic-loop.sh: unquoted heredoc contains command '
                + `substitution (${substitutions.join(', ')}); the shell would execute prompt text`,
            );
        }
    }
}
if (!criticWrapper.includes("tr -d '\\000\\r'")) {
    failures.push(
        'scripts/local-critic-loop.sh: SUMMARY parser must normalize UTF-16 NUL bytes',
    );
}
if (!criticWrapper.includes("grep -aE '^SUMMARY: [0-9]+ must-fix, [0-9]+ nit$'")) {
    failures.push(
        'scripts/local-critic-loop.sh: SUMMARY parser must require the exact verdict contract',
    );
}
if (!criticWrapper.includes('! "$MUST_FIX" =~ ^[0-9]+$')) {
    failures.push(
        'scripts/local-critic-loop.sh: SUMMARY count must be validated before arithmetic',
    );
}
if (!criticWrapper.includes('! "$NIT" =~ ^[0-9]+$')) {
    failures.push(
        'scripts/local-critic-loop.sh: SUMMARY nit count must be validated',
    );
}

const reviewInstructions = read('.github/instructions/r-rules.instructions.md');
for (const mirror of mirrors) {
    if (!reviewInstructions.includes(mirror)) {
        failures.push(`r-rules.instructions.md: mirror is not registered: ${mirror}`);
    }
}

if (failures.length > 0) {
    console.error(`Security-rule contract failed with ${failures.length} error(s):`);
    failures.forEach((failure) => console.error(`- ${failure}`));
    process.exit(1);
}

console.log(
    `Security-rule contract OK: ${requiredIds.length} identifiers, `
    + `${securityControls.length} control dispositions (bidirectional + checklist parity), `
    + `${aiHardeningLessons.length} AI hardening lessons, `
    + `${mirrors.length} Copilot mirrors, safe R40 prompt and parser.`,
);
