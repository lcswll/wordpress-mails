// Preview looks: desktop / phone / plain text × light / dark / forced dark. Every look keeps the sandbox, the CSP
// and the blocked remote content. Own rows ("[PV]"), seeded lazily via tests/e2e/views-seed.php and deleted at the end.
import { expect, test } from '@playwright/test';

const LOG = '/wp-admin/admin.php?page=mailspur-email-log';
const SEARCH = `${LOG}&s=%5BPV%5D`;
const rows = (page) => page.locator('#mailspur-rows tr[data-id]');
const BLOCKED = /violates the following Content Security Policy directive|Blocked script execution in 'about:srcdoc'/;
const MAIL_HOSTS = /(^|\.)(example\.com|shop\.example|evil\.example)$/;

function watch(page) {
	const errors = [];
	const external = [];
	page.on('console', (msg) => {
		if (msg.type() === 'error' && !BLOCKED.test(msg.text())) errors.push(msg.text());
	});
	page.on('pageerror', (err) => errors.push(err.message));
	page.on('request', (req) => {
		if (MAIL_HOSTS.test(new URL(req.url()).hostname)) external.push(req.url());
	});
	page.on('dialog', (dialog) => dialog.accept());
	return { errors, external };
}

const look = (page, name) => page.locator('.mailspur-looks').getByRole('button', { name, exact: true });

/** The iframe keeps every protection, whatever the look. */
async function expectIsolated(page) {
	const frame = page.locator('iframe.mailspur-frame');
	await expect(frame).toHaveAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox');
	const srcdoc = await frame.getAttribute('srcdoc');
	expect(srcdoc).toContain("default-src 'none'");
	expect(srcdoc).toContain("form-action 'none'");
	expect(srcdoc).toContain('data-blocked-src="https://example.com/pv-pixel.gif"');
	expect(await frame.evaluate((f) => f.contentDocument)).toBeNull();
	expect(await page.evaluate(() => window.pwned)).toBeUndefined();
	return srcdoc;
}

/** Opens a seeded mail with remote content blocked (ui.spec.js may have switched "always load" on). */
async function open(page, subject) {
	await page.goto(SEARCH);
	await rows(page).filter({ hasText: subject }).locator('.mailspur-open').click();
	await expect(page.locator('#mailspur-d-subject')).toHaveText(subject);
	const toggle = page.locator('#mailspur-d-remote-toggle');
	if ('Block again' === (await toggle.textContent())) await toggle.click();
	await expect(toggle).toHaveText('Load remote content');
}

test.describe.configure({ mode: 'serial' });

test('switch between desktop, phone, dark, forced dark and plain text', async ({ page }) => {
	const seen = watch(page);
	await page.goto('/wp-admin/?mailspur-e2e-seed=views');
	await expect(rows(page)).toHaveCount(2);
	await open(page, '[PV] Dark-ready newsletter');
	const inner = page.frameLocator('iframe.mailspur-frame');

	// Desktop + light is the default and renders like before.
	await expect(look(page, 'Desktop')).toHaveAttribute('aria-pressed', 'true');
	await expect(look(page, 'Light')).toHaveAttribute('aria-pressed', 'true');
	await expect(inner.locator('h1')).toHaveText('Dark-ready newsletter');
	await expect(inner.locator('body')).toHaveCSS('background-color', 'rgb(255, 255, 255)');
	await expect(page.locator('#mailspur-d-remote')).toBeVisible();
	await expectIsolated(page);

	// Phone: 375 px wide, centred; the 600 px table overflows inside the frame only.
	await look(page, 'Phone').click();
	await expect(look(page, 'Phone')).toHaveAttribute('aria-pressed', 'true');
	const box = await page.locator('iframe.mailspur-frame').boundingBox();
	expect(box.width).toBeGreaterThan(370);
	expect(box.width).toBeLessThanOrEqual(377);
	await expect(page.locator('.mailspur-look-hint')).toContainText('375 px');
	await expect(inner.locator('h1')).toHaveText('Dark-ready newsletter');
	await expectIsolated(page);

	// Dark: the email's own prefers-color-scheme: dark rules apply, the light-only ones do not.
	await look(page, 'Dark').click();
	await expect(inner.locator('body')).toHaveCSS('background-color', 'rgb(16, 16, 16)');
	await expect(inner.locator('h1')).not.toHaveCSS('color', 'rgb(179, 45, 46)');
	await expect(page.locator('.mailspur-look-hint')).toContainText('dark-mode styles of the email itself');
	let srcdoc = await expectIsolated(page);
	expect(srcdoc).toContain('html{color-scheme:dark}');
	expect(srcdoc).not.toContain('prefers-color-scheme');

	// Forced dark: inverted document, images inverted back.
	await look(page, 'Forced dark').click();
	await expect(inner.locator('html')).toHaveCSS('filter', /invert\(1\)/);
	await expect(inner.locator('img[alt="Logo"]')).toHaveCSS('filter', /invert\(1\)/);
	srcdoc = await expectIsolated(page);
	expect(srcdoc).toContain('prefers-color-scheme: dark'); // Left untouched in this look.

	// Plain text: derived from the HTML, no iframe at all, nothing remote to load.
	await look(page, 'Plain text').click();
	await expect(page.locator('iframe.mailspur-frame')).toHaveCount(0);
	await expect(page.locator('[data-group="scheme"]')).toBeHidden();
	await expect(page.locator('#mailspur-d-remote')).toBeHidden();
	const text = page.locator('.mailspur-stage pre');
	await expect(text).toContainText('Dark-ready newsletter');
	await expect(text).toContainText('the blog (https://shop.example/blog)');
	await expect(text).toContainText('- First');
	await expect(text).toContainText('[Logo]');
	await expect(text).not.toContainText('Hidden preheader');
	await expect(text).not.toContainText('window.top');
	await expect(page.locator('.mailspur-look-hint')).toContainText('has its own plain-text version');

	// Other tabs are unaffected.
	await page.getByRole('tab', { name: 'Source' }).click();
	await expect(page.locator('.mailspur-looks')).toHaveCount(0);
	await expect(page.locator('#mailspur-d-body pre')).toContainText('<table width="600"');

	expect(seen.errors).toEqual([]);
	expect(seen.external).toEqual([]);
});

test('the choice is remembered and the switcher works with the keyboard', async ({ page }) => {
	const seen = watch(page);
	await open(page, '[PV] Light only');
	await look(page, 'Plain text').click();
	await expect(look(page, 'Plain text')).toHaveAttribute('aria-pressed', 'true');
	await expect(page.locator('.mailspur-look-hint')).toContainText('has no plain-text version');
	await expect(page.locator('.mailspur-stage pre')).toContainText('No dark styles here.');

	await look(page, 'Phone').focus();
	await page.keyboard.press('Enter');
	await expect(look(page, 'Phone')).toHaveAttribute('aria-pressed', 'true');
	await expect(look(page, 'Phone')).toBeFocused();
	await page.keyboard.press('Tab');
	await page.keyboard.press('Tab');
	await expect(look(page, 'Light')).toBeFocused();
	await page.keyboard.press('Tab');
	await expect(look(page, 'Dark')).toBeFocused();
	await page.keyboard.press('Space');
	await expect(look(page, 'Dark')).toHaveAttribute('aria-pressed', 'true');

	// No dark styles of its own: shown unchanged, with an honest hint.
	await expect(page.locator('.mailspur-look-hint')).toContainText('no dark-mode styles of its own');
	const srcdoc = await expectIsolated(page);
	expect(srcdoc).toContain('html{color-scheme:light}');

	// Loading remote content still works per mail and keeps the look.
	await page.locator('#mailspur-d-remote-toggle').click();
	expect(await page.locator('iframe.mailspur-frame').getAttribute('srcdoc')).toMatch(/img-src data: cid: https: http:/);
	await expect(look(page, 'Phone')).toHaveAttribute('aria-pressed', 'true');

	await page.reload();
	await rows(page).filter({ hasText: '[PV] Light only' }).locator('.mailspur-open').click();
	await expect(look(page, 'Phone')).toHaveAttribute('aria-pressed', 'true');
	await expect(look(page, 'Dark')).toHaveAttribute('aria-pressed', 'true');
	expect(seen.errors).toEqual([]);
});

test('phone look fits a phone-sized admin screen', async ({ page }) => {
	await page.setViewportSize({ width: 375, height: 812 });
	await open(page, '[PV] Dark-ready newsletter');
	await look(page, 'Phone').click();
	await expect(page.locator('iframe.mailspur-frame')).toBeVisible();
	const overflow = await page.evaluate(() => {
		const d = document.querySelector('#mailspur-dialog');
		return d.scrollWidth - d.clientWidth;
	});
	expect(overflow).toBeLessThanOrEqual(0);
	await page.keyboard.press('Escape');
});

// Leaves the log as the other specs expect it, even when a test above failed.
test.afterAll(async ({ browser }, testInfo) => {
	const page = await browser.newPage({ baseURL: testInfo.project.use.baseURL });
	page.on('dialog', (dialog) => dialog.accept());
	await page.goto(SEARCH);
	await expect(page.locator('#mailspur-app')).toHaveAttribute('aria-busy', 'false');
	if ((await rows(page).count()) > 0) {
		await page.locator('#mailspur-select-all').check();
		await page.locator('#mailspur-bulk-delete').click();
	}
	await expect(page.locator('.mailspur-empty')).toBeVisible();
	await page.close();
});
