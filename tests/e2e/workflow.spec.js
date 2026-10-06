// Workflow module in a real browser: "More filters" (source, format, attachments, notes, provider status) incl. URL state,
// CSV/JSON export of the current filter, anonymised entries and "Copy for support". Own rows ("[WF]"), seeded lazily via
// tests/e2e/workflow-seed.php so the other specs' counts are untouched.
import fs from 'node:fs';
import { expect, test } from '@playwright/test';

const LOG = '/wp-admin/admin.php?page=mailspur-email-log';
const WF = `${LOG}&s=%5BWF%5D`;
const rows = (page) => page.locator('#mailspur-rows tr[data-id]');

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage();
	await page.goto('/wp-admin/?mailspur-e2e-seed=workflow');
	await expect(page).toHaveURL(/page=mailspur-email-log/);
	await page.close();
});

/** Fails the test on console errors. */
function watch(page) {
	const errors = [];
	page.on('console', (msg) => {
		// The preview's CSP/sandbox reports blocked content – that is the protection working, not an error.
		if (msg.type() === 'error' && !/Content Security Policy|about:srcdoc/.test(msg.text())) errors.push(msg.text());
	});
	page.on('pageerror', (err) => errors.push(err.message));
	return errors;
}

test('more filters: source, format, attachments, notes – in the URL and combined with status', async ({ page }) => {
	const errors = watch(page);
	await page.goto(WF);
	await expect(rows(page)).toHaveCount(5);

	const toggle = page.locator('#mailspur-more-toggle');
	await expect(page.locator('#mailspur-more')).toBeHidden();
	await toggle.click();
	await expect(page.locator('#mailspur-more')).toBeVisible();
	await expect(toggle).toHaveAttribute('aria-expanded', 'true');

	// Options come from GET /sources with readable labels and counts.
	const source = page.locator('#mailspur-source');
	await expect(source.locator('option[value="plugin:mailspur-email-log"]')).toHaveText(/^Mailspur – Email Log \(2\)$/);
	await expect(source.locator('option[value^="theme:"]')).toHaveText(/\(theme\) \(\d+\)$/);

	await source.selectOption('plugin:mailspur-email-log');
	await expect(rows(page)).toHaveCount(2);
	await expect(page).toHaveURL(/[?&]source=plugin%3Amailspur-email-log/);
	await expect(page.locator('[data-count="failed"]')).toHaveText('1');
	await expect(page.locator('#mailspur-more-count')).toHaveText('1');

	await page.locator('#mailspur-format').selectOption('html');
	await expect(rows(page)).toHaveCount(1);
	await expect(rows(page).first()).toContainText('[WF] Invoice with attachment');
	await expect(page).toHaveURL(/[?&]format=html/);

	// Survives a reload: panel opens by itself, nothing is filtered invisibly.
	await page.reload();
	await expect(page.locator('#mailspur-more')).toBeVisible();
	await expect(rows(page)).toHaveCount(1);
	await expect(page.locator('#mailspur-source')).toHaveValue('plugin:mailspur-email-log');
	await expect(page.locator('#mailspur-more-count')).toHaveText('2');

	await page.locator('#mailspur-format').selectOption('');
	await page.locator('#mailspur-notes').check();
	await expect(rows(page)).toHaveCount(1);
	await expect(rows(page).first()).toContainText('[WF] Failed with notes');
	await expect(page).toHaveURL(/[?&]notes=1/);

	await page.locator('#mailspur-notes').uncheck();
	await page.locator('#mailspur-attachments').check();
	await expect(rows(page)).toHaveCount(1);
	await expect(page).toHaveURL(/[?&]att=1/);

	// Status chips combine with the extra filters; paging resets.
	await page.locator('#mailspur-attachments').uncheck();
	await page.locator('[data-status="failed"]').click();
	await expect(rows(page)).toHaveCount(1);

	await page.locator('#mailspur-reset').click();
	await expect(page.locator('#mailspur-source')).toHaveValue('');
	await expect(page.locator('#mailspur-more-count')).toHaveText('');
	await expect(page).not.toHaveURL(/source=|format=|att=|notes=/);
	expect(errors).toEqual([]);
});

test('provider status filter: from the URL, in "More filters" and in the export', async ({ page }) => {
	const errors = watch(page);
	const WFD = `${LOG}&s=%5BWFD%5D`;
	await page.goto(`${WFD}&delivery=bounced`);
	await expect(rows(page)).toHaveCount(1);
	await expect(rows(page).first()).toContainText('[WFD] Invoice bounced');
	// Opened by itself, so nothing is filtered invisibly.
	await expect(page.locator('#mailspur-more')).toBeVisible();
	await expect(page.locator('#mailspur-delivery-field')).toBeVisible();
	await expect(page.locator('#mailspur-delivery')).toHaveValue('bounced');
	await expect(page.locator('#mailspur-more-count')).toHaveText('1');

	await page.locator('#mailspur-delivery').selectOption('delivered');
	await expect(rows(page)).toHaveCount(1);
	await expect(rows(page).first()).toContainText('[WFD] Invoice delivered');
	await expect(page).toHaveURL(/[?&]delivery=delivered/);

	const [download] = await Promise.all([page.waitForEvent('download'), page.locator('.mailspur-export').getByRole('button', { name: 'CSV' }).click()]);
	const lines = fs.readFileSync(await download.path(), 'utf8').trim().split('\r\n');
	expect(lines).toHaveLength(2);
	expect(lines[0]).toContain('"status","delivery"');
	expect(lines[1]).toContain('"sent","delivered"');

	await page.locator('#mailspur-delivery').selectOption('');
	await expect(rows(page)).toHaveCount(2);
	await expect(page).not.toHaveURL(/delivery=/);
	expect(errors).toEqual([]);
});

test('export CSV and JSON of the current filter', async ({ page }) => {
	const errors = watch(page);
	await page.goto(`${WF}&source=plugin%3Amailspur-email-log`);
	await expect(rows(page)).toHaveCount(2);

	const exportBar = page.locator('.mailspur-export');
	await expect(exportBar).toBeVisible();

	const [csvDownload] = await Promise.all([page.waitForEvent('download'), exportBar.getByRole('button', { name: 'CSV' }).click()]);
	expect(csvDownload.suggestedFilename()).toMatch(/^mail-log-\d{4}-\d{2}-\d{2}\.csv$/);
	const csv = fs.readFileSync(await csvDownload.path(), 'utf8');
	expect(csv.charCodeAt(0)).toBe(0xfeff); // BOM for Excel.
	const lines = csv.trim().split('\r\n');
	expect(lines).toHaveLength(3); // Header + the two filtered entries.
	expect(lines[0]).toContain('"id","date","date_utc","status"');
	expect(csv).toContain('"invoice.pdf"');
	expect(csv).not.toContain('/nowhere/');
	expect(csv).not.toContain('<p>Invoice</p>');

	// Whole [WF] set as JSON with bodies; the formula subject is not defused in JSON (only CSV needs it).
	await page.goto(WF);
	await expect(rows(page)).toHaveCount(5);
	await page.locator('#mailspur-export-bodies').check();
	const [jsonDownload] = await Promise.all([page.waitForEvent('download'), exportBar.getByRole('button', { name: 'JSON' }).click()]);
	const data = JSON.parse(fs.readFileSync(await jsonDownload.path(), 'utf8'));
	expect(data).toHaveLength(5);
	expect(data.find((e) => e.subject.startsWith('[WF] Invoice')).message).toBe('<p>Invoice</p>');
	expect(data.find((e) => e.subject.includes('formula')).subject).toBe('=SUM(1+1) [WF] formula');

	// CSV defuses the formula cell.
	await page.locator('#mailspur-export-bodies').uncheck();
	const [all] = await Promise.all([page.waitForEvent('download'), exportBar.getByRole('button', { name: 'CSV' }).click()]);
	expect(fs.readFileSync(await all.path(), 'utf8')).toContain(`"'=SUM(1+1) [WF] formula"`);
	expect(errors).toEqual([]);
});

test('anonymised entries are marked and cannot be resent', async ({ page }) => {
	const errors = watch(page);
	await page.goto(`${LOG}&s=Old%20anonymised`);
	await expect(rows(page)).toHaveCount(1);
	const row = rows(page).first();
	await expect(row.locator('.mailspur-anon-badge')).toHaveText('Anonymised');
	await expect(row.locator('.col-to')).toHaveText('a***@example.com');
	await expect(row.locator('[data-action="resend"]')).toHaveCount(0);

	await row.locator('.mailspur-open').click();
	await expect(page.locator('#mailspur-d-meta')).toContainText('Content removed after 30 days');
	await expect(page.locator('#mailspur-dialog [data-action="resend"]')).toBeHidden();
	await page.keyboard.press('Escape');

	// A normal entry keeps its resend button in the dialog.
	await page.goto(`${WF}&status=held`);
	await rows(page).first().locator('.mailspur-open').click();
	await expect(page.locator('#mailspur-dialog [data-action="resend"]')).toBeVisible();
	await page.keyboard.press('Escape');
	expect(errors).toEqual([]);
});

test('copy for support: plain sentence in the clipboard, keyboard accessible', async ({ page, context }) => {
	const errors = watch(page);
	await context.grantPermissions(['clipboard-read', 'clipboard-write']);
	await page.goto(`${WF}&status=failed`);
	await rows(page).first().locator('.mailspur-open').click();

	const copy = page.locator('#mailspur-dialog [data-module-action="support"]');
	await expect(copy).toBeVisible();
	await expect(copy).toHaveText('Copy for support');
	await copy.focus();
	await page.keyboard.press('Enter');
	await expect(page.locator('#mailspur-toast')).toContainText('Copied');

	const text = await page.evaluate(() => navigator.clipboard.readText());
	expect(text).toMatch(/^The email “\[WF\] Failed with notes” to wf@example\.com on .+ at .+ could not be delivered\. We will send it again\.$/);
	expect(text).not.toContain('SMTP');
	await page.keyboard.press('Escape');
	expect(errors).toEqual([]);
});

test('mobile: toolbar with more filters and export has no horizontal scrolling', async ({ page }) => {
	await page.setViewportSize({ width: 375, height: 812 });
	await page.goto(`${WF}&format=text`);
	await expect(page.locator('#mailspur-more')).toBeVisible();
	await expect(rows(page).first()).toBeVisible();
	const overflow = await page.evaluate(() => {
		const app = document.querySelector('.mailspur-app');
		return app.scrollWidth - app.clientWidth;
	});
	expect(overflow).toBeLessThanOrEqual(0);
	const doc = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	expect(doc).toBeLessThanOrEqual(0);
});
