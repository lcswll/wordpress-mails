/**
 * Finds the PHP and Composer executables for the local tooling.
 *
 * Order: $PHP_BINARY → portable PHP from `npm run setup:php` (.cache/php/<version>/) → `php` on PATH.
 * CI runners have real PHP on PATH; the portable copy only exists on Windows dev machines.
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
export const PLUGIN_SLUG = 'outbox-mail-log';
export const pluginDir = path.join(root, PLUGIN_SLUG);

const runs = (bin) => spawnSync(bin, ['-v'], { encoding: 'utf8', shell: bin === 'php' && process.platform === 'win32' }).status === 0;

/**
 * @param {string} [version] Preferred portable version; falls back to any portable version that runs
 *                           (Windows Smart App Control may block some builds).
 * @returns {string|null}
 */
export function findPhp(version) {
	if (process.env.PHP_BINARY) return process.env.PHP_BINARY;
	const dir = path.join(root, '.cache', 'php');
	const versions = fs.existsSync(dir) ? fs.readdirSync(dir).sort().reverse() : [];
	for (const v of version ? [version] : versions) {
		const bin = path.join(dir, v, process.platform === 'win32' ? 'php.exe' : 'php');
		if (fs.existsSync(bin) && runs(bin)) return bin;
	}
	if (version) return null;
	return runs('php') ? 'php' : null;
}

/** @returns {string[]|null} argv prefix to run Composer, e.g. ['php', '.cache/composer.phar'] */
export function findComposer(php) {
	const phar = path.join(root, '.cache', 'composer.phar');
	if (php && fs.existsSync(phar)) return [php, phar];
	const probe = spawnSync('composer', ['--version'], { encoding: 'utf8', shell: process.platform === 'win32' });
	return probe.status === 0 ? ['composer'] : null;
}

/** Runs a command inheriting stdio; returns the exit code. */
export function run(cmd, args, options = {}) {
	const res = spawnSync(cmd, args, { cwd: root, stdio: 'inherit', ...options });
	if (res.error) throw res.error;
	return res.status ?? 1;
}
