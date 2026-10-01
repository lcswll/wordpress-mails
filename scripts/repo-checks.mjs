#!/usr/bin/env node
/**
 * wordpress.org readiness: plugin header, readme.txt, versions, changelog and directory assets.
 *
 *   npm run check                 # offline checks
 *   npm run check -- --online     # also compare "Tested up to" with the current WordPress release
 *   npm run check -- --tag v1.2.0 # release: the Git tag must match the plugin version
 *
 * Rules follow the plugin handbook (developer.wordpress.org/plugins/wordpress-org/) and the readme
 * validator: e.g. short description ≤ 150 characters, at most 5 tags, Stable tag = Version.
 */
import fs from 'node:fs';
import path from 'node:path';
import { PLUGIN_SLUG, pluginDir, root } from './lib/php.mjs';

const args = process.argv.slice(2);
const online = args.includes('--online');
const tag = args.includes('--tag') ? args[args.indexOf('--tag') + 1] : null;

const errors = [];
const warnings = [];
const ok = [];
const expect = (cond, message, level = errors) => (cond ? ok.push(message) : level.push(message));

const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8').replace(/\r\n/g, '\n');
const cmpVersion = (a, b) => {
	const pa = a.split('.').map(Number);
	const pb = b.split('.').map(Number);
	for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
		if ((pa[i] || 0) !== (pb[i] || 0)) return (pa[i] || 0) - (pb[i] || 0);
	}
	return 0;
};

// ------------------------------------------------------------------ plugin header.
const main = read(`${PLUGIN_SLUG}/${PLUGIN_SLUG}.php`);
const header = {};
for (const m of main.matchAll(/^\s*\*\s*([A-Za-z][A-Za-z ]+?):\s*(.+?)\s*$/gm)) header[m[1]] = m[2];

for (const field of ['Plugin Name', 'Description', 'Version', 'Requires at least', 'Requires PHP', 'Author', 'License', 'License URI', 'Text Domain']) {
	expect(Boolean(header[field]), `header has "${field}"`);
}
expect(/^\d+\.\d+\.\d+$/.test(header.Version || ''), `Version "${header.Version}" is semver (x.y.z)`);
expect(header['Text Domain'] === PLUGIN_SLUG, `Text Domain equals the slug "${PLUGIN_SLUG}"`);
expect(/GPL/i.test(header.License || ''), 'license is GPL-compatible');
if (header['Domain Path']) expect(fs.existsSync(path.join(pluginDir, header['Domain Path'])), `Domain Path ${header['Domain Path']} exists`);
const constVersion = /const VERSION\s*=\s*'([^']+)'/.exec(main)?.[1];
expect(constVersion === header.Version, `const VERSION (${constVersion}) equals the header Version (${header.Version})`);
expect((header.Description || '').length <= 150, 'header description ≤ 150 characters', warnings);

// ----------------------------------------------------------------------- readme.
const readme = read(`${PLUGIN_SLUG}/readme.txt`);
const lines = readme.split('\n');
const name = /^===\s*(.+?)\s*===$/.exec(lines[0])?.[1];
expect(name === header['Plugin Name'], `readme title "${name}" equals Plugin Name`);

const meta = {};
let i = 1;
for (; i < lines.length && lines[i].trim() !== ''; i++) {
	const m = /^([^:]+):\s*(.*)$/.exec(lines[i]);
	if (m) meta[m[1].trim()] = m[2].trim();
}
for (const field of ['Contributors', 'Tags', 'Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License']) {
	expect(Boolean(meta[field]), `readme has "${field}"`);
}
const tags = (meta.Tags || '').split(',').map((t) => t.trim()).filter(Boolean);
expect(tags.length > 0 && tags.length <= 5, `${tags.length} tag(s) (wordpress.org shows at most 5)`);
expect(meta['Stable tag'] === header.Version, `Stable tag (${meta['Stable tag']}) equals Version (${header.Version})`);
expect(meta['Requires at least'] === header['Requires at least'], 'readme/header "Requires at least" match');
expect(meta['Requires PHP'] === header['Requires PHP'], 'readme/header "Requires PHP" match');
expect(/^\d+\.\d+$/.test(meta['Tested up to'] || ''), `"Tested up to" (${meta['Tested up to']}) is major.minor`);
expect(cmpVersion(meta['Tested up to'] || '0', meta['Requires at least'] || '0') >= 0, '"Tested up to" ≥ "Requires at least"');

while (i < lines.length && lines[i].trim() === '') i++;
const short = lines[i] || '';
expect(short.length > 0 && !short.startsWith('=='), 'short description present');
expect(short.length <= 150, `short description is ${short.length}/150 characters`);

const sections = [...readme.matchAll(/^==\s*([^=]+?)\s*==$/gm)].map((m) => m[1]);
for (const s of ['Description', 'Installation', 'Frequently Asked Questions', 'Changelog']) {
	expect(sections.includes(s), `readme section "${s}"`);
}
const changelog = readme.split(/^== Changelog ==$/m)[1] || '';
expect(new RegExp(`^= ${header.Version.replace(/\./g, '\\.')}`, 'm').test(changelog), `changelog has an entry for ${header.Version}`);

// ---------------------------------------------------------- wordpress.org assets.
const assetsDir = path.join(root, '.wordpress-org');
const pngSize = (file) => {
	const b = fs.readFileSync(file);
	return b.toString('ascii', 1, 4) === 'PNG' ? [b.readUInt32BE(16), b.readUInt32BE(20)] : null;
};
for (const [file, w, h] of [['icon-128x128.png', 128, 128], ['icon-256x256.png', 256, 256], ['banner-772x250.png', 772, 250], ['banner-1544x500.png', 1544, 500]]) {
	const full = path.join(assetsDir, file);
	const size = fs.existsSync(full) ? pngSize(full) : null;
	expect(size && size[0] === w && size[1] === h, `.wordpress-org/${file} is ${w}×${h}`);
}
const screenshotsInReadme = ((readme.split(/^== Screenshots ==$/m)[1] || '').split(/^== /m)[0].match(/^\d+\./gm) || []).length;
const screenshotFiles = fs.existsSync(assetsDir) ? fs.readdirSync(assetsDir).filter((f) => /^screenshot-\d+\.(png|jpg)$/.test(f)).length : 0;
expect(screenshotsInReadme === screenshotFiles, `${screenshotsInReadme} screenshot caption(s) / ${screenshotFiles} screenshot file(s)`);

// ---------------------------------------------------------------- plugin assets.
for (const file of fs.readdirSync(path.join(pluginDir, 'assets'))) {
	const content = fs.readFileSync(path.join(pluginDir, 'assets', file), 'utf8');
	const remote = content.match(/https?:\/\/[a-z0-9.-]+\.[a-z]{2,}/gi) || [];
	expect(remote.length === 0, `assets/${file} loads nothing from external hosts${remote.length ? ` (${remote.join(', ')})` : ''}`);
}

// --------------------------------------------------------------------- release.
if (tag) expect(tag.replace(/^v/, '') === header.Version, `Git tag ${tag} matches Version ${header.Version}`);

if (online) {
	try {
		const res = await fetch('https://api.wordpress.org/core/version-check/1.7/');
		const current = (await res.json()).offers[0].current.split('.').slice(0, 2).join('.');
		const diff = cmpVersion(meta['Tested up to'], current);
		expect(diff <= 0, `"Tested up to" ${meta['Tested up to']} is not newer than WordPress ${current}`);
		expect(diff >= 0, `"Tested up to" ${meta['Tested up to']} is the current WordPress ${current} – test and bump it`, warnings);
	} catch (err) {
		warnings.push(`could not fetch the current WordPress version (${err.message})`);
	}

	// Reviewers open every URI in the header and readme; dead links get the submission sent back.
	const urls = new Set([
		...['Plugin URI', 'Author URI', 'License URI', 'Update URI'].map((f) => header[f]).filter(Boolean),
		...(readme.match(/https?:\/\/[^\s)<>"'`]+/g) || []).filter((u) => !/example\.|\/\/(localhost|127\.)/.test(u)),
		meta['License URI'],
	].filter(Boolean));
	// A dead link (HTTP 4xx/5xx) fails the check. A network error is only a warning: some hosts (e.g. gnu.org)
	// throttle or drop requests from CI runners, which says nothing about the link itself.
	for (const url of urls) {
		let status = null;
		let lastError = '';
		for (let attempt = 1; attempt <= 3 && status === null; attempt++) {
			try {
				const res = await fetch(url, {
					redirect: 'follow',
					headers: { 'User-Agent': 'Mozilla/5.0 (repo-checks)' },
					signal: AbortSignal.timeout(15_000),
				});
				status = res.status;
			} catch (err) {
				lastError = err.cause?.code || err.message;
				await new Promise((r) => setTimeout(r, attempt * 2000));
			}
		}
		if (status === null) {
			warnings.push(`${url} could not be checked (${lastError}) – network issue, not counted as a dead link`);
		} else {
			expect(status >= 200 && status < 400, `${url} is reachable (HTTP ${status})`);
		}
	}
}

console.log(`${ok.length} checks passed.`);
for (const w of warnings) console.log(`⚠ ${w}`);
for (const e of errors) console.log(`✗ ${e}`);
process.exit(errors.length ? 1 : 0);
