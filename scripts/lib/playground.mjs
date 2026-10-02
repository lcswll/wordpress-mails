/**
 * Runs the WordPress Playground CLI (PHP-WASM + SQLite, no Docker needed) from node_modules.
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import { pluginDir, root, PLUGIN_SLUG } from './php.mjs';

const require = createRequire(import.meta.url);
const NOISE = /lockWholeFile|stale Playground temp dirs/;

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
 * @param {object}   [options]
 * @param {boolean}  [options.quiet]     Do not stream the CLI output.
 * @param {number}   [options.timeoutMs] Kill the CLI after this long (exit code 124).
 * @param {() => boolean} [options.doneWhen] Treat the run as finished once this returns true. On Linux the CLI
 *                                           can stay alive after the blueprint completed (open worker handles).
 * @returns {Promise<{code: number, output: string, timedOut: boolean}>}
 */
export function playground(args, { quiet = false, timeoutMs = 0, doneWhen = null } = {}) {
	return new Promise((resolve) => {
		const child = spawn(process.execPath, [cliEntry(), ...args], { cwd: root, stdio: ['ignore', 'pipe', 'pipe'] });
		let output = '';
		let settled = false;
		const timers = [];

		const finish = (code, timedOut = false) => {
			if (settled) return;
			settled = true;
			timers.forEach(clearInterval);
			if (child.exitCode === null) child.kill('SIGKILL');
			resolve({ code, output, timedOut });
		};

		const onData = (chunk) => {
			const text = chunk.toString();
			output += text;
			const lines = text.split('\n').filter((l) => l.trim() && !NOISE.test(l));
			if (!quiet && lines.length) process.stdout.write(lines.join('\n') + '\n');
		};
		child.stdout.on('data', onData);
		child.stderr.on('data', onData);
		child.on('close', (code) => finish(code ?? 1));

		if (doneWhen) {
			timers.push(setInterval(() => {
				// Small grace period so the last step can flush its output before the process is stopped.
				if (doneWhen()) setTimeout(() => finish(0), 2000);
			}, 1000));
		}
		if (timeoutMs) {
			timers.push(setInterval(() => finish(124, true), timeoutMs));
		}
	});
}

export { cliEntry };
