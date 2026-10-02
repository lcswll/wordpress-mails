// Email types tab in a real browser: inventory of the core seed (60 order mails, password reset, failed newsletter,
// XSS subject), the attention view, ignoring a type, the settings row and the type line in the log dialog.
// Read-only towards the log: other specs assert exact counts.
import { expect, test } from '@playwright/test';

const LOG = '/wp-admin/admin.php?page=mailspur-email-log';
const TYPES = `${LOG}&tab=types`;
const typeRow = (page, text) => page.locator('.mst-row').filter({ has: page.locator('.mst-pattern', { hasText: text }) });

test.describe.configure({ mode: 'serial' });

function watchErrors(page) {
	const errors = [];
	page.on('console', (msg) => {
		if (msg.type() === 'error' && !/Content Security Policy|about:srcdoc/.test(msg.text())) errors.push(msg.text());
	});
	page.on('pageerror', (err) => errors.push(err.message));
	return errors;
}

test('tab lists every email type with volume, rhythm and status', async ({ page }) => {
	const errors = watchErrors(page);
	await page.goto(TYPES);

	// Second tab, right after the log.
	await expect(page.locator('.mailspur-nav a').nth(1)).toHaveText('Email types');
	await expect(page.locator('.mailspur-nav a.is-active')).toHaveText('Email types');

	// 60 order confirmations with different numbers are one type, numbers shown as a placeholder chip.
	const orders = typeRow(page, 'Your order');
	await expect(orders).toHaveCount(1);
	await expect(orders.locator('.mst-pattern')).toContainText('[Example Shop] Your order #number has been received');
	await expect(orders.locator('.mst-ph')).toHaveText('number');
	await expect(orders.locator('.mst-volume')).toHaveText('60 in 30 days');
	await expect(orders.locator('svg.mst-spark')).toHaveAttribute('aria-label', /60 emails in the last 30 days/);
	await expect(orders.locator('svg.mst-spark rect.mst-bar')).toHaveCount(1);
	await expect(orders.locator('.mst-state')).toHaveText('New');
	await expect(orders.getByRole('link', { name: 'Show emails' })).toHaveAttribute('href', /source=core.*s=/);

	// The failed newsletter is flagged.
	const newsletter = typeRow(page, 'Your weekly newsletter');
	await expect(newsletter.locator('.mst-state')).toHaveText('Failing');
	await expect(newsletter.locator('.mst-failed')).toHaveText('1 failed');

	// Hostile subjects are plain text.
	await expect(page.locator('.mailspur-types img')).toHaveCount(0);
	await expect(page.locator('.mst-summary')).toContainText(/email types from \d+ senders/);
	expect(errors).toEqual([]);
});

test('attention view shows only problems', async ({ page }) => {
	await page.goto(TYPES);
	await page.getByRole('link', { name: 'Needs attention' }).click();
	await expect(page).toHaveURL(/show=attention/);
	await expect(page.locator('.mst-row')).toHaveCount(1);
	await expect(page.locator('.mst-row .mst-pattern')).toContainText('Your weekly newsletter');
});

test('a type can be ignored and monitored again', async ({ page }) => {
	await page.goto(TYPES);
	const newsletter = typeRow(page, 'Your weekly newsletter');
	await newsletter.getByRole('button', { name: 'Ignore' }).click();
	await expect(page.locator('.notice-success')).toContainText('This email type is ignored now');
	await expect(typeRow(page, 'Your weekly newsletter').locator('.mst-state')).toHaveText('Ignored');

	await typeRow(page, 'Your weekly newsletter').getByRole('button', { name: 'Monitor again' }).click();
	await expect(page.locator('.notice-success')).toContainText('monitored again');
	await expect(typeRow(page, 'Your weekly newsletter').locator('.mst-state')).toHaveText('Failing');
});

test('settings offer the stopped-type alert', async ({ page }) => {
	await page.goto(`${LOG}&tab=settings`);
	await expect(page.locator('#mailspur-types-alerts')).toContainText('Alert when an email type that is sent regularly stops');
	await expect(page.locator('input[name="mailspur_settings[alert_types]"]')).not.toBeChecked();
});

test('log dialog names the email type', async ({ page }) => {
	const errors = watchErrors(page);
	await page.goto(LOG);
	await page.locator('#mailspur-rows tr[data-id] .mailspur-open', { hasText: 'Your order #125659 has been received' }).click();
	const dialog = page.locator('#mailspur-dialog');
	await expect(dialog).toBeVisible();
	const link = dialog.locator('.mailspur-type-dd a');
	await expect(link).toHaveText('[Example Shop] Your order #… has been received');
	await expect(link).toHaveAttribute('href', /tab=types#mailspur-type-\d+$/);
	await expect(dialog.locator('.mailspur-type-rhythm')).toHaveText('usually sent: Rarely');
	expect(errors).toEqual([]);
});
