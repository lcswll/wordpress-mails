#!/usr/bin/env node
/**
 * Runs a PHP tool with whatever PHP is available (see scripts/lib/php.mjs), e.g.
 *
 *   node scripts/php.mjs vendor/bin/phpcs
 *   node scripts/php.mjs --composer audit --locked
 *   npm run phpstan
 *
 * Installs the Composer dev dependencies on first use.
 */
import fs from 'node:fs';
import path from 'node:path';
import { findComposer, findPhp, root, run } from './lib/php.mjs';

const php = findPhp();
if (!php) {
	console.error('No PHP found. Windows: `npm run setup:php` (portable, nothing installed system-wide). Otherwise install PHP ≥ 7.4.');
	process.exit(2);
}

const composer = findComposer(php);
const args = process.argv.slice(2);
const shell = process.platform === 'win32' && composer?.[0] === 'composer'; // composer.bat needs a shell.

if (args[0] === '--composer') {
	if (!composer) {
		console.error('Composer not found – `npm run setup:php` also downloads it.');
		process.exit(2);
	}
	process.exit(run(composer[0], [...composer.slice(1), ...args.slice(1)], { shell }));
}

if (!fs.existsSync(path.join(root, 'vendor', 'autoload.php'))) {
	if (!composer) {
		console.error('Composer not found – `npm run setup:php` also downloads it.');
		process.exit(2);
	}
	const code = run(composer[0], [...composer.slice(1), 'install', '--no-interaction', '--no-progress'], { shell });
	if (code !== 0) process.exit(code);
}

process.exit(run(php, args));
