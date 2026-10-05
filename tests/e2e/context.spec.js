// Context module in a real browser: the deep link from the order box / profile opens one mail in the log,
// and the profile shows the "Emails to this user" section. Read-only towards the log: other specs assert exact counts.
// The order box itself needs WooCommerce, which the e2e site does not have (covered by tests/e2e/features/context.php).
import { expect, test } from '@playwright/test';

const LOG = '/wp-admin/admin.php?page=mailspur-email-log';

test.describe.configure({ mode: 'serial' });

function watchErrors(page) {
	const errors = [];
	page.on('console', (msg) => {
		if (msg.type() === 'error' && !/Content Security Policy|about:srcdoc/.test(msg.text())) errors.push(msg.text());
	});
	page.on('pageerror', (err) => errors.push(err.message));
	return errors;
}

test('deep link opens the mail once and leaves a clean URL', async ({ page }) => {
	const errors = watchErrors(page);
	const subject = 'Your order #125659 has been received';
	await page.goto(`${LOG}&s=${encodeURIComponent(subject)}`);
	const row = page.locator('#mailspur-rows tr[data-id]').filter({ hasText: subject }).first();
	const id = await row.getAttribute('data-id');
	expect(Number(id)).toBeGreaterThan(0);

	await page.goto(`${LOG}&s=${encodeURIComponent(subject)}&mail=${id}`);
	const dialog = page.locator('#mailspur-dialog');
	await expect(dialog).toBeVisible();
	await expect(dialog.locator('#mailspur-d-subject')).toContainText(subject);
	await expect(page).not.toHaveURL(/[?&]mail=/);
	expect(errors).toEqual([]);
});

test('profile shows the emails to this user with a link into the log', async ({ page }) => {
	const errors = watchErrors(page);
	await page.goto('/wp-admin/profile.php');
	const section = page.locator('.mailspur-ctx-profile');
	await expect(section.getByRole('heading', { name: 'Emails to this user' })).toBeVisible();
	await expect(section.locator('table.mailspur-ctx-table, .mailspur-ctx-empty').first()).toBeVisible();
	const all = section.getByRole('link', { name: 'Show all in Mail Log' });
	await expect(all).toHaveAttribute('href', /page=mailspur-email-log.*s=/);
	// Styles are scoped to the section: the rest of the profile keeps the WordPress look.
	await expect(page.locator('#your-profile .form-table').first()).toBeVisible();
	expect(errors).toEqual([]);
});
