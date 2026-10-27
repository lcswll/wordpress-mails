#!/usr/bin/env node
/**
 * PHPCS without escape hatches (phpcs.xml.dist: full WordPress standard, VIP security sniffs, PHPCompatibility).
 *
 *   node scripts/phpcs-strict.mjs
 *
 * Pass 1 runs every sniff except the two database sniffs with --ignore-annotations: a `phpcs:ignore` or
 * `phpcs:disable` comment has no effect at all, so escaping, nonces, sanitizing, prepared SQL, silenced errors …
 * can only be fixed, never commented out. Exceptions exist only in phpcs.xml.dist, scoped and explained.
 *
 * Pass 2 runs only WordPress.DB.DirectDatabaseQuery and WordPress.DB.SlowDBQuery, which honor annotations: a
 * statistics plugin with its own tables cannot avoid them, so every such query carries an annotation with a
 * reason – scripts/suppressions.mjs makes sure annotations never name anything else.
 *
 * Both passes report every severity (--severity=1; PHPCS hides severity < 5 by default) and fail on warnings.
 */
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { root } from './lib/php.mjs';

const DB_SNIFFS = 'WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery';
const common = ['-q', '-s', '--severity=1', '--runtime-set', 'ignore_warnings_on_exit', '0', '--runtime-set', 'ignore_errors_on_exit', '0'];
const passes = [
	{ name: 'all sniffs, annotations ignored', args: [...common, '--ignore-annotations', `--exclude=${DB_SNIFFS}`] },
	{ name: 'database sniffs, justified annotations', args: [...common, `--sniffs=${DB_SNIFFS}`] },
];

let failed = 0;
for (const pass of passes) {
	console.log(`PHPCS: ${pass.name}`);
	const res = spawnSync(process.execPath, [path.join(root, 'scripts', 'php.mjs'), 'vendor/bin/phpcs', ...pass.args], { cwd: root, stdio: 'inherit' });
	if (res.status !== 0) failed++;
}
process.exit(failed ? 1 : 0);
