// Browser tests for the admin screen against WordPress Playground (seeded via tests/e2e/ui.blueprint.json).
// Started by scripts/e2e.mjs; PHP/WP versions come from E2E_PHP / E2E_WP.
import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env.E2E_PORT || 9411);

export default defineConfig({
	testDir: 'tests/e2e',
	testMatch: '*.spec.js',
	outputDir: '.cache/playwright',
	globalSetup: './tests/e2e/wait-for-wordpress.js',
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	timeout: 60_000,
	reporter: process.env.CI ? [['list'], ['html', { open: 'never', outputFolder: '.cache/playwright-report' }]] : 'list',
	use: {
		baseURL: `http://127.0.0.1:${port}`,
		// Locally use the installed Edge (no browser download); CI installs Playwright's Chromium.
		...(process.env.CI ? devices['Desktop Chrome'] : { ...devices['Desktop Edge'], channel: 'msedge' }),
		viewport: { width: 1440, height: 900 },
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	webServer: {
		command: `node scripts/playground-server.mjs --port ${port}`,
		// Readiness by TCP port: the Playground server does not answer the HEAD probe used for `url`.
		port,
		timeout: 300_000,
		reuseExistingServer: false,
		stdout: 'pipe',
		stderr: 'pipe',
	},
});
