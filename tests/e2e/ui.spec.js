// Admin screen in a real browser: filtering, preview isolation, actions, settings, mobile layout.
import { expect, test } from '@playwright/test';

const LOG = '/wp-admin/admin.php?page=outbox-mail-log';
const rows = (page) => page.locator('#outbox-rows tr[data-id]');

// Hosts referenced by the seeded mails (tracking pixel, links, form target). WordPress core itself
// may load e.g. Gravatar in the admin bar – that is not the plugin's business.
const MAIL_HOSTS = /(^|\.)(example\.com|example\.org|shop\.example|evil\.example|site\.example)$/;

// Console messages the browser emits when the preview protections kick in – expected, not errors.
const BLOCKED = /violates the following Content Security Policy directive|Blocked script execution in 'about:srcdoc'/;

/**
 * Collects console errors, protection reports (CSP/sandbox blocks) and every request to a host that only
 * appears inside logged mails.
 */
function watch(page) {
	const errors = [];
	const blocked = [];
	const external = [];
	page.on('console', (msg) => {
		if (msg.type() === 'error') (BLOCKED.test(msg.text()) ? blocked : errors).push(msg.text());
	});
	page.on('pageerror', (err) => errors.push(err.message));
	page.on('request', (req) => {
		const url = new URL(req.url());
		if (MAIL_HOSTS.test(url.hostname)) external.push(req.url());
	});
	page.on('dialog', (dialog) => dialog.accept());
	return { errors, blocked, external };
}

test.describe.configure({ mode: 'serial' });

test('log lists the seeded mails with counts', async ({ page }) => {
	const seen = watch(page);
	await page.goto(LOG);

	await expect(rows(page)).toHaveCount(25);
	await expect(page.locator('[data-count="all"]')).toHaveText('63');
	await expect(page.locator('[data-count="failed"]')).toHaveText('1');
	await expect(page.locator('#outbox-summary')).toContainText('63');

	// The XSS subject is shown as text, never parsed.
	await expect(rows(page).first().locator('.outbox-open')).toHaveText('<img src=x onerror=alert(1)>XSS subject');
	await expect(page.locator('#outbox-rows img')).toHaveCount(0);

	expect(seen.errors).toEqual([]);
	expect(seen.external).toEqual([]);

	// The matrix really runs the requested WordPress version (the CLI banner always shows its defaults).
	if (process.env.E2E_WP && process.env.E2E_WP !== 'latest') {
		// Core assets carry the running version (?ver=6.5.12); the footer would only offer the update.
		expect(await page.content()).toMatch(new RegExp(`ver=${process.env.E2E_WP.replace('.', '\\.')}(\\.\\d+)?\\b`));
	}
});

test('search, status, sorting and pagination', async ({ page }) => {
	await page.goto(LOG);
	await expect(rows(page)).toHaveCount(25);

	await page.keyboard.press('/');
	await expect(page.locator('#outbox-search')).toBeFocused();
	await page.keyboard.type('bob@example');
	await expect(rows(page)).toHaveCount(1);
	await expect(page).toHaveURL(/[?&]s=bob%40example/);
	await expect(rows(page).first()).toContainText('Password reset');

	await page.locator('#outbox-reset').click();
	await expect(rows(page)).toHaveCount(25);

	await page.locator('[data-status="failed"]').click();
	await expect(rows(page)).toHaveCount(1);
	await expect(rows(page).first().locator('.outbox-error')).toContainText('Could not instantiate mail function');
	await page.locator('[data-status="all"]').click();

	await page.locator('[data-sort="subject"]').click();
	await expect(page.locator('th.col-subject')).toHaveAttribute('aria-sort', 'descending');
	await page.locator('[data-sort="subject"]').click();
	await expect(page.locator('th.col-subject')).toHaveAttribute('aria-sort', 'ascending');
	await expect(rows(page).first()).toContainText('<img src=x');

	await page.locator('[data-page="next"]').click();
	await expect(page.locator('#outbox-page')).toHaveValue('2');
	await expect(page).toHaveURL(/paged=2/);
	await page.locator('#outbox-per-page').selectOption('100');
	await expect(rows(page)).toHaveCount(63);

	// Filters survive a reload because they live in the URL.
	await page.reload();
	await expect(page.locator('th.col-subject')).toHaveAttribute('aria-sort', 'ascending');
});

test('HTML preview is isolated: no scripts, no forms, no tracking', async ({ page }) => {
	const seen = watch(page);
	await page.goto(`${LOG}&s=jan0%40`);
	await expect(rows(page)).toHaveCount(1);
	await rows(page).first().locator('.outbox-open').click();

	const dialog = page.locator('#outbox-dialog');
	await expect(dialog).toBeVisible();
	await expect(page.locator('#outbox-d-subject')).toHaveText('[Example Shop] Your order #125600 has been received');
	await expect(page.locator('#outbox-d-meta')).toContainText('Example Shop <shop@example.com>');

	const frame = page.locator('iframe.outbox-frame');
	await expect(frame).toHaveAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox');
	const srcdoc = await frame.getAttribute('srcdoc');
	expect(srcdoc).toContain("default-src 'none'");
	expect(srcdoc).toContain('key=[redacted]');
	expect(srcdoc).not.toContain('SECRET123');

	const inner = page.frameLocator('iframe.outbox-frame');
	await expect(inner.locator('h1')).toHaveText('Thanks for your order!');

	// The injected script must not have touched the admin page.
	expect(await page.evaluate(() => window.pwned)).toBeUndefined();
	await expect(page.locator('#wpadminbar')).toBeVisible();
	// Opaque origin: the admin page cannot reach into the frame either (and vice versa).
	expect(await frame.evaluate((f) => f.contentDocument)).toBeNull();

	await expect(page.locator('#outbox-d-remote')).toBeVisible();
	expect(seen.external).toEqual([]);

	// Opting in relaxes the CSP for this one mail.
	await page.locator('#outbox-d-remote-toggle').click();
	expect(await page.locator('iframe.outbox-frame').getAttribute('srcdoc')).toMatch(/img-src data: cid: https: http:/);

	await page.getByRole('tab', { name: 'Headers' }).click();
	await page.keyboard.press('Escape');

	// Ordinary links are not remote content: a clean mail must not show the notice.
	await page.goto(`${LOG}&s=lena1%40`);
	await rows(page).first().locator('.outbox-open').click();
	await expect(page.frameLocator('iframe.outbox-frame').locator('h1')).toHaveText('Thanks for your order');
	await expect(page.locator('#outbox-d-remote')).toBeHidden();
	await page.getByRole('tab', { name: 'Headers' }).click();
	await expect(page.locator('#outbox-d-body pre')).toContainText('Content-Type: text/html; charset=UTF-8');

	await page.keyboard.press('Escape');
	await expect(dialog).toBeHidden();
	expect(seen.errors).toEqual([]);
	// The browser confirms both protections: the injected <script> and the tracking pixel were blocked.
	expect(seen.blocked.some((m) => m.includes("Blocked script execution in 'about:srcdoc'"))).toBe(true);
	expect(seen.blocked.some((m) => m.includes('https://example.com/pixel.gif'))).toBe(true);
});

test('keyboard navigation and delete from the dialog', async ({ page }) => {
	watch(page);
	await page.goto(LOG);
	await rows(page).nth(1).locator('.outbox-open').click();
	await expect(page.locator('#outbox-d-subject')).toHaveText('Your weekly newsletter');

	await page.keyboard.press('j');
	await expect(page.locator('#outbox-d-subject')).toHaveText('Password reset');
	await expect(page.locator('#outbox-d-body pre')).toContainText('key=[redacted]&login=anna');
	await page.keyboard.press('k');
	await expect(page.locator('#outbox-d-subject')).toHaveText('Your weekly newsletter');

	await page.locator('#outbox-dialog [data-action="delete"]').click();
	await expect(page.locator('[data-count="all"]')).toHaveText('62');
	await expect(page.locator('#outbox-d-subject')).toHaveText('Password reset');
	await page.keyboard.press('Escape');
});

test('bulk delete', async ({ page }) => {
	watch(page);
	await page.goto(`${LOG}&s=lena`);
	await expect(rows(page)).toHaveCount(6);
	await page.locator('#outbox-select-all').check();
	await expect(page.locator('#outbox-selected')).toContainText('6');
	await page.locator('#outbox-bulk-delete').click();
	await expect(page.locator('.outbox-empty')).toBeVisible();
	await expect(page.locator('[data-count="all"]')).toHaveText('0');
});

test('settings are saved and validated', async ({ page }) => {
	await page.goto(`${LOG}&tab=settings`);
	await page.locator('#outbox-retention').fill('30');
	// Bypass the browser's min="0" like a crafted request would, so the server-side sanitizer is tested.
	await page.locator('#outbox-max').evaluate((el) => el.removeAttribute('min'));
	await page.locator('#outbox-max').fill('-5');
	await page.locator('input[name="outbox_mail_log_settings[remote_images]"]').check();
	await page.getByRole('button', { name: 'Save Changes' }).click();

	await expect(page.locator('#setting-error-settings_updated')).toBeVisible();
	await expect(page.locator('#outbox-retention')).toHaveValue('30');
	await expect(page.locator('#outbox-max')).toHaveValue('5');
	await expect(page.locator('input[name="outbox_mail_log_settings[remote_images]"]')).toBeChecked();
});

test('mobile layout has no horizontal scrolling', async ({ page }) => {
	await page.setViewportSize({ width: 375, height: 812 });
	await page.goto(LOG);
	await expect(rows(page).first()).toBeVisible();
	const overflow = await page.evaluate(() => document.querySelector('.outbox-app').scrollWidth - document.querySelector('.outbox-app').clientWidth);
	expect(overflow).toBeLessThanOrEqual(0);
});
