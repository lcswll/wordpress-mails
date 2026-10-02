#!/usr/bin/env node
/**
 * Runtime tests in a real WordPress (WordPress Playground: PHP-WASM + SQLite, no Docker).
 *
 *   npm run test:e2e                          # PHP 8.3, latest WordPress: self-test + Plugin Check + browser UI tests
 *   npm run test:e2e -- --php 7.4 --wp 6.5    # oldest supported combination
 *   npm run test:e2e -- --no-ui               # without the Playwright browser tests
 *
 * 1. tests/e2e/selftest.blueprint.json activates the plugin, installs the official Plugin Check plugin plus
 *    WP Mail Logging and Email Log, and runs tests/e2e/plugin-check.php (static Plugin Check checks),
 *    tests/e2e/import-test.php (import from other plugins) and tests/e2e/selftest.php (integration assertions).
 *    Results land in .cache/e2e-out/*.json.
 * 2. Playwright (playwright.config.js) starts a Playground server with seeded mails and tests the admin screen.
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { root } from './lib/php.mjs';
import { mountArgs, playground } from './lib/playground.mjs';

// Playground's php-wasm needs JSPI (Node 24+); the asyncify fallback on Node 22 crashes with PHP 7.4
// ("RuntimeError: unreachable").
if (Number(process.versions.node.split('.')[0]) < 24) {
	console.error(`Node ${process.versions.node}: the WordPress runtime tests need Node 24 or newer.`);
	process.exit(2);
}

const args = process.argv.slice(2);
const opt = (name, fallback) => (args.includes(`--${name}`) ? args[args.indexOf(`--${name}`) + 1] : fallback);
const php = opt('php', '8.3');
const wp = opt('wp', 'latest');
const ui = !args.includes('--no-ui');

const out = path.join(root, '.cache', 'e2e-out');
fs.rmSync(out, { recursive: true, force: true });
fs.mkdirSync(out, { recursive: true });

let failed = false;

// The CLI's --php/--wp flags do not reach run-blueprint; preferredVersions in the blueprint does.
const blueprint = JSON.parse(fs.readFileSync(path.join(root, 'tests', 'e2e', 'selftest.blueprint.json'), 'utf8'));
blueprint.preferredVersions = { php, wp };

// Module integration tests: tests/e2e/features/<name>.php run before the self-test (which uninstalls the
// plugin at its end) and write /e2e-out/features/<name>.json in the same format as selftest.json.
const featureDir = path.join(root, 'tests', 'e2e', 'features');
const features = fs.existsSync(featureDir) ? fs.readdirSync(featureDir).filter((f) => f.endsWith('.php')).sort() : [];
fs.mkdirSync(path.join(out, 'features'), { recursive: true });
const selftestIndex = blueprint.steps.findIndex((step) => 'runPHP' === step.step && step.code.includes('selftest.php'));
blueprint.steps.splice(
	selftestIndex,
	0,
	...features.map((file) => ({ step: 'runPHP', code: `<?php require '/e2e/features/${file}';` })),
);
const blueprintFile = path.join(root, '.cache', 'e2e-selftest.blueprint.json');
fs.writeFileSync(blueprintFile, JSON.stringify(blueprint, null, '\t'));

console.log(`\n=== Integration self-test + Plugin Check (PHP ${php}, WordPress ${wp}) ===`);
const run = await playground(
	[
		'run-blueprint',
		`--blueprint=${blueprintFile}`,
		...mountArgs({ '/e2e': path.join(root, 'tests', 'e2e'), '/e2e-out': out }),
	],
	{
		quiet: !process.env.CI, // In CI the full output helps when something hangs.
		timeoutMs: 10 * 60_000,
		// selftest.php writes its report as the very last step.
		doneWhen: () => fs.existsSync(path.join(out, 'selftest.json')),
	},
);
if (run.timedOut) console.error('Playground did not finish within 10 minutes.');

const read = (file) => {
	try {
		return JSON.parse(fs.readFileSync(path.join(out, file), 'utf8'));
	} catch {
		return null;
	}
};

const selftest = read('selftest.json');
if (!selftest) {
	failed = true;
	console.error('No self-test report – Playground output:\n' + run.output.split('\n').filter((l) => !/lockWholeFile|stale Playground/.test(l)).join('\n'));
} else {
	console.log(`PHP ${selftest.php}, WordPress ${selftest.wp}: ${selftest.passed} passed, ${selftest.failed} failed`);
	for (const r of selftest.results) {
		console.log(`  ${r.ok ? '✓' : '✗'} ${r.name}${r.ok ? '' : `\n      ${JSON.stringify(r.detail)}`}`);
	}
	failed ||= selftest.failed > 0 || run.code !== 0;
}

const imports = read('import.json');
if (!imports) {
	failed = true;
	console.error('No import report.');
} else {
	console.log(`
Import from other plugins: ${imports.passed} passed, ${imports.failed} failed`);
	for (const r of imports.results) {
		console.log(`  ${r.ok ? '✓' : '✗'} ${r.name}${r.ok ? '' : `
      ${JSON.stringify(r.detail)}`}`);
	}
	failed ||= imports.failed > 0;
}

for (const file of features) {
	const name = path.basename(file, '.php');
	const report = read(`features/${name}.json`);
	if (!report) {
		failed = true;
		console.error(`\nNo report from tests/e2e/features/${file}.`);
		continue;
	}
	console.log(`\nFeature "${name}": ${report.passed} passed, ${report.failed} failed`);
	for (const r of report.results) {
		console.log(`  ${r.ok ? '✓' : '✗'} ${r.name}${r.ok ? '' : `\n      ${JSON.stringify(r.detail)}`}`);
	}
	failed ||= report.failed > 0;
}

const pcp = read('plugin-check.json');
if (!pcp) {
	failed = true;
	console.error('No Plugin Check report.');
} else {
	// Accepted findings need a reason: tests/e2e/plugin-check-allowlist.json { "<code>": "why" }.
	const allow = JSON.parse(fs.readFileSync(path.join(root, 'tests', 'e2e', 'plugin-check-allowlist.json'), 'utf8'));
	const open = pcp.findings.filter((f) => !(f.code in allow));
	console.log(`\nPlugin Check (${pcp.checks.length} static checks): ${pcp.findings.length} finding(s), ${open.length} not allow-listed`);
	for (const f of pcp.findings) {
		console.log(`  ${f.code in allow ? '~' : '✗'} ${f.type} ${f.file}:${f.line} ${f.code} – ${f.message}`);
	}
	failed ||= open.length > 0;
}

if (ui) {
	console.log(`\n=== Browser UI tests (Playwright) ===`);
	const res = spawnSync(process.execPath, [path.join(root, 'node_modules', '@playwright', 'test', 'cli.js'), 'test'], {
		cwd: root,
		stdio: 'inherit',
		env: { ...process.env, E2E_PHP: php, E2E_WP: wp },
	});
	failed ||= res.status !== 0;
}

console.log(failed ? '\nE2E: FAILED' : '\nE2E: all green');
process.exit(failed ? 1 : 0);
