#!/usr/bin/env node
/**
 * Local counterpart of the CI (.github/workflows/ci.yml): runs every check, prints a summary, exits 1 on failure.
 *
 *   npm run verify            # everything incl. WordPress runtime tests (Playground) and browser tests
 *   npm run verify -- --fast  # static checks + unit tests only (≈ 30 s, used by the pre-push hook)
 *
 * Only in CI: php -l on PHP 7.4–8.5, the e2e matrix (oldest/newest PHP+WP), the official plugin-check-action,
 * gitleaks over the full history (runs here too if gitleaks is on PATH) and actionlint/zizmor.
 */
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { root } from './lib/php.mjs';

const fast = process.argv.includes('--fast');
const node = process.execPath;
const script = (name, ...args) => [node, [path.join(root, 'scripts', name), ...args]];

const steps = [
	{ name: 'PHPCS (WPCS, VIP, PHPCompatibility)', run: script('php.mjs', 'vendor/bin/phpcs', '-q') },
	{ name: 'PHPStan (level 8)', run: script('php.mjs', 'vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=2G') },
	{ name: 'PHPUnit', run: script('php.mjs', 'vendor/bin/phpunit') },
	{ name: 'ESLint', run: [node, [path.join(root, 'node_modules', 'eslint', 'bin', 'eslint.js'), '--max-warnings=0', '.']] },
	{ name: 'wordpress.org readiness', run: script('repo-checks.mjs') },
	{ name: 'Translations', run: script('i18n.mjs', '--check') },
	{ name: 'Plugin Check (PHPCS rules)', run: script('plugin-check.mjs'), slow: true },
	{ name: 'npm audit', run: ['npm', ['audit', '--audit-level=moderate']], shell: process.platform === 'win32', slow: true },
	{ name: 'composer audit', run: script('php.mjs', '--composer', 'audit', '--locked'), slow: true },
	{ name: 'Runtime + browser tests (Playground)', run: script('e2e.mjs'), slow: true },
	{ name: 'Release ZIP', run: script('build-zip.mjs') },
	{ name: 'gitleaks', run: ['gitleaks', ['git', '.', '--redact', '--no-banner', '--log-level', 'warn']], optional: true },
];

const results = [];
for (const step of steps) {
	if (fast && step.slow) {
		results.push({ name: step.name, status: 'skipped (--fast)' });
		continue;
	}
	console.log(`\n=== ${step.name} ===`);
	const started = Date.now();
	const [cmd, args] = step.run;
	const res = step.shell
		? spawnSync([cmd, ...args].join(' '), { cwd: root, stdio: 'inherit', shell: true })
		: spawnSync(cmd, args, { cwd: root, stdio: 'inherit' });
	const secs = ((Date.now() - started) / 1000).toFixed(0);
	if (res.error?.code === 'ENOENT' && step.optional) {
		results.push({ name: step.name, status: 'skipped (not installed)' });
	} else if (res.error) {
		results.push({ name: step.name, status: `ERROR: ${res.error.message}`, failed: true });
	} else {
		results.push({ name: step.name, status: res.status === 0 ? `ok (${secs} s)` : `FAILED (exit ${res.status}, ${secs} s)`, failed: res.status !== 0 });
	}
}

console.log('\n=== Summary ===');
for (const r of results) console.log(`${r.failed ? '✗' : '✓'} ${r.name.padEnd(40)} ${r.status}`);
const failed = results.filter((r) => r.failed).length;
console.log(failed ? `\n${failed} check(s) failed.` : '\nAll green.');
process.exit(failed ? 1 : 0);
