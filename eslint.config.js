// ESLint flat config: correctness + DOM-XSS rules for the admin script, Node rules for the tooling.
import js from '@eslint/js';
import nounsanitized from 'eslint-plugin-no-unsanitized';
import globals from 'globals';

export default [
	{
		ignores: ['node_modules/**', 'vendor/**', '.cache/**', 'dist/**', 'test-results/**', 'playwright-report/**'],
	},
	js.configs.recommended,
	{
		files: ['mailspur-email-log/assets/**/*.js'],
		languageOptions: {
			ecmaVersion: 2020,
			sourceType: 'script',
			globals: { ...globals.browser },
		},
		plugins: { 'no-unsanitized': nounsanitized },
		rules: {
			// innerHTML/outerHTML/insertAdjacentHTML/document.write with non-literal input.
			'no-unsanitized/method': 'error',
			'no-unsanitized/property': 'error',
			'no-eval': 'error',
			'no-implied-eval': 'error',
			'no-new-func': 'error',
			'no-script-url': 'error',
			eqeqeq: ['error', 'always'],
			'no-var': 'error',
			'prefer-const': 'error',
			'no-unused-vars': ['error', { args: 'after-used', caughtErrors: 'none' }],
			// The sandboxed preview is built as srcdoc; it must never get allow-scripts/allow-same-origin.
			'no-restricted-syntax': [
				'error',
				{
					selector: "Literal[value=/allow-(scripts|same-origin|top-navigation|forms)/]",
					message: 'The mail preview iframe must not get script, same-origin, form or top-navigation permissions.',
				},
			],
		},
	},
	{
		files: ['**/*.mjs', 'eslint.config.js', 'tests/e2e/**/*.js', 'playwright.config.js'],
		languageOptions: {
			ecmaVersion: 2024,
			sourceType: 'module',
			globals: { ...globals.node },
		},
		rules: {
			'no-unused-vars': ['error', { caughtErrors: 'none' }],
		},
	},
	{
		// Callbacks passed to page.evaluate() run in the browser.
		files: ['tests/e2e/**/*.js', 'scripts/wporg-assets.mjs'],
		languageOptions: {
			globals: { ...globals.node, ...globals.browser },
		},
	},
];
