#!/usr/bin/env node
/**
 * Live Preview on wordpress.org: builds .wordpress-org/blueprints/blueprint.json (deployed to SVN assets/blueprints/).
 *
 *   node scripts/wporg-blueprint.mjs           # write the blueprint
 *   node scripts/wporg-blueprint.mjs --check   # CI: fail if it is outdated
 *
 * The preview installs the released plugin from wordpress.org and runs the same seed as the browser tests and the
 * directory screenshots (tests/e2e/seed.php + the 60-day history of tests/e2e/screenshot-seed.php), so visitors land
 * on a filled Overview instead of "No emails yet". The seed is inlined because Playground cannot read files from this
 * repo; the test-only parts (hostile mails, XSS subject, legacy import table, e2e hooks) are cut out.
 */
import fs from 'node:fs';
import path from 'node:path';
import { PLUGIN_SLUG, root } from './lib/php.mjs';

const check = process.argv.includes('--check');
const read = (file) => fs.readFileSync(path.join(root, 'tests', 'e2e', file), 'utf8').replace(/\r\n/g, '\n');
const outFile = path.join(root, '.wordpress-org', 'blueprints', 'blueprint.json');

/** Replaces exactly one match, so a changed seed fails loudly instead of leaking test data into the preview. */
function cut(text, pattern, replacement, file) {
	const matches = text.match(new RegExp(pattern.source, pattern.flags + 'g'))?.length ?? 0;
	if (matches !== 1) {
		console.error(`tests/e2e/${file}: expected one match of ${pattern} (found ${matches}) – update scripts/wporg-blueprint.mjs`);
		process.exit(1);
	}
	return text.replace(pattern, replacement);
}

let seed = read('seed.php');
seed = cut(seed, /^\/\/ Every 10th mail is hostile[\s\S]*?<\/body><\/html>';\n\n/m, '', 'seed.php');
seed = cut(seed, /0 === \$i % 10 \? \$attack : /, '', 'seed.php');
seed = cut(seed, /^wp_mail\( 'x@example\.com'.*\n/m, '', 'seed.php');
seed = cut(seed, /^\/\/ An old WP Mail Logging table[\s\S]*?\n}\n\n/m, '', 'seed.php');
seed = cut(seed, /^\/\/ Module seed data[\s\S]*$/m, '', 'seed.php');

// The 60 days of shop traffic behind the statistics and email types, run directly instead of on ?mailspur-e2e-seed.
const history = cut(read('screenshot-seed.php'), /^<\?php\n/, '', 'screenshot-seed.php');

const blueprint = {
	$schema: 'https://playground.wordpress.net/blueprint-schema.json',
	landingPage: `/wp-admin/admin.php?page=${PLUGIN_SLUG}&tab=overview`,
	preferredVersions: { php: '8.3', wp: 'latest' },
	login: true,
	steps: [
		{ step: 'setSiteOptions', options: { blogname: 'Example Shop', permalink_structure: '/%postname%/' } },
		{ step: 'installPlugin', pluginData: { resource: 'wordpress.org/plugins', slug: PLUGIN_SLUG }, options: { activate: true } },
		{ step: 'runPHP', code: `${seed.trimEnd()}\n\n${history.trimEnd()}\n\nmailspur_e2e_seed_history();\n` },
	],
};
const content = JSON.stringify(blueprint, null, '\t') + '\n';

if (check) {
	const current = fs.existsSync(outFile) ? fs.readFileSync(outFile, 'utf8').replace(/\r\n/g, '\n') : null;
	if (current !== content) {
		console.error('.wordpress-org/blueprints/blueprint.json is outdated – run `node scripts/wporg-blueprint.mjs`');
		process.exit(1);
	}
	console.log('Live Preview blueprint up to date.');
	process.exit(0);
}
fs.mkdirSync(path.dirname(outFile), { recursive: true });
fs.writeFileSync(outFile, content);
console.log(`Wrote ${path.relative(root, outFile)}.`);
