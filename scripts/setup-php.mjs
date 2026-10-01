#!/usr/bin/env node
/**
 * Portable PHP + Composer for Windows dev machines – nothing is installed system-wide.
 *
 *   npm run setup:php                 # PHP 7.4 (minimum version) into .cache/php/7.4/ + Composer into .cache/composer.phar
 *   npm run setup:php -- --php 8.4    # another version; the tooling uses the newest one that runs
 *
 * Sources: windows.php.net (official builds, SHA-256 from releases.json) and getcomposer.org
 * (SHA-256 from the published .sha256sum). Both checksums are verified before anything is unpacked.
 * On Linux/macOS install PHP with your package manager instead.
 */
import crypto from 'node:crypto';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { root } from './lib/php.mjs';

const args = process.argv.slice(2);
const version = args.includes('--php') ? args[args.indexOf('--php') + 1] : '7.4';
const cache = path.join(root, '.cache');
const UA = { 'User-Agent': 'mailspur-email-log-setup (node)' };
const sha256 = (buf) => crypto.createHash('sha256').update(buf).digest('hex');

async function download(url) {
	const res = await fetch(url, { headers: UA });
	if (!res.ok) throw new Error(`${url}: HTTP ${res.status}`);
	return Buffer.from(await res.arrayBuffer());
}

async function setupPhp() {
	const target = path.join(cache, 'php', version);
	if (fs.existsSync(path.join(target, 'php.exe'))) {
		console.log(`PHP ${version} already present: ${target}`);
		return target;
	}
	if (process.platform !== 'win32') {
		throw new Error('Portable PHP is only provided for Windows – install PHP via your package manager.');
	}

	const releases = JSON.parse((await download('https://windows.php.net/downloads/releases/releases.json')).toString('utf8'));
	const release = releases[version];
	if (!release) throw new Error(`PHP ${version} not found on windows.php.net (available: ${Object.keys(releases).join(', ')})`);
	// Non-thread-safe x64 build: the right one for CLI use.
	const key = Object.keys(release).find((k) => /^nts-v[cs]\d+-x64$/.test(k));
	const zip = release[key]?.zip;
	if (!zip) throw new Error(`No NTS x64 build for PHP ${version}`);

	console.log(`Downloading PHP ${release.version} (${zip.size}) …`);
	const buf = await download(`https://windows.php.net/downloads/releases/${zip.path}`);
	if (sha256(buf) !== zip.sha256) throw new Error(`PHP ${release.version}: checksum mismatch – download discarded.`);

	fs.mkdirSync(target, { recursive: true });
	const zipFile = path.join(target, 'php.zip');
	fs.writeFileSync(zipFile, buf);
	// bsdtar bundled with Windows 10+ unpacks zip archives (GNU tar from Git Bash cannot, so call it by path).
	const bsdtar = path.join(process.env.SystemRoot || 'C:\\Windows', 'System32', 'tar.exe');
	const tar = spawnSync(bsdtar, ['-xf', 'php.zip'], { cwd: target, stdio: 'inherit' });
	fs.rmSync(zipFile);
	if (tar.status !== 0) throw new Error('Unpacking PHP failed.');

	const ini = fs.readFileSync(path.join(target, 'php.ini-development'), 'utf8')
		.replace(/^;\s*extension_dir\s*=\s*"ext"/m, 'extension_dir = "ext"')
		.replace(/^;(extension=(?:curl|fileinfo|intl|mbstring|openssl|zip|sodium))\s*$/gm, '$1')
		.replace(/^memory_limit\s*=.*$/m, 'memory_limit = 2G');
	fs.writeFileSync(path.join(target, 'php.ini'), ini);
	const check = spawnSync(path.join(target, 'php.exe'), ['-v'], { encoding: 'utf8' });
	if (check.status !== 0) {
		// Typical cause: Windows Smart App Control / WDAC blocks the unsigned-reputation binary.
		console.warn(`PHP ${release.version} was downloaded but Windows refuses to run it (${(check.error?.message || check.stderr || '').trim() || 'blocked'}).`);
		console.warn('Try another version, e.g. `npm run setup:php -- --php 7.4` – the tooling uses any version that runs.');
		return target;
	}
	console.log(`PHP ${release.version} ready: ${target}`);
	return target;
}

async function setupComposer() {
	const phar = path.join(cache, 'composer.phar');
	if (fs.existsSync(phar)) {
		console.log(`Composer already present: ${phar}`);
		return;
	}
	const buf = await download('https://getcomposer.org/download/latest-stable/composer.phar');
	const expected = (await download('https://getcomposer.org/download/latest-stable/composer.phar.sha256sum')).toString('utf8').split(/\s+/)[0];
	if (sha256(buf) !== expected) throw new Error('composer.phar: checksum mismatch – download discarded.');
	fs.mkdirSync(cache, { recursive: true });
	fs.writeFileSync(phar, buf);
	console.log(`Composer ready: ${phar}`);
}

try {
	await setupPhp();
	await setupComposer();
} catch (err) {
	console.error(err.message);
	process.exit(1);
}
