/**
 * Runs the WordPress Playground CLI (PHP-WASM + SQLite, no Docker needed) from node_modules.
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import { pluginDir, root, PLUGIN_SLUG } from './php.mjs';

const require = createRequire(import.meta.url);

function cliEntry() {
	const pkgFile = require.resolve('@wp-playground/cli/package.json', { paths: [root] });
	const pkg = JSON.parse(fs.readFileSync(pkgFile, 'utf8'));
	const bin = typeof pkg.bin === 'string' ? pkg.bin : Object.values(pkg.bin)[0];
	return path.join(path.dirname(pkgFile), bin);
}

/**
 * Mount arguments in the --mount-dir form (works with Windows drive letters).
 *
 * @param {Record<string,string>} extra vfs path → host path
 */
export function mountArgs(extra = {}) {
	const mounts = { [`/wordpress/wp-content/plugins/${PLUGIN_SLUG}`]: pluginDir, ...extra };
	return Object.entries(mounts).flatMap(([vfs, host]) => ['--mount-dir', host, vfs]);
}

/**
 * @param {string[]} args CLI arguments, e.g. ['run-blueprint', '--blueprint=…']
 * @param {{quiet?: boolean}} [options]
 * @returns {Promise<{code: number, output: string}>}
 */
export function playground(args, { quiet = false } = {}) {
	return new Promise((resolve) => {
		const child = spawn(process.execPath, [cliEntry(), ...args], { cwd: root, stdio: ['ignore', 'pipe', 'pipe'] });
		let output = '';
		const onData = (chunk) => {
			const text = chunk.toString();
			output += text;
			// Known php-wasm noise on Windows hosts.
			const lines = text.split('\n').filter((l) => l.trim() && !/lockWholeFile|stale Playground temp dirs/.test(l));
			if (!quiet && lines.length) process.stdout.write(lines.join('\n') + '\n');
		};
		child.stdout.on('data', onData);
		child.stderr.on('data', onData);
		child.on('close', (code) => resolve({ code: code ?? 1, output }));
	});
}

export { cliEntry };
