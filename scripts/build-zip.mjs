#!/usr/bin/env node
/**
 * Builds the installable plugin ZIP and verifies it.
 *
 *   npm run build      → dist/mailspur-email-log-<version>.zip (+ .sha256, manifest.json)
 *
 * - Root folder inside the ZIP is the plugin slug (what WordPress and wordpress.org expect).
 * - Only an allowlist of file types is packed; dotfiles, maps and dev files never are.
 * - Reproducible: sorted entries, fixed timestamps (SOURCE_DATE_EPOCH or last commit), LF line endings –
 *   the same commit gives the same SHA-256 on Windows and in CI.
 * - The written ZIP is read back and checked (structure, header version, size limit, ABSPATH guards).
 */
import { spawnSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import yauzl from 'yauzl';
import yazl from 'yazl';
import { PLUGIN_SLUG, pluginDir, root } from './lib/php.mjs';

const TEXT = new Set(['.php', '.js', '.css', '.txt', '.po', '.json', '.svg', '.md']);
const BINARY = new Set(['.png', '.jpg', '.jpeg', '.gif', '.webp', '.mo']);
const MAX_BYTES = 10 * 1024 * 1024; // wordpress.org upload limit.

const mainFile = path.join(pluginDir, `${PLUGIN_SLUG}.php`);
const version = /^\s*\*\s*Version:\s*(\S+)/m.exec(fs.readFileSync(mainFile, 'utf8'))?.[1];
if (!version) throw new Error('No Version header in the main plugin file.');

const epoch = Number(process.env.SOURCE_DATE_EPOCH) ||
	Number(spawnSync('git', ['log', '-1', '--format=%ct'], { cwd: root, encoding: 'utf8' }).stdout.trim()) ||
	Math.floor(Date.UTC(2026, 0, 1) / 1000);
const mtime = new Date(epoch * 1000);

function collect(dir, rel = '') {
	const out = [];
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const relPath = rel ? `${rel}/${entry.name}` : entry.name;
		if (entry.name.startsWith('.')) throw new Error(`Hidden file in plugin folder: ${relPath}`);
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			out.push(...collect(full, relPath));
			continue;
		}
		const ext = path.extname(entry.name).toLowerCase();
		if (!TEXT.has(ext) && !BINARY.has(ext)) throw new Error(`File type not allowed in the release: ${relPath}`);
		out.push({ relPath, full, text: TEXT.has(ext) });
	}
	return out;
}

const files = collect(pluginDir).sort((a, b) => a.relPath.localeCompare(b.relPath));
const dist = path.join(root, 'dist');
fs.mkdirSync(dist, { recursive: true });
const zipName = `${PLUGIN_SLUG}-${version}.zip`;
const zipPath = path.join(dist, zipName);

await new Promise((resolve, reject) => {
	const zip = new yazl.ZipFile();
	for (const f of files) {
		let data = fs.readFileSync(f.full);
		if (f.text) data = Buffer.from(data.toString('utf8').replace(/\r\n/g, '\n'), 'utf8');
		zip.addBuffer(data, `${PLUGIN_SLUG}/${f.relPath}`, { mtime, mode: 0o100644, compress: true });
	}
	zip.end();
	zip.outputStream.pipe(fs.createWriteStream(zipPath)).on('close', resolve).on('error', reject);
});

// ---------------------------------------------------------------- verify the written archive.
const entries = await new Promise((resolve, reject) => {
	yauzl.open(zipPath, { lazyEntries: true }, (err, zip) => {
		if (err) return reject(err);
		const found = new Map();
		zip.on('entry', (entry) => {
			zip.openReadStream(entry, (e, stream) => {
				if (e) return reject(e);
				const chunks = [];
				stream.on('data', (c) => chunks.push(c));
				stream.on('end', () => {
					found.set(entry.fileName, Buffer.concat(chunks));
					zip.readEntry();
				});
			});
		});
		zip.on('end', () => resolve(found));
		zip.readEntry();
	});
});

const problems = [];
const size = fs.statSync(zipPath).size;
if (size > MAX_BYTES) problems.push(`ZIP is ${size} bytes – wordpress.org accepts at most ${MAX_BYTES}.`);
for (const name of entries.keys()) {
	if (!name.startsWith(`${PLUGIN_SLUG}/`)) problems.push(`Entry outside the plugin folder: ${name}`);
	if (/(^|\/)(node_modules|vendor|tests?|\.git)\//.test(name) || /\.(map|zip|log)$/.test(name)) problems.push(`Dev file in ZIP: ${name}`);
	if (name.endsWith('.php') && !name.includes('/languages/')) {
		const head = entries.get(name).toString('utf8').slice(0, 2000);
		const guard = name.endsWith('/uninstall.php') ? /defined\(\s*'WP_UNINSTALL_PLUGIN'\s*\)\s*\|\|\s*exit/ : /defined\(\s*'ABSPATH'\s*\)\s*\|\|\s*exit/;
		if (!guard.test(head)) problems.push(`Missing direct-access guard: ${name}`);
	}
}
for (const required of [`${PLUGIN_SLUG}/${PLUGIN_SLUG}.php`, `${PLUGIN_SLUG}/readme.txt`, `${PLUGIN_SLUG}/uninstall.php`]) {
	if (!entries.has(required)) problems.push(`Missing in ZIP: ${required}`);
}
const zippedVersion = /^\s*\*\s*Version:\s*(\S+)/m.exec(entries.get(`${PLUGIN_SLUG}/${PLUGIN_SLUG}.php`)?.toString('utf8') ?? '')?.[1];
if (zippedVersion !== version) problems.push(`Version in ZIP (${zippedVersion}) differs from ${version}.`);

if (problems.length) {
	fs.rmSync(zipPath, { force: true });
	console.error('ZIP verification failed:\n  ' + problems.join('\n  '));
	process.exit(1);
}

const sha256 = crypto.createHash('sha256').update(fs.readFileSync(zipPath)).digest('hex');
fs.writeFileSync(`${zipPath}.sha256`, `${sha256}  ${zipName}\n`);
const commit = spawnSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).stdout.trim();
fs.writeFileSync(
	path.join(dist, 'manifest.json'),
	JSON.stringify({ slug: PLUGIN_SLUG, version, file: zipName, sha256, bytes: size, files: entries.size, commit, built: mtime.toISOString() }, null, 2) + '\n',
);
console.log(`${zipName}: ${entries.size} files, ${(size / 1024).toFixed(1)} KB, sha256 ${sha256}`);
