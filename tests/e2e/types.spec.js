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

	// Right after the log (Overview, Log, Email types …).
	await expect(page.locator('.mailspur-nav a').nth(2)).toHaveText('Email types');
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

test('email inventory opens as a printable page with a CSV download', async ({ page }) => {
	const errors = watchErrors(page);
	await page.goto(TYPES);
	const link = page.locator('a.mst-export');
	await expect(link).toHaveText('Email inventory');
	await page.goto(await link.getAttribute('href'));
	await expect(page.locator('h1')).toHaveText('Email inventory');
	const orders = page.locator('tbody tr').filter({ hasText: '[Example Shop] Your order #… has been received' });
	await expect(orders).toHaveCount(1);
	await expect(orders).toContainText('Email address');
	await expect(page.locator('img')).toHaveCount(0);

	const [csv] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: 'Download as CSV' }).click()]);
	expect(csv.suggestedFilename()).toMatch(/^email-inventory-\d{4}-\d{2}-\d{2}\.csv$/);
	expect(errors).toEqual([]);
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

// The tests below add their own rows (?mailspur-e2e-seed=types) and remove them again afterwards.
test.afterAll(async ({ browser }) => {
	const page = await browser.newPage();
	await page.goto('/wp-admin/?mailspur-e2e-seed=types-cleanup');
	await page.close();
});

test('content change: marker, compare view and "Seen"', async ({ page }) => {
	const errors = watchErrors(page);
	await page.goto('/wp-admin/?mailspur-e2e-seed=types');
	await expect(page).toHaveURL(/tab=types/);

	const digest = typeRow(page, '[Types test] Weekly digest');
	await expect(digest).toHaveCount(1);
	const marker = digest.locator('.mst-change');
	await expect(marker).toContainText(/Content changed on .+, after the E2E Digest 2\.0 update\./);

	await marker.getByRole('button', { name: 'Compare' }).click();
	const dialog = page.locator('dialog.mst-dialog');
	await expect(dialog).toBeVisible();
	await expect(dialog.locator('h2')).toHaveText('[Types test] Weekly digest #…');
	await expect(dialog.locator('.mst-diff .is-del')).toHaveCount(1);
	await expect(dialog.locator('.mst-diff .is-del')).toContainText('Read online [https://news.example/read/87/]');
	await expect(dialog.locator('.mst-diff .is-add')).toHaveCount(0);
	await expect(dialog.locator('.mst-diff')).not.toContainText('token=');

	await dialog.getByRole('tab', { name: 'Previews' }).click();
	const frames = dialog.locator('iframe.mst-frame');
	await expect(frames).toHaveCount(2);
	await expect(frames.first()).toHaveAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox');
	await expect(dialog.locator('figcaption').first()).toContainText('Last email before the change');
	await expect(page.frameLocator('iframe.mst-frame').first().getByRole('link', { name: 'Read online' })).toBeVisible();
	await expect(page.frameLocator('iframe.mst-frame').nth(1).getByRole('link', { name: 'Read online' })).toHaveCount(0);
	await dialog.getByRole('button', { name: 'Close' }).click();
	await expect(dialog).toBeHidden();

	await marker.getByRole('button', { name: 'Seen' }).click();
	await expect(page.locator('.notice-success')).toContainText('Content change marked as seen.');
	await expect(typeRow(page, '[Types test] Weekly digest').locator('.mst-change')).toHaveCount(0);
	expect(errors).toEqual([]);
});

test('stopped cron type names its cause', async ({ page }) => {
	await page.goto(TYPES);
	const reminder = typeRow(page, '[Types test] Daily reminder');
	await expect(reminder.locator('.mst-state')).toHaveText('Stopped');
	await expect(reminder.locator('.mst-cause')).toHaveText('Sent by the cron event mailspur_e2e_ui_reminder – this event is no longer scheduled.');
});

test('shortcuts: open the latest email and send it to me', async ({ page }) => {
	const errors = watchErrors(page);
	await page.goto(TYPES);
	let digest = typeRow(page, '[Types test] Weekly digest');
	await digest.locator('.mst-more summary').click();
	const open = digest.getByRole('link', { name: 'Open latest' });
	await expect(open).toHaveAttribute('href', /mail=\d+/);
	await open.click();
	await expect(page.locator('#mailspur-dialog')).toBeVisible();
	await expect(page.locator('#mailspur-d-subject')).toHaveText('[Types test] Weekly digest #89');
	await expect(page).not.toHaveURL(/mail=/);

	await page.goto(TYPES);
	digest = typeRow(page, '[Types test] Weekly digest');
	await digest.locator('.mst-more summary').click();
	await digest.getByRole('button', { name: 'Send latest to me' }).click();
	const confirm = digest.locator('.mst-confirm');
	await expect(confirm).toContainText(/Send the latest email of this type to .+@.+\?/);
	await confirm.getByRole('button', { name: 'Send' }).click();
	await expect(confirm.locator('[role="status"]')).toHaveText(/^Sent to .+@.+\.$/);
	expect(errors).toEqual([]);
});

test('shortcuts: core emails explain they have no editor and offer a probe', async ({ page }) => {
	const errors = watchErrors(page);
	await page.goto(TYPES);
	const reset = typeRow(page, 'Password reset');
	await reset.locator('.mst-more summary').click();
	await expect(reset.getByRole('link', { name: 'Edit template' })).toHaveCount(0);
	await expect(reset.locator('.mst-menu-note')).toHaveText('WordPress core emails have no editor; they can be changed with filters.');
	// Only the confirmation: triggering would add log entries other specs count.
	await reset.getByRole('button', { name: 'Trigger to me' }).click();
	const confirm = reset.locator('.mst-confirm');
	await expect(confirm).toContainText(/new reset link .+ Only .+@.+ receives it\./);
	await confirm.getByRole('button', { name: 'Cancel' }).click();
	await expect(confirm).toHaveCount(0);
	await expect(reset.getByRole('button', { name: 'Trigger to me' })).toBeFocused();
	expect(errors).toEqual([]);
});
