#!/usr/bin/env node
/**
 * Fails on every way to switch a check off from inside the code.
 *
 *   node scripts/suppressions.mjs
 *
 * - PHPCS: `phpcs:ignore` / `phpcs:disable` only in the plugin, only for the database sniffs of
 *   scripts/phpcs-strict.mjs pass 2, always with explicit codes and a `-- reason`. `phpcs:ignoreFile` and the
 *   legacy `@codingStandardsIgnore*` are never allowed. (Pass 1 ignores annotations anyway; this keeps the
 *   reviewers' Plugin Check run, which honors them, equally clean.)
 * - PHPStan: no `@phpstan-ignore*` comments, no `ignoreErrors`, no baseline.
 * - ESLint / TypeScript: no inline directives (`eslint-disable`, `/* eslint … *\/`, `/* global … *\/`,
 *   `@ts-ignore` …); eslint.config.js must keep `noInlineConfig: true`.
 *
 * Central, reviewed exceptions belong into phpcs.xml.dist / eslint.config.js – never into the code.
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { PLUGIN_SLUG, root } from './lib/php.mjs';

const ALLOWED_CODES = new Set([
	'WordPress.DB.DirectDatabaseQuery.DirectQuery',
	'WordPress.DB.DirectDatabaseQuery.NoCaching',
	'WordPress.DB.DirectDatabaseQuery.SchemaChange',
	'WordPress.DB.SlowDBQuery.slow_db_query_meta_query',
	'WordPress.DB.SlowDBQuery.slow_db_query_meta_key',
	'WordPress.DB.SlowDBQuery.slow_db_query_meta_value',
]);
const SELF = path.relative(root, fileURLToPath(import.meta.url)).split(path.sep).join('/');
// Generated or third-party code.
const SKIP = [/^vendor\//, /^node_modules\//, /^\.cache\//, /^dist\//];

const listed = spawnSync('git', ['ls-files', '-co', '--exclude-standard'], { cwd: root, encoding: 'utf8' });
if (listed.status !== 0) {
	console.error(listed.stderr);
	process.exit(2);
}
const files = listed.stdout.split('\n').filter((f) => f && f !== SELF && !SKIP.some((re) => re.test(f)) && fs.existsSync(path.join(root, f)));

const problems = [];
const report = (file, line, msg) => problems.push(`${file}:${line} ${msg}`);

for (const file of files) {
	const ext = path.extname(file);
	const text = fs.readFileSync(path.join(root, file), 'utf8');
	const lines = text.split(/\r?\n/);

	if (['.php', '.inc'].includes(ext)) {
		lines.forEach((line, i) => {
			const n = i + 1;
			if (/@codingStandards(Ignore|Change)/.test(line)) report(file, n, 'legacy @codingStandardsIgnore annotation');
			if (/phpcs:ignoreFile/.test(line)) report(file, n, 'phpcs:ignoreFile is not allowed');
			if (/@phpstan-ignore|phpstan-ignore-(line|next-line)|@psalm-suppress/.test(line)) report(file, n, 'static-analysis suppression comment');
			const m = line.match(/phpcs:(ignore|disable)\b(.*)$/);
			if (!m) return;
			if (!file.startsWith(`${PLUGIN_SLUG}/`)) {
				report(file, n, `phpcs:${m[1]} outside the plugin – put scoped exceptions into phpcs.xml.dist`);
				return;
			}
			const [codesPart, reason] = m[2].split(/\s--\s/);
			const codes = codesPart.split(',').map((c) => c.trim()).filter(Boolean);
			if (!codes.length) report(file, n, `phpcs:${m[1]} without explicit sniff codes`);
			for (const code of codes) {
				if (!ALLOWED_CODES.has(code)) report(file, n, `phpcs:${m[1]} ${code} – only database sniffs may be annotated; fix the code instead`);
			}
			if (!reason || reason.trim().length < 10) report(file, n, `phpcs:${m[1]} without a "-- reason"`);
		});
	}

	if (['.js', '.jsx', '.mjs', '.cjs', '.ts', '.tsx'].includes(ext)) {
		lines.forEach((line, i) => {
			if (/eslint-(disable|enable)|\/\*\s*eslint[\s-]|\/\*\s*globals?\s|@ts-(ignore|nocheck|expect-error)|jshint|jscs:disable/.test(line)) {
				report(file, i + 1, 'inline lint directive – change eslint.config.js centrally if a rule really does not apply');
			}
		});
	}
}

const neon = ['phpstan.neon', 'phpstan.neon.dist'].filter((f) => fs.existsSync(path.join(root, f)));
for (const f of neon) {
	// Comment lines ("# No baseline …") do not configure anything.
	const text = fs.readFileSync(path.join(root, f), 'utf8').split(/\r?\n/).filter((l) => !/^\s*#/.test(l)).join('\n');
	if (/^\s*ignoreErrors\s*:/m.test(text)) report(f, 0, 'PHPStan ignoreErrors is not allowed');
	if (/baseline/i.test(text)) report(f, 0, 'PHPStan baseline is not allowed');
	if (/reportUnmatchedIgnoredErrors\s*:\s*false/.test(text)) report(f, 0, 'reportUnmatchedIgnoredErrors must stay on');
}
if (!/noInlineConfig:\s*true/.test(fs.readFileSync(path.join(root, 'eslint.config.js'), 'utf8'))) {
	report('eslint.config.js', 0, 'linterOptions.noInlineConfig must be true');
}

if (problems.length) {
	console.error(problems.join('\n'));
	console.error(`\n${problems.length} suppression(s) found.`);
	process.exit(1);
}
console.log(`No suppressions: ${files.length} files checked (only justified database annotations in the plugin).`);
