#!/usr/bin/env node
/**
 * Translations: extracts the plugin's strings into languages/<slug>.pot (shipped) and builds i18n/<locale>.po from
 * i18n/<locale>/*.json (NOT shipped – import it on translate.wordpress.org, which delivers language packs).
 *
 *   npm run i18n               # rebuild the language files from i18n/<locale>/*.json (one file per module)
 *   npm run i18n -- --check    # CI: fail on missing/unused translations or outdated generated files
 *   npm run i18n -- --prune    # drop translations whose string no longer exists in the code
 *
 * Keys are the English source texts, values the translations. Plugin header fields live in header.json.
 * Plurals – _n( 'singular', 'plural', $n, … ): the singular key holds form 0; the plural key holds form 1 as a
 * string (nplurals=2), the same string as form 0 (nplurals=1) or an array [form 1, form 2, …] (nplurals>=3).
 *
 * The plugin bundles no translation files (wordpress.org review requirement); WordPress loads the language packs
 * from wp-content/languages/plugins just in time.
 */
import fs from 'node:fs';
import path from 'node:path';
import { PLUGIN_SLUG, pluginDir, root } from './lib/php.mjs';

const check = process.argv.includes('--check');
const prune = process.argv.includes('--prune');
const FUNCS = '(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)';
const STR = `(?:'((?:[^'\\\\]|\\\\.)*)'|"((?:[^"\\\\]|\\\\.)*)")`;
const re = new RegExp(`\\b${FUNCS}\\(\\s*${STR}\\s*,\\s*'${PLUGIN_SLUG}'\\s*\\)`, 'g');
// _n( 'singular', 'plural', <count>, 'slug' ): the count expression may contain parentheses, so it is skipped lazily.
const rePlural = new RegExp(`\\b_n\\(\\s*${STR}\\s*,\\s*${STR}\\s*,[^;]*?'${PLUGIN_SLUG}'\\s*\\)`, 'g');

function walk(dir, out = []) {
	for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
		const p = path.join(dir, e.name);
		if (e.isDirectory()) {
			if (e.name !== 'languages') walk(p, out);
		} else if (p.endsWith('.php')) {
			out.push(p);
		}
	}
	return out;
}

const literal = (single, double) => (single !== undefined
	? single.replace(/\\'/g, "'").replace(/\\\\/g, '\\')
	: double.replace(/\\"/g, '"').replace(/\\\\/g, '\\'));
const singles = new Set(); // texts used on their own (__, _e, esc_html__ …, header fields)
const plurals = new Map(); // _n(): singular => plural
for (const file of walk(pluginDir).sort()) {
	const code = fs.readFileSync(file, 'utf8');
	for (const m of code.matchAll(re)) singles.add(literal(m[1], m[2]));
	for (const m of code.matchAll(rePlural)) plurals.set(literal(m[1], m[2]), literal(m[3], m[4]));
}
// translate.wordpress.org also extracts these plugin header fields (shown in the Plugins list).
const mainFile = fs.readFileSync(path.join(pluginDir, `${PLUGIN_SLUG}.php`), 'utf8');
for (const field of ['Plugin Name', 'Plugin URI', 'Description', 'Author', 'Author URI']) {
	const m = mainFile.match(new RegExp(`^\\s*\\*\\s*${field}:\\s*(.+?)\\s*$`, 'm'));
	if (!m) {
		console.error(`Plugin header "${field}" not found in ${PLUGIN_SLUG}.php`);
		process.exit(1);
	}
	singles.add(m[1]);
}
const pluralKeys = new Set(plurals.values());
const strings = new Set([...singles, ...plurals.keys(), ...pluralKeys]); // every key the JSON files need
// One .po/.pot entry per text, plus one per _n() pair, sorted by msgid (singles first on a tie).
const entries = [
	...[...singles].map((key) => ({ key, plural: null })),
	...[...plurals].map(([key, plural]) => ({ key, plural })),
].sort((a, b) => (a.key === b.key ? (a.plural ? 1 : -1) : a.key < b.key ? -1 : 1));

// Placeholders and markup must survive translation unchanged (order may differ only with positional %1$s).
const tokenList = (s) => [...s.matchAll(/%(?:\d+\$)?[sdf]|%%|<\/?[a-z][^>]*>/gi)].map((m) => m[0]).sort();
const tokens = (s) => tokenList(s).join(' ');
// Plural forms other than the last may leave out a placeholder ("Eine E-Mail"), but never add one.
const subTokens = (form, src) => {
	const left = tokenList(src);
	return tokenList(form).every((t) => left.includes(t) && left.splice(left.indexOf(t), 1));
};
// Plural-Forms per locale (gettext header); everything else is nplurals=2; plural=(n != 1).
const PLURALS = {
	ja: 'nplurals=1; plural=0;', ko_KR: 'nplurals=1; plural=0;', zh_CN: 'nplurals=1; plural=0;', zh_TW: 'nplurals=1; plural=0;',
	id_ID: 'nplurals=1; plural=0;', vi: 'nplurals=1; plural=0;', th: 'nplurals=1; plural=0;', fa_IR: 'nplurals=1; plural=0;',
	tr_TR: 'nplurals=2; plural=(n > 1);', fr_FR: 'nplurals=2; plural=(n > 1);', pt_BR: 'nplurals=2; plural=(n > 1);', hi_IN: 'nplurals=2; plural=(n != 1);',
	ru_RU: 'nplurals=3; plural=(n % 10 == 1 && n % 100 != 11) ? 0 : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 1 : 2);',
	uk: 'nplurals=3; plural=(n % 10 == 1 && n % 100 != 11) ? 0 : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 1 : 2);',
	hr: 'nplurals=3; plural=(n % 10 == 1 && n % 100 != 11) ? 0 : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 1 : 2);',
	pl_PL: 'nplurals=3; plural=(n == 1) ? 0 : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 1 : 2);',
	cs_CZ: 'nplurals=3; plural=(n == 1) ? 0 : ((n >= 2 && n <= 4) ? 1 : 2);',
	sk_SK: 'nplurals=3; plural=(n == 1) ? 0 : ((n >= 2 && n <= 4) ? 1 : 2);',
	ro_RO: 'nplurals=3; plural=(n == 1) ? 0 : ((n == 0 || n % 100 >= 2 && n % 100 <= 19) ? 1 : 2);',
	ar: 'nplurals=6; plural=(n == 0) ? 0 : ((n == 1) ? 1 : ((n == 2) ? 2 : ((n % 100 >= 3 && n % 100 <= 10) ? 3 : ((n % 100 >= 11 && n % 100 <= 99) ? 4 : 5))));',
	he_IL: 'nplurals=2; plural=(n != 1);',
};

// German style guide (de.wordpress.org/team/handbook/polyglots-team/style-guide/) plus the de_DE team's request
// to say "KI" instead of "AI". The plugin name stays as it is (brand).
const germanStyle = (src, de) => {
	if (src === de) return [];
	const text = de.replace(/<[^>]+>/g, '');
	const out = [];
	if (/\bAI\b/.test(text)) out.push('"AI" instead of "KI"');
	if (/"/.test(text)) out.push('straight quotes instead of „…“');
	if (/ & /.test(text)) out.push('"&" instead of "und"');
	if (/ –/.test(text)) out.push('normal space before "–" (needs a protected space)');
	if (/\b(z|d|u)\. (B|h|a)\./.test(text)) out.push('abbreviation without protected space (z. B.)');
	if (/\d (%|kB|MB|GB|ms|km|kg|€)/.test(text)) out.push('normal space between number and unit');
	if (/\b(fürs|vorm|drauf|nochmal)\b/i.test(text)) out.push('colloquial word');
	return out;
};

const po = (s) => '"' + s.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n') + '"';
const msg = (e, forms) => (e.plural
	? `msgid ${po(e.key)}\nmsgid_plural ${po(e.plural)}\n${forms.map((f, i) => `msgstr[${i}] ${po(f)}`).join('\n')}\n`
	: `msgid ${po(e.key)}\nmsgstr ${po(forms[0])}\n`);

const problems = [];
const files = {};
const i18nDir = path.join(root, 'i18n');
const locales = fs.readdirSync(i18nDir, { withFileTypes: true }).filter((e) => e.isDirectory()).map((e) => e.name).sort();
for (const locale of locales) {
	const pluralForms = PLURALS[locale] ?? 'nplurals=2; plural=(n != 1);';
	const nplurals = Number(/nplurals=(\d+)/.exec(pluralForms)[1]);
	const say = (p) => problems.push(`${locale}: ${p}`);
	const map = {};
	const parts = {}; // module file => its translations (for --prune)
	for (const part of fs.readdirSync(path.join(i18nDir, locale)).filter((f) => f.endsWith('.json')).sort()) {
		parts[part] = JSON.parse(fs.readFileSync(path.join(i18nDir, locale, part), 'utf8'));
		for (const [key, value] of Object.entries(parts[part])) {
			if (key in map && JSON.stringify(map[key]) !== JSON.stringify(value)) say(`"${key}" translated differently in ${part}`);
			map[key] = value;
		}
	}

	const filled = (v) => typeof v === 'string' && v.trim() !== '';
	for (const s of [...strings].sort()) {
		if (!(s in map)) {
			say(`missing translation for "${s}"`);
			continue;
		}
		// Plural keys of nplurals>=3 locales hold an array; a text that is also used on its own cannot.
		const forms = pluralKeys.has(s) && nplurals >= 3 ? map[s] : [map[s]];
		if (!Array.isArray(forms) || (pluralKeys.has(s) && nplurals >= 3 && (singles.has(s) || plurals.has(s) || forms.length !== nplurals - 1))) {
			say(`"${s}" needs ${pluralKeys.has(s) && nplurals >= 3 ? `an array of ${nplurals - 1} plural forms` : 'a string'}`);
			continue;
		}
		if (!forms.every(filled)) {
			say(`empty translation for "${s}"`);
			continue;
		}
		const last = pluralKeys.has(s) && forms === map[s] ? forms.length - 1 : 0;
		forms.forEach((f, i) => {
			const ok = plurals.has(s) || (pluralKeys.has(s) && i < last) ? subTokens(f, s) : tokens(f) === tokens(s);
			if (!ok) say(`placeholders/markup differ for "${s}" → "${f}"`);
			else if (locale.startsWith('de_')) for (const p of germanStyle(s, f)) say(`${p} in "${f}"`);
		});
	}
	if (nplurals === 1) {
		for (const [s, p] of plurals) if (s in map && p in map && map[s] !== map[p]) say(`nplurals=1: "${p}" must repeat the translation of "${s}"`);
	}

	const unused = Object.keys(map).filter((s) => !strings.has(s));
	if (prune && unused.length) {
		for (const [part, data] of Object.entries(parts)) {
			if (!Object.keys(data).some((s) => !strings.has(s))) continue;
			for (const s of unused) delete data[s];
			fs.writeFileSync(path.join(i18nDir, locale, part), JSON.stringify(data, null, '\t') + '\n');
		}
		console.log(`${locale}: removed ${unused.length} unused translation(s).`);
	} else {
		for (const s of unused) say(`unused translation "${s}" (npm run i18n -- --prune)`);
	}

	const done = entries.filter((e) => e.key in map && (!e.plural || e.plural in map));
	files[path.join(i18nDir, `${locale}.po`)] = 'msgid ""\nmsgstr ""\n"Content-Type: text/plain; charset=UTF-8\\n"\n' +
		`"Language: ${locale}\\n"\n"Plural-Forms: ${pluralForms}\\n"\n"X-Domain: ${PLUGIN_SLUG}\\n"\n\n` +
		done.map((e) => msg(e, e.plural ? [map[e.key], ...[].concat(map[e.plural])].slice(0, nplurals) : [map[e.key]])).join('\n');
}

// Template for translators (and the wordpress.org import as a cross-check).
files[path.join(pluginDir, 'languages', `${PLUGIN_SLUG}.pot`)] = 'msgid ""\nmsgstr ""\n"Content-Type: text/plain; charset=UTF-8\\n"\n' +
	`"X-Domain: ${PLUGIN_SLUG}\\n"\n\n` + entries.map((e) => msg(e, e.plural ? ['', ''] : [''])).join('\n');

// Nothing but the .pot may ship in languages/ (old bundled .po/.l10n.php/.mo files must not linger in the release),
// and i18n/ holds no .po of a locale that no longer exists.
const languagesDir = path.join(pluginDir, 'languages');
const stale = [
	...(fs.existsSync(languagesDir) ? fs.readdirSync(languagesDir).map((f) => path.join(languagesDir, f)) : []),
	...fs.readdirSync(i18nDir).filter((f) => f.endsWith('.po')).map((f) => path.join(i18nDir, f)),
].filter((f) => !(f in files));

if (check) {
	for (const [file, content] of Object.entries(files)) {
		const current = fs.existsSync(file) ? fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n') : null;
		if (current !== content) problems.push(`${path.relative(root, file)} is outdated – run \`npm run i18n\``);
	}
	for (const file of stale) problems.push(`${path.relative(root, file)} is not generated by scripts/i18n.mjs – run \`npm run i18n\``);
	if (problems.length) {
		console.error(problems.join('\n'));
		process.exit(1);
	}
	console.log(`i18n: ${strings.size} strings, ${locales.length} locale(s) complete and up to date.`);
	process.exit(0);
}

if (problems.length) {
	console.error(problems.join('\n'));
	process.exit(1);
}
fs.mkdirSync(languagesDir, { recursive: true });
for (const file of stale) fs.rmSync(file);
for (const [file, content] of Object.entries(files)) fs.writeFileSync(file, content);
console.log(`${strings.size} strings written for ${locales.length} locale(s).`);
