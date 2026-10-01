#!/usr/bin/env node
/**
 * The PHPCS-based rules of the official Plugin Check plugin (what wordpress.org reviewers run), executed with
 * real PHP and Plugin Check's own bundled PHPCS + sniffs – pinned version, SHA-256 verified.
 *
 *   node scripts/plugin-check.mjs
 *
 * The non-PHPCS checks (readme, headers, file types, trademarks …) run inside WordPress via `npm run test:e2e`;
 * in CI the official wordpress/plugin-check-action additionally runs everything against the built plugin.
 * Accepted findings need a reason in tests/e2e/plugin-check-allowlist.json ({ "<code>": "why" }).
 */
import crypto from 'node:crypto';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { findPhp, PLUGIN_SLUG, pluginDir, root } from './lib/php.mjs';

const PCP_VERSION = '2.1.0';
const PCP_SHA256 = '6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4';

// Sniff selection per Plugin Check check class (includes/Checker/Checks/**), category plugin_repo + security.
const SNIFFS = [
	'WordPress.WP.I18n',
	'WordPress.Security.EscapeOutput',
	'WordPress.Security.SafeRedirect',
	'WordPress.DB.DirectDatabaseQuery',
	'WordPress.DB.SlowDBQuery',
	'WordPress.NamingConventions.PrefixAllGlobals',
	'WordPress.WP.EnqueuedResources',
	'WordPress.WP.EnqueuedResourceParameters',
	'WordPressVIPMinimum.Performance.WPQueryParams',
	'PluginCheck.CodeAnalysis.EnqueuedResourceOffloading',
	'PluginCheck.CodeAnalysis.Offloading',
	'PluginCheck.CodeAnalysis.Localhost',
	'PluginCheck.CodeAnalysis.PHPErrorReporting',
	'PluginCheck.CodeAnalysis.SettingSanitization',
	'PluginCheck.CodeAnalysis.WriteFile',
	'PluginCheck.Security.DirectDB',
	'PluginCheck.Security.VerifyNonce',
];

async function ensurePcp() {
	const dir = path.join(root, '.cache', `plugin-check-${PCP_VERSION}`);
	if (fs.existsSync(path.join(dir, 'plugin-check', 'vendor', 'bin', 'phpcs'))) return path.join(dir, 'plugin-check');
	const res = await fetch(`https://downloads.wordpress.org/plugin/plugin-check.${PCP_VERSION}.zip`);
	if (!res.ok) throw new Error(`Plugin Check download failed: HTTP ${res.status}`);
	const buf = Buffer.from(await res.arrayBuffer());
	if (crypto.createHash('sha256').update(buf).digest('hex') !== PCP_SHA256) throw new Error('Plugin Check: checksum mismatch – download discarded.');
	fs.mkdirSync(dir, { recursive: true });
	fs.writeFileSync(path.join(dir, 'pcp.zip'), buf);
	// Windows' bsdtar handles zip; on Linux/macOS use unzip.
	const unpack = process.platform === 'win32'
		? spawnSync(path.join(process.env.SystemRoot || 'C:\\Windows', 'System32', 'tar.exe'), ['-xf', 'pcp.zip'], { cwd: dir, stdio: 'inherit' })
		: spawnSync('unzip', ['-q', 'pcp.zip'], { cwd: dir, stdio: 'inherit' });
	fs.rmSync(path.join(dir, 'pcp.zip'));
	if (unpack.status !== 0) throw new Error('Unpacking Plugin Check failed.');
	return path.join(dir, 'plugin-check');
}

const php = findPhp();
if (!php) {
	console.error('No PHP found – run `npm run setup:php` (Windows) or install PHP.');
	process.exit(2);
}

const pcp = await ensurePcp();
const vendor = path.join(pcp, 'vendor');
const installed = [
	'wp-coding-standards/wpcs',
	'phpcsstandards/phpcsutils',
	'phpcsstandards/phpcsextra',
	'automattic/vipwpcs',
	'sirbrillig/phpcs-variable-analysis',
	'plugin-check/phpcs-sniffs',
].map((p) => path.join(vendor, p)).join(',');

function phpcs(extra) {
	const res = spawnSync(php, [
		path.join(vendor, 'bin', 'phpcs'), '-q', '--report=json', '--no-cache', `--runtime-set`, 'installed_paths', installed,
		'--runtime-set', 'text_domain', PLUGIN_SLUG, '--runtime-set', 'minimum_wp_version', '6.5',
		'--ignore=*/languages/*', ...extra, pluginDir,
	], { cwd: root, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
	if (res.status > 2) throw new Error(`PHPCS crashed (exit ${res.status}): ${res.stderr || res.stdout}`);
	return JSON.parse(res.stdout).files;
}

const reports = [
	phpcs(['--extensions=php,js,css', `--standard=${path.join(pcp, 'phpcs-rulesets', 'plugin-review.xml')}`]),
	phpcs(['--extensions=php', '--standard=WordPress,WordPressVIPMinimum,PluginCheck', `--sniffs=${SNIFFS.join(',')}`]),
];

const allow = JSON.parse(fs.readFileSync(path.join(root, 'tests', 'e2e', 'plugin-check-allowlist.json'), 'utf8'));
let open = 0;
let total = 0;
for (const files of reports) {
	for (const [file, { messages }] of Object.entries(files)) {
		for (const m of messages) {
			total++;
			const allowed = m.source in allow;
			if (!allowed) open++;
			console.log(`${allowed ? '~' : '✗'} ${m.type} ${path.relative(root, file)}:${m.line} ${m.source} – ${m.message}`);
		}
	}
}
console.log(`Plugin Check ${PCP_VERSION} (PHPCS rules): ${total} finding(s), ${open} not allow-listed.`);
process.exit(open ? 1 : 0);
