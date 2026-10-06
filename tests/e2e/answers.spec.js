// "Overview" tab in a real browser: four question cards, the "Did my email arrive?" lookup for an address and an
// order number, the link into the filtered log and the health line of the dashboard widget.
// Read-only towards the log: other specs assert exact counts (seed: 63 mails today, the newsletter failed).
import { expect, test } from '@playwright/test';

const LOG = '/wp-admin/admin.php?page=mailspur-email-log';
const OVERVIEW = `${LOG}&tab=overview`;

test.describe.configure({ mode: 'serial' });

function watchErrors(page) {
	const errors = [];
	page.on('console', (msg) => {
		if (msg.type() === 'error' && !/Content Security Policy|about:srcdoc/.test(msg.text())) errors.push(msg.text());
	});
	page.on('pageerror', (err) => errors.push(err.message));
	return errors;
}

test('overview is the first tab, the log stays the default', async ({ page }) => {
	const errors = watchErrors(page);
	await page.goto(LOG);
	await expect(page.locator('.mailspur-nav a').first()).toHaveText('Overview');
	await expect(page.locator('.mailspur-nav a.is-active')).toHaveText('Log');

	await page.locator('.mailspur-nav a').first().click();
	await expect(page).toHaveURL(/tab=overview/);
	const cards = page.locator('#mailspur-answers .msa-card');
	await expect(cards).toHaveCount(4);
	await expect(cards.locator('h2')).toHaveText(['Did my email arrive?', 'Is everything running?', 'Is an email missing?', 'Why did an email fail?']);
	await expect(page.locator('#msa-health .msa-text').first()).toContainText(/needs? attention|Everything looks fine/);
	await expect(page.locator('#msa-failure .msa-text').first()).toHaveText('1 email failed in the last 7 days.');
	await expect(page.locator('#msa-failure .msa-more a')).toHaveAttribute('href', /status=failed/);
	expect(errors).toEqual([]);
});

test('typing an address answers in a sentence', async ({ page }) => {
	const errors = watchErrors(page);
	await page.goto(OVERVIEW);
	const input = page.getByRole('searchbox', { name: 'Email address or order number' });
	const answer = page.locator('#msa-arrived-answer');

	await input.fill('subscribers@example.com');
	await page.getByRole('button', { name: 'Check' }).click();
	await expect(answer.locator('.msa-answer')).toHaveClass(/is-bad/);
	await expect(answer.locator('.msa-text').first()).toContainText('No. “Your weekly newsletter” to subscribers@example.com failed today at');
	await expect(answer.locator('.msa-text').first()).toContainText('Reason:');

	await input.fill('jan0@example.com');
	await input.press('Enter');
	await expect(answer.locator('.msa-text').first()).toContainText('Very likely. “[Example Shop] Your order #125600 has been received” went out to jan0@example.com today at');
	await expect(answer).toContainText('check the spam folder');

	// Order number without a shop: found by its number in the subject.
	await input.fill('#125601');
	await input.press('Enter');
	await expect(answer.locator('.msa-text').first()).toContainText('“[Example Shop] Your order #125601 has been received” went out to lena1@example.com');

	await input.fill('nobody@example.net');
	await input.press('Enter');
	await expect(answer.locator('.msa-text').first()).toContainText('Mailspur has no email to nobody@example.net in the log.');

	// The address never goes into the URL.
	await expect(page).not.toHaveURL(/example/);

	await input.fill('subscribers@example.com');
	await input.press('Enter');
	await answer.getByRole('link', { name: 'Show these emails in the log' }).click();
	await expect(page.locator('#mailspur-rows tr[data-id]')).toHaveCount(1, { timeout: 20_000 });
	await expect(page.locator('#mailspur-search')).toHaveValue('subscribers@example.com');
	expect(errors).toEqual([]);
});

test('dashboard widget starts with the health sentence', async ({ page }) => {
	const errors = watchErrors(page);
	await page.goto('/wp-admin/');
	const line = page.locator('#mailspur_insights_widget .msa-dashboard');
	await expect(line).toBeVisible();
	await expect(line).toContainText(/needs? attention|Everything looks fine/);
	await line.getByRole('link', { name: 'Overview' }).click();
	await expect(page.locator('#mailspur-answers')).toBeVisible();
	expect(errors).toEqual([]);
});

test('overview has no horizontal scrolling on mobile', async ({ page }) => {
	await page.setViewportSize({ width: 375, height: 800 });
	await page.goto(OVERVIEW);
	await expect(page.locator('#mailspur-answers')).toBeVisible();
	const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	expect(overflow).toBeLessThanOrEqual(0);
});
