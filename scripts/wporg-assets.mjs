#!/usr/bin/env node
/**
 * Generates the wordpress.org directory assets in .wordpress-org/ (deployed to SVN /assets, not into the plugin):
 *
 *   node scripts/wporg-assets.mjs              # icons, banners and screenshots
 *   node scripts/wporg-assets.mjs --no-shots   # only icons and banners
 *
 * Icons (animated GIF) and banners come from scripts/assets/wporg-brand.html. Screenshots are taken from the real admin screen in
 * WordPress Playground, seeded with example data (tests/e2e/seed.php). Uses the locally installed Edge.
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from '@playwright/test';
import gifenc from 'gifenc';
import { root } from './lib/php.mjs';

const { GIFEncoder, applyPalette, quantize } = gifenc;
const out = path.join(root, '.wordpress-org');
const shots = !process.argv.includes('--no-shots');
const browser = await chromium.launch(process.env.CI ? {} : { channel: 'msedge' });

// ------------------------------------------------------------------ icons + banners.
// The icon is an animated GIF (wordpress.org accepts icon-128x128.gif / icon-256x256.gif, max. 1 MB): every frame is
// rendered in the browser, read back as pixels and encoded with one shared palette, so colours do not flicker.
const brand = pathToFileURL(path.join(root, 'scripts', 'assets', 'wporg-brand.html')).href;
for (const size of [128, 256]) {
	const page = await browser.newPage({ viewport: { width: size, height: size } });
	await page.goto(`${brand}?asset=icon&frame=0`);
	const delays = await page.evaluate(() => window.iconFrames);
	const frames = [];
	for (let i = 0; i < delays.length; i++) {
		await page.goto(`${brand}?asset=icon&frame=${i}`);
		frames.push(dither(await pixels(page, size), size));
	}
	await page.close();

	const all = new Uint8Array(frames.length * size * size * 4);
	frames.forEach((f, i) => all.set(f, i * f.length));
	const palette = quantize(all, 256);
	const gif = GIFEncoder();
	frames.forEach((f, i) => gif.writeFrame(applyPalette(f, palette), size, size, { palette: i ? undefined : palette, delay: delays[i], repeat: 0 }));
	gif.finish();
	const file = `icon-${size}x${size}.gif`;
	fs.writeFileSync(path.join(out, file), gif.bytes());
	// A PNG next to the GIF would compete with it on wordpress.org.
	fs.rmSync(path.join(out, `icon-${size}x${size}.png`), { force: true });
	console.log(`✓ ${file} (${frames.length} frames, ${Math.round(gif.bytes().length / 1024)} KB)`);
}

for (const [file, width, height] of [
	['banner-772x250.png', 772, 250],
	['banner-1544x500.png', 1544, 500],
]) {
	const page = await browser.newPage({ viewport: { width, height } });
	await page.goto(`${brand}?asset=banner`);
	await page.screenshot({ path: path.join(out, file) });
	await page.close();
	console.log(`✓ ${file}`);
}

/**
 * Ordered (Bayer 4×4) dithering against banding of the background gradient in 256 colours. The pattern is
 * fixed per pixel position, so it does not flicker between frames.
 */
function dither(rgba, size) {
	const bayer = [0, 8, 2, 10, 12, 4, 14, 6, 3, 11, 1, 9, 15, 7, 13, 5];
	for (let y = 0; y < size; y++) {
		for (let x = 0; x < size; x++) {
			const offset = (bayer[(y % 4) * 4 + (x % 4)] / 16 - 0.5) * 6;
			const i = (y * size + x) * 4;
			for (let c = 0; c < 3; c++) rgba[i + c] = Math.max(0, Math.min(255, Math.round(rgba[i + c] + offset)));
		}
	}
	return rgba;
}

/** RGBA pixels of the rendered page (screenshot decoded by the browser itself). */
async function pixels(page, size) {
	const png = (await page.screenshot()).toString('base64');
	const base64 = await page.evaluate(
		async ({ png, size }) => {
			const img = new Image();
			img.src = `data:image/png;base64,${png}`;
			await img.decode();
			const canvas = new OffscreenCanvas(size, size);
			const ctx = canvas.getContext('2d');
			ctx.drawImage(img, 0, 0);
			const data = ctx.getImageData(0, 0, size, size).data;
			let bin = '';
			for (let i = 0; i < data.length; i += 0x8000) bin += String.fromCharCode.apply(null, data.subarray(i, i + 0x8000));
			return btoa(bin);
		},
		{ png, size }
	);
	return new Uint8Array(Buffer.from(base64, 'base64'));
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

		// Email types from the same history: a stopped renewal after a plugin update, merged welcome names.
		await page.setViewportSize({ width: 1280, height: 1000 });
		await page.goto(`${base}&tab=types`);
		await page.locator('.mst-row').first().waitFor();
		await hideNoise();
		await page.screenshot({ path: path.join(out, 'screenshot-7.png') });
		console.log('✓ screenshot-7.png (email types)');

		// Tall viewport instead of fullPage, so the admin menu background reaches the bottom.
		await page.setViewportSize({ width: 1280, height: 1100 });
		await page.goto(`${base}&tab=settings`);
		await hideNoise();
		await page.screenshot({ path: path.join(out, 'screenshot-6.png') });
		console.log('✓ screenshot-6.png (settings)');
	} finally {
		server.kill();
	}
}

await browser.close();
