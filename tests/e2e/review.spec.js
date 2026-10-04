// Author credit and review request in a real browser: plugin list (author link, review link), footer credit on
// Mailspur's page, and the review card (due only after 14 days with delivered emails; "Maybe later" snoozes it).
import { expect, test } from '@playwright/test';

const LOG = '/wp-admin/admin.php?page=mailspur-email-log';

test.describe.configure({ mode: 'serial' });

test('plugin list names the author and links to the reviews', async ({ page }) => {
	await page.goto('/wp-admin/plugins.php');
	const row = page.locator('tr[data-slug="mailspur-email-log"]').first();
	await expect(row.getByRole('link', { name: 'Lucas Wille' })).toHaveAttribute('href', 'https://lucaswille.de/');
	await expect(row.getByRole('link', { name: 'Leave a review' })).toHaveAttribute('href', /wordpress\.org\/support\/plugin\/mailspur-email-log\/reviews/);
});

test('footer credit only on Mailspur pages', async ({ page }) => {
	await page.goto(LOG);
	const footer = page.locator('#footer-left');
	await expect(footer).toContainText('Mailspur is made by Lucas Wille');
	await expect(footer.getByRole('link', { name: 'Lucas Wille' })).toHaveAttribute('href', 'https://lucaswille.de/');
	await expect(footer.getByRole('link', { name: /review/ })).toHaveAttribute('href', /reviews\/#new-post/);

	await page.goto('/wp-admin/index.php');
	await expect(page.locator('#footer-left')).not.toContainText('Mailspur');
});

test('review request appears when it goes well and can be snoozed', async ({ page }) => {
	await page.goto(LOG);
	await expect(page.locator('.mailspur-review')).toHaveCount(0); // Fresh install: too early.

	await page.goto('/wp-admin/?mailspur-e2e-seed=review');
	await page.goto(LOG);
	const card = page.locator('.mailspur-review');
	await expect(card).toBeVisible();
	await expect(card).toContainText('Is Mailspur helping you?');
	await expect(card).toContainText(/has logged \d+ delivered emails/);
	await expect(card.getByRole('link', { name: 'Rate Mailspur on WordPress.org' })).toHaveAttribute('target', '_blank');

	// Not on other admin pages.
	await page.goto('/wp-admin/index.php');
	await expect(page.locator('.mailspur-review')).toHaveCount(0);

	await page.goto(LOG);
	await page.locator('.mailspur-review').getByRole('link', { name: 'Maybe later' }).click();
	await expect(page).toHaveURL(/page=mailspur-email-log/);
	await expect(page.locator('.mailspur-review')).toHaveCount(0);
});
