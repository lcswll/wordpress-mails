// Notes module in a real browser: list badges, error hints, the Notes dialog tab, error explanations
// and the settings section. Uses the core seed (tests/e2e/seed.php) only – extra seeded mails would
// change the totals the core spec asserts.
import { expect, test } from '@playwright/test';

const LOG = '/wp-admin/admin.php?page=mailspur-email-log';
const SETTINGS = `${LOG}&tab=settings`;
const rows = (page) => page.locator('#mailspur-rows tr[data-id]');
const row = (page, subject) => rows(page).filter({ has: page.locator('.mailspur-open', { hasText: subject }) });

test.describe.configure({ mode: 'serial' });

function watchErrors(page) {
	const errors = [];
	page.on('console', (msg) => {
		// Expected CSP reports from the sandboxed preview are not ours.
		if (msg.type() === 'error' && !/Content Security Policy|about:srcdoc/.test(msg.text())) errors.push(msg.text());
	});
	page.on('pageerror', (err) => errors.push(err.message));
	page.on('dialog', (dialog) => dialog.accept());
	return errors;
}

test('list shows note badges and short error hints', async ({ page }) => {
	const errors = watchErrors(page);
	await page.goto(LOG);
	await expect(rows(page)).toHaveCount(25);

	// Password reset: sent without any sender (pre_wp_mail) → info note.
	const reset = row(page, 'Password reset');
	const badge = reset.locator('.mailspur-notes-badge');
	await expect(badge).toHaveCount(1);
	await expect(badge).toHaveClass(/is-info/);
	await expect(badge).toHaveText('⚠ 1');
	await expect(badge).toHaveAttribute('aria-label', 'Notes: 1. No sender address recorded');

	// Failed newsletter: the explanation title sits under the raw error, which stays untouched.
	const newsletter = row(page, 'Your weekly newsletter');
	await expect(newsletter.locator('.mailspur-error')).toContainText('Could not instantiate mail function');
	await expect(newsletter.locator('.mailspur-notes-hint')).toHaveText('The server cannot send email with PHP mail()');

	// Clean order confirmations get no badge.
	await expect(row(page, 'Your order #125659 has been received').locator('.mailspur-notes-badge')).toHaveCount(0);
	expect(errors).toEqual([]);
});

test('badge opens the dialog on the Notes tab', async ({ page }) => {
	const errors = watchErrors(page);
	await page.goto(LOG);
	await row(page, 'Password reset').locator('.mailspur-notes-badge').click();

	const dialog = page.locator('#mailspur-dialog');
	await expect(dialog).toBeVisible();
	const tab = dialog.locator('#mailspur-tab-notes');
	await expect(tab).toHaveText('Notes (1)');
	await expect(tab).toHaveAttribute('aria-selected', 'true');

	const body = dialog.locator('#mailspur-d-body');
	await expect(body.locator('.mailspur-notes-group.is-info h3')).toHaveText('Information (1)');
	const note = body.locator('.mailspur-note[data-code="no_from"]');
	await expect(note.locator('.mailspur-note-title')).toHaveText('No sender address recorded');
	await expect(note.locator('.mailspur-note-fix')).toContainText('How to fix:');

	// It behaves like the other dialog tabs.
	await dialog.locator('#mailspur-tab-preview').click();
	await expect(body.locator('.mailspur-notes')).toHaveCount(0);
	await tab.click();
	await expect(body.locator('.mailspur-notes')).toBeVisible();
	expect(errors).toEqual([]);
});

test('clean mail shows "no problems" and opens on the preview', async ({ page }) => {
	await page.goto(LOG);
	await row(page, 'Your order #125659 has been received').locator('.mailspur-open').click();
	const dialog = page.locator('#mailspur-dialog');
	await expect(dialog.locator('#mailspur-tab-preview')).toHaveAttribute('aria-selected', 'true');
	await expect(dialog.locator('#mailspur-tab-notes')).toHaveText('Notes');
	await dialog.locator('#mailspur-tab-notes').click();
	await expect(dialog.locator('.mailspur-notes-none')).toHaveText('No problems found in this email.');
});

test('failed mail explains the error with steps', async ({ page }) => {
	await page.goto(LOG);
	await row(page, 'Your weekly newsletter').locator('.mailspur-open').click();
	const meta = page.locator('#mailspur-d-meta');
	await expect(meta.locator('dt.mailspur-notes-help-label')).toHaveText('What to do');
	const help = meta.locator('dd.mailspur-notes-help');
	await expect(help.locator('strong')).toHaveText('The server cannot send email with PHP mail()');
	await expect(help.locator('ol li')).toHaveCount(3);
	await expect(help.locator('ol li').first()).toContainText('SMTP plugin');
});

test('settings: ignored notes disappear from the list, the switch hides everything', async ({ page }) => {
	await page.goto(SETTINGS);
	await expect(page.getByRole('heading', { name: 'Notes', exact: true })).toBeVisible();
	const enabled = page.locator('input[name="mailspur_settings[notes_enabled]"]');
	const ignoreNoFrom = page.locator('input[name="mailspur_settings[notes_ignore][]"][value="no_from"]');
	await expect(enabled).toBeChecked();
	await expect(page.locator('input[name="mailspur_settings[notes_ignore][]"]')).not.toHaveCount(0);
	await expect(page.locator('label', { has: ignoreNoFrom })).toHaveText('No sender address recorded');

	await ignoreNoFrom.check();
	await page.getByRole('button', { name: 'Save Changes' }).click();
	await expect(page.locator('#setting-error-settings_updated')).toBeVisible();
	await expect(ignoreNoFrom).toBeChecked();

	await page.goto(LOG);
	await expect(rows(page)).toHaveCount(25);
	await expect(row(page, 'Password reset').locator('.mailspur-notes-badge')).toHaveCount(0);

	// Switched off: no badges, no Notes tab – error explanations stay.
	await page.goto(SETTINGS);
	await page.locator('input[name="mailspur_settings[notes_enabled]"]').uncheck();
	await page.getByRole('button', { name: 'Save Changes' }).click();
	await expect(page.locator('#setting-error-settings_updated')).toBeVisible();

	await page.goto(LOG);
	await expect(rows(page)).toHaveCount(25);
	await expect(page.locator('#mailspur-rows .mailspur-notes-badge')).toHaveCount(0);
	await row(page, 'Your weekly newsletter').locator('.mailspur-open').click();
	await expect(page.locator('#mailspur-tab-notes')).toHaveCount(0);
	await expect(page.locator('#mailspur-d-meta dd.mailspur-notes-help')).toBeVisible();

	// Restore the defaults for the following specs.
	await page.goto(SETTINGS);
	await page.locator('input[name="mailspur_settings[notes_enabled]"]').check();
	await page.locator('input[name="mailspur_settings[notes_ignore][]"][value="no_from"]').uncheck();
	await page.getByRole('button', { name: 'Save Changes' }).click();
	await expect(page.locator('#setting-error-settings_updated')).toBeVisible();
	await expect(page.locator('input[name="mailspur_settings[notes_enabled]"]')).toBeChecked();
});
