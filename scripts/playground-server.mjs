#!/usr/bin/env node
/**
 * Playground server with the plugin mounted and the log seeded (tests/e2e/ui.blueprint.json).
 * Used by playwright.config.js and for manual testing / screenshots:
 *
 *   node scripts/playground-server.mjs [--port 9400] [--php 8.3] [--wp latest]
 */
import fs from 'node:fs';
import path from 'node:path';
import { root } from './lib/php.mjs';
import { mountArgs, playground } from './lib/playground.mjs';

const args = process.argv.slice(2);
const opt = (name, fallback) => (args.includes(`--${name}`) ? args[args.indexOf(`--${name}`) + 1] : fallback);

// The CLI's --php/--wp flags are not applied reliably; preferredVersions in the blueprint is.
const blueprint = JSON.parse(fs.readFileSync(path.join(root, 'tests', 'e2e', 'ui.blueprint.json'), 'utf8'));
blueprint.preferredVersions = { php: opt('php', process.env.E2E_PHP || '8.3'), wp: opt('wp', process.env.E2E_WP || 'latest') };
fs.mkdirSync(path.join(root, '.cache'), { recursive: true });
const blueprintFile = path.join(root, '.cache', 'e2e-ui.blueprint.json');
fs.writeFileSync(blueprintFile, JSON.stringify(blueprint));

// seed.php drops a marker here once the blueprint is done (tests/e2e/wait-for-wordpress.js waits for it).
const out = path.join(root, '.cache', 'e2e-out');
fs.mkdirSync(out, { recursive: true });
fs.rmSync(path.join(out, 'seeded'), { force: true });

const { code } = await playground([
	'server',
	`--port=${opt('port', process.env.E2E_PORT || '9400')}`,
	'--login',
	`--blueprint=${blueprintFile}`,
	...mountArgs({ '/e2e': path.join(root, 'tests', 'e2e'), '/e2e-out': out }),
]);
process.exit(code);
