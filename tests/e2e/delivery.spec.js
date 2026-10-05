// Delivery module in a real browser: staging mode (settings, notice, admin bar, held mails, "Send now"),
// "Send to…" and the sender check. Runs before ui.spec.js on the same site, so it removes every entry it
// creates and switches staging mode off again.
import { expect, test } from '@playwright/test';

const LOG = '/wp-admin/admin.php?page=mailspur-email-log';
const SETTINGS = `${LOG}&tab=settings`;
const rows = (page) => page.locator('#mailspur-rows tr[data-id]');

test.describe.configure({ mode: 'serial' });

/** Accepts confirm()s and answers prompt()s from the queue; collects console errors. */
function watch(page, answers = []) {
	const errors = [];
	page.on('console', (msg) => {
		// The preview's CSP/sandbox reports blocked content – that is the protection working, not an error.
		if (msg.type() === 'error' && !/Content Security Policy|about:srcdoc/.test(msg.text())) errors.push(msg.text());
	});
	page.on('pageerror', (err) => errors.push(err.message));
	page.on('dialog', (dialog) => ('prompt' === dialog.type() ? dialog.accept(answers.shift() ?? '') : dialog.accept()));
	return errors;
}

/** REST call from inside the admin page (uses the page's nonce). */
async function rest(page, path, method = 'GET') {
	return page.evaluate(
		async ([p, m]) => {
			const cfg = window.mailspurConfig;
			const res = await fetch(cfg.restUrl.replace(/\/?$/, '/') + p, { method: m, headers: { 'X-WP-Nonce': cfg.nonce } });
			return res.json();
		},
		[path, method],
	);
}

async function setMode(page, mode, redirectTo = '') {
	await page.goto(SETTINGS);
	await page.locator(`input[name="mailspur_settings[staging_mode]"][value="${mode}"]`).check();
	await page.locator('#mailspur-staging-redirect').fill(redirectTo);
	await page.getByRole('button', { name: 'Save Changes' }).click();
	await expect(page.locator('#setting-error-settings_updated')).toBeVisible();
}

let maxId = 0;

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage();
	await page.goto(LOG);
	const list = await rest(page, 'mails?per_page=200');
	maxId = Math.max(0, ...list.items.map((i) => i.id));
	await page.close();
});

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage();
	await setMode(page, 'off');
	await page.goto(LOG);
	const list = await rest(page, 'mails?per_page=200');
	const ids = list.items.map((i) => i.id).filter((id) => id > maxId);
	if (ids.length) await rest(page, 'mails?ids=' + ids.join(','), 'DELETE');
	await page.close();
});

test('staging mode holds mails, warns everywhere and releases one by one', async ({ page }) => {
	const errors = watch(page);
	await setMode(page, 'hold');
	await expect(page.locator('#wp-admin-bar-mailspur-staging')).toContainText('Mailspur: staging mode');

	await page.goto(`${LOG}&s=lena1%40`);
	await expect(page.locator('.mailspur-staging-notice')).toContainText('Staging mode is active: emails are logged but not delivered.');
	await expect(rows(page)).toHaveCount(1);

	// The core "Resend" goes through wp_mail() and is held.
	await rows(page).first().locator('.mailspur-open').click();
	await expect(page.locator('[data-module-action="delivery-release"]')).toBeHidden();
	await page.locator('#mailspur-dialog [data-action="resend"]').click();
	await expect(page.locator('#mailspur-toast')).toContainText('Email sent again.');
	await page.keyboard.press('Escape');

	await page.goto(`${LOG}&s=lena1%40&status=held`);
	await expect(rows(page)).toHaveCount(1);
	await expect(rows(page).first().locator('.mailspur-badge')).toHaveText('Held');
	await rows(page).first().locator('.mailspur-open').click();
	await expect(page.locator('#mailspur-d-meta')).toContainText('Held by staging mode – not delivered.');

	// "Send now" bypasses staging mode for this one mail and logs a new entry (Playground cannot deliver → failed).
	await page.locator('[data-module-action="delivery-release"]').click();
	await expect(page.locator('#mailspur-toast')).toHaveText(/Held email sent|Sending failed/);
	await page.keyboard.press('Escape');
	await page.goto(`${LOG}&s=lena1%40`);
	await expect(rows(page)).toHaveCount(3);
	await expect(page.locator('[data-count="held"]')).toHaveText('1');
	await expect(rows(page).first().locator('.col-source')).toHaveText('Resent from log');
	await expect(rows(page).first().locator('.mailspur-badge')).not.toHaveText('Held');
	await rows(page).first().locator('.mailspur-open').click();
	await expect(page.locator('#mailspur-d-meta')).toContainText(/Released from staging \(held entry #\d+\)\./);
	expect(errors).toEqual([]);
});

test('send to another address', async ({ page }) => {
	const errors = watch(page, ['nope', 'qa@example.net, dev@example.net']);
	await page.goto(`${LOG}&s=mia2%40`);
	await rows(page).first().locator('.mailspur-open').click();

	await page.locator('[data-module-action="delivery-send-to"]').click();
	await expect(page.locator('#mailspur-toast')).toHaveText('Please enter valid email addresses.');

	await page.locator('[data-module-action="delivery-send-to"]').click();
	// Staging mode (hold) is still on, so wp_mail() reports success and the copy is held.
	await expect(page.locator('#mailspur-toast')).toHaveText('Email sent to qa@example.net, dev@example.net.');
	await page.keyboard.press('Escape');

	await page.goto(`${LOG}&s=qa%40example.net`);
	await expect(rows(page)).toHaveCount(1);
	await expect(rows(page).first().locator('.col-to')).toContainText('qa@example.net, dev@example.net');
	await expect(rows(page).first().locator('.col-subject')).toContainText('Your order #125602');
	expect(errors).toEqual([]);
});

test('redirect mode without a valid address falls back to holding', async ({ page }) => {
	watch(page);
	await setMode(page, 'redirect', 'not-an-address');
	await expect(page.locator('#mailspur-staging-redirect')).toHaveValue('');
	await expect(page.locator('.mailspur-staging-notice')).toContainText('no valid redirect address is set');

	await setMode(page, 'redirect', 'dev@example.net');
	await expect(page.locator('#mailspur-staging-redirect')).toHaveValue('dev@example.net');
	await expect(page.locator('.mailspur-staging-notice')).toContainText('all emails are redirected to dev@example.net');
});

/** Saves the emergency brake settings (staging mode off). */
async function setBrake(page, mode, threshold) {
	await page.goto(SETTINGS);
	await page.locator('input[name="mailspur_settings[staging_mode]"][value="off"]').check();
	await page.locator(`input[name="mailspur_settings[brake_mode]"][value="${mode}"]`).check();
	await page.locator('#mailspur-brake-threshold').fill(threshold);
	await page.getByRole('button', { name: 'Save Changes' }).click();
	await expect(page.locator('#setting-error-settings_updated')).toBeVisible();
}

test('emergency brake holds a flood and releases it in batches', async ({ page }) => {
	const errors = watch(page);
	await setBrake(page, 'hold', '1');
	await expect(page.locator('#mailspur-brake-threshold')).toHaveValue('1');

	// A new user gets two notifications (admin + user): the second one exceeds the threshold of 1 per hour.
	await page.goto('/wp-admin/user-new.php');
	await page.locator('#user_login').fill('brakeflood');
	await page.locator('#email').fill('brakeflood@example.com');
	await page.locator('#createusersub').click();
	await page.waitForURL(/users.php/);
	await expect(page.locator('#wp-admin-bar-mailspur-brake')).toContainText('Mailspur: emails held');

	await page.goto(LOG);
	const notice = page.locator('#mailspur-brake-notice');
	await expect(notice).toContainText('Emergency brake:');
	await expect(notice).toContainText('emails are held by the emergency brake.');
	await expect(notice).toContainText('Main source:');

	await page.goto(`${LOG}&s=brakeflood%40&status=held`);
	await expect(rows(page)).toHaveCount(1);
	await rows(page).first().locator('.mailspur-open').click();
	await expect(page.locator('#mailspur-d-meta')).toContainText('Held by emergency brake – not delivered.');
	await page.keyboard.press('Escape');

	await notice.locator('[data-mailspur-brake="release"]').click();
	await expect(notice.locator('.mailspur-brake-progress')).toHaveText(/^Done: \d+ emails sent, \d+ failed\.$/);
	await expect(notice).toHaveClass(/notice-success/);

	await page.reload();
	await expect(page.locator('#mailspur-brake-notice')).toHaveCount(0);
	await expect(page.locator('#wp-admin-bar-mailspur-brake')).toHaveCount(0);
	await rows(page).first().locator('.mailspur-open').click();
	await expect(page.locator('#mailspur-d-meta')).toContainText('Held by emergency brake – released later.');
	await expect(page.locator('[data-module-action="delivery-release"]')).toBeHidden();
	await page.keyboard.press('Escape');

	// Clean up: user and settings (log entries are removed in afterAll).
	await page.evaluate(async () => {
		const cfg = window.mailspurConfig;
		const users = cfg.restUrl.replace('mailspur-email-log/v1', 'wp/v2/users');
		const join = users.includes('?') ? '&' : '?';
		const found = await (await fetch(users + join + 'search=brakeflood', { headers: { 'X-WP-Nonce': cfg.nonce } })).json();
		for (const user of found) {
			await fetch(users.replace('wp/v2/users', 'wp/v2/users/' + user.id) + join + 'force=true&reassign=1', { method: 'DELETE', headers: { 'X-WP-Nonce': cfg.nonce } });
		}
	});
	await setBrake(page, 'alert', '');
	expect(errors).toEqual([]);
});

test('sender check runs on demand and shows a traffic light per record', async ({ page }) => {
	const errors = watch(page);
	await page.goto(SETTINGS);
	await expect(page.locator('#mailspur-sender-check')).toBeVisible();

	await page.locator('#mailspur-dkim-selector').fill('bad selector!');
	await page.locator('#mailspur-delivery-run').click();
	await expect(page.locator('#mailspur-delivery-state')).toHaveText('A DKIM selector may only contain letters, digits, dots, hyphens and underscores.');

	await page.locator('#mailspur-dkim-selector').fill('mysel');
	await page.locator('#mailspur-delivery-run').click();
	await expect(page.locator('#mailspur-delivery-run')).toHaveText('Check again', { timeout: 45_000 });
	await expect(page.locator('#mailspur-delivery-run')).toBeEnabled();

	// DNS in Playground is not reliable: either per-domain results or a note (unavailable / no domain).
	const domains = page.locator('.mailspur-delivery-domain');
	const notes = page.locator('.mailspur-delivery-note');
	await expect(domains.or(notes).first()).toBeVisible();
	const count = await domains.count();
	for (let i = 0; i < count; i++) {
		await expect(domains.nth(i).locator('.mailspur-delivery-item')).toHaveCount(4);
		await expect(domains.nth(i).locator('.mailspur-light[aria-label]')).toHaveCount(4);
	}
	expect(errors).toEqual([]);
});
