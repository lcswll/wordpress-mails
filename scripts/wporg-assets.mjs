#!/usr/bin/env node
/**
 * Generates the wordpress.org directory assets in .wordpress-org/ (deployed to SVN /assets, not into the plugin):
 *
 *   node scripts/wporg-assets.mjs              # icons, banners and screenshots
 *   node scripts/wporg-assets.mjs --no-shots   # only icons and banners
 *
 * Icons/banners come from scripts/assets/wporg-brand.html. Screenshots are taken from the real admin screen in
 * WordPress Playground, seeded with example data (tests/e2e/seed.php). Uses the locally installed Edge.
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from '@playwright/test';
import { root } from './lib/php.mjs';

const out = path.join(root, '.wordpress-org');
const shots = !process.argv.includes('--no-shots');
const browser = await chromium.launch(process.env.CI ? {} : { channel: 'msedge' });

// ------------------------------------------------------------------ icons + banners.
const brand = pathToFileURL(path.join(root, 'scripts', 'assets', 'wporg-brand.html')).href;
for (const [file, asset, width, height] of [
	['icon-128x128.png', 'icon', 128, 128],
	['icon-256x256.png', 'icon', 256, 256],
	['banner-772x250.png', 'banner', 772, 250],
	['banner-1544x500.png', 'banner', 1544, 500],
]) {
	const page = await browser.newPage({ viewport: { width, height } });
	await page.goto(`${brand}?asset=${asset}`);
	await page.screenshot({ path: path.join(out, file) });
	await page.close();
	console.log(`✓ ${file}`);
}

// --------------------------------------------------------------------- screenshots.
if (shots) {
	const port = 9420;
	const marker = path.join(root, '.cache', 'e2e-out', 'seeded');
	fs.rmSync(marker, { force: true });
	const server = spawn(process.execPath, [path.join(root, 'scripts', 'playground-server.mjs'), '--port', String(port)], { cwd: root, stdio: 'ignore' });
	try {
		const deadline = Date.now() + 5 * 60_000;
		while (!fs.existsSync(marker)) {
			if (Date.now() > deadline) throw new Error('Playground did not start.');
			await new Promise((r) => setTimeout(r, 500));
		}

		const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 });
		const base = `http://127.0.0.1:${port}/wp-admin/admin.php?page=mailspur-email-log`;
		const hideNoise = () => page.addStyleTag({ content: '#wpfooter, .notice, .update-nag { display: none !important; }' });

		// The XSS test entry is for the tests, not for the directory page.
		await page.goto(base);
		await page.evaluate(async () => {
			const c = window.mailspurConfig;
			const headers = { 'X-WP-Nonce': c.nonce };
			const list = await (await fetch(`${c.restUrl}/mails?search=XSS`, { headers })).json();
			await Promise.all(list.items.map((i) => fetch(`${c.restUrl}/mails/${i.id}`, { method: 'DELETE', headers })));
		});
		await page.goto(base);
		await page.locator('#mailspur-rows tr[data-id]').first().waitFor();
		await hideNoise();
		await page.screenshot({ path: path.join(out, 'screenshot-1.png') });
		console.log('✓ screenshot-1.png (log)');

		await page.goto(`${base}&s=lena1%40`);
		await page.locator('#mailspur-rows .mailspur-open').first().click();
		await page.frameLocator('iframe.mailspur-frame').locator('h1').waitFor();
		await page.evaluate(() => document.activeElement?.blur());
		await hideNoise();
		await page.screenshot({ path: path.join(out, 'screenshot-2.png') });
		console.log('✓ screenshot-2.png (preview)');

		// Trace of a mail sent by a real web request: the "Lost your password?" form of wp-login.php.
		await page.goto(`http://127.0.0.1:${port}/wp-login.php?action=lostpassword`);
		await page.locator('#user_login').fill('admin');
		await page.locator('#wp-submit').click();
		await page.waitForLoadState('load');
		await page.goto(`${base}&s=Password%20Reset`);
		await page.locator('#mailspur-rows .mailspur-open').first().click();
		await page.locator('#mailspur-dialog [role="tab"][data-view="trace"]').click();
		await page.locator('#mailspur-d-body').getByText(/ms/).first().waitFor();
		await page.evaluate(() => document.activeElement?.blur());
		await page.screenshot({ path: path.join(out, 'screenshot-3.png') });
		console.log('✓ screenshot-3.png (trace)');

		// The failed newsletter: explained error + notes.
		await page.goto(`${base}&s=newsletter`);
		await page.locator('#mailspur-rows .mailspur-open').first().click();
		await page.locator('#mailspur-dialog [role="tab"][data-view="notes"]').click();
		await page.waitForTimeout(500);
		await page.evaluate(() => document.activeElement?.blur());
		await page.screenshot({ path: path.join(out, 'screenshot-4.png') });
		console.log('✓ screenshot-4.png (notes)');

		// 30 days of plausible shop traffic (tests/e2e/screenshot-seed.php), only for this screenshot.
		await page.goto(`http://127.0.0.1:${port}/wp-admin/?mailspur-e2e-seed=history`);
		await page.setViewportSize({ width: 1280, height: 1100 });
		await page.goto(`${base}&tab=stats`);
		await page.locator('.mailspur svg').first().waitFor();
		await page.waitForTimeout(500);
		await hideNoise();
		await page.screenshot({ path: path.join(out, 'screenshot-5.png') });
		console.log('✓ screenshot-5.png (statistics)');

		// Tall viewport instead of fullPage, so the admin menu background reaches the bottom.
		await page.goto(`${base}&tab=settings`);
		await hideNoise();
		await page.screenshot({ path: path.join(out, 'screenshot-6.png') });
		console.log('✓ screenshot-6.png (settings)');
	} finally {
		server.kill();
	}
}

await browser.close();
