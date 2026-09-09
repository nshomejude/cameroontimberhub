// CI-style staleness guard for the committed TypeScript SDK (blueprint §4
// "SDKs" — Phase 3+ nice-to-have; this is the lightweight guard for it).
//
// Regenerates api-types.ts from the current OpenAPI export into a temp file
// and diffs it against the committed copy. Fails loudly (non-zero exit) if
// they differ, so a future API change that forgets to run `npm run
// sdk:generate` is caught in CI instead of silently shipping a stale SDK.
//
// Requires storage/app/openapi.json to already exist and be current — run
// `php artisan scramble:export --path=storage/app/openapi.json` first (see
// sdks/typescript/README.md for the full two-step flow). This script only
// knows Node/npm; it deliberately does not shell out to PHP itself so it
// stays usable in a Node-only CI job that receives the exported spec as an
// artifact from a separate PHP step.

import { execFileSync } from 'node:child_process';
import { readFileSync, existsSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(__dirname, '..', '..');
const specPath = join(repoRoot, 'storage', 'app', 'openapi.json');
const committedPath = join(__dirname, 'api-types.ts');

if (!existsSync(specPath)) {
    console.error(
        `Missing ${specPath}.\n` +
        'Run `php artisan scramble:export --path=storage/app/openapi.json` first, ' +
        'then re-run `npm run sdk:check-stale`.'
    );
    process.exit(2);
}

if (!existsSync(committedPath)) {
    console.error(`Missing committed ${committedPath} — run \`npm run sdk:generate\` and commit it.`);
    process.exit(2);
}

const tmpDir = mkdtempSync(join(tmpdir(), 'cth-sdk-check-'));
const tmpOut = join(tmpDir, 'api-types.ts');

try {
    execFileSync(
        'npx',
        ['openapi-typescript', specPath, '-o', tmpOut],
        { cwd: repoRoot, stdio: 'pipe', shell: process.platform === 'win32' }
    );

    const committed = readFileSync(committedPath, 'utf8');
    const fresh = readFileSync(tmpOut, 'utf8');

    if (committed !== fresh) {
        console.error(
            'sdks/typescript/api-types.ts is STALE relative to the current OpenAPI spec.\n' +
            'Run `npm run sdk:generate` and commit the result.'
        );
        process.exit(1);
    }

    console.log('sdks/typescript/api-types.ts is up to date with the current OpenAPI spec.');
} finally {
    rmSync(tmpDir, { recursive: true, force: true });
}
