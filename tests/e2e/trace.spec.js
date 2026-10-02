// Trace module in a real browser: the "Trace" dialog tab, the .eml download and the settings.
// Uses the core seed (tests/e2e/seed.php) read-only; SMTP transcripts and imported entries are simulated by
// rewriting the detail response, so no extra log entries change the counts other specs expect.
import fs from 'node:fs';
import { expect, test } from '@playwright/test';

const LOG = '/wp-admin/admin.php?page=mailspur-email-log';
const rows = (page) => page.locator('#mailspur-rows tr[data-id]');
const DETAIL = /\/mailspur-email-log\/v1\/mails\/\d+(?:\?|$)/;

function watchErrors(page) {
	const errors = [];
	page.on('console', (msg) => {
		if (msg.type() === 'error' && !/Content Security Policy|about:srcdoc/.test(msg.text())) errors.push(msg.text());
	});
	page.on('pageerror', (err) => errors.push(err.message));
	return errors;
}

async function openMail(page, search) {
	await page.goto(`${LOG}&s=${encodeURIComponent(search)}`);
	await expect(rows(page)).toHaveCount(1);
	await rows(page).first().locator('.mailspur-open').click();
	await expect(page.locator('#mailspur-dialog')).toBeVisible();
}

/** Rewrites the detail payload of the next opened mail. */
async function patchDetail(page, patch) {
	await page.route(DETAIL, async (route) => {
		const response = await route.fetch();
		const json = await response.json();
		await route.fulfill({ response, json: patch(json) });
	});
}

test.describe.configure({ mode: 'serial' });

test('trace tab shows timeline, API delivery, origin and request', async ({ page }) => {
	const errors = watchErrors(page);
	await openMail(page, 'Password reset');

	await page.getByRole('tab', { name: 'Trace' }).click();
	const body = page.locator('#mailspur-d-body');
	await expect(body.locator('.mailspur-trace-bar .mailspur-trace-seg')).toHaveCount(1);
	await expect(body).toContainText(/Total: [\d.,]+ ms/);
	await expect(body.locator('.mailspur-trace-phases li')).toHaveCount(2);

	// The seed delivers through a pre_wp_mail closure in seed.php.
	await expect(body.locator('.mailspur-trace-api')).toHaveText('Delivered by seed.php (API)');
	await expect(body).toContainText('…/seed.php');
	await expect(body).toContainText('Origin');
	await expect(body).toContainText('Request');
	await expect(body.locator('.mailspur-trace-raw')).toContainText('rebuilds the message');
	// Not an SMTP mail – no transcript section.
	await expect(body).not.toContainText('SMTP transcript');

	// Arrow keys move between the dialog tabs, including the module tab.
	await page.getByRole('tab', { name: 'Trace' }).focus();
	await page.keyboard.press('ArrowLeft');
	await expect(page.getByRole('tab', { name: 'Headers' })).toHaveAttribute('aria-selected', 'true');
	expect(errors).toEqual([]);
});

test('failed PHPMailer mail shows transport and phases', async ({ page }) => {
	await openMail(page, 'weekly newsletter');
	await page.getByRole('tab', { name: 'Trace' }).click();
	const body = page.locator('#mailspur-d-body');
	await expect(body.locator('.mailspur-trace-seg')).toHaveCount(2);
	await expect(body.locator('.mailspur-trace-phases li')).toHaveCount(3);
	await expect(body.locator('.mailspur-trace-table').first()).toContainText('PHP mail()');
	await expect(body).toContainText('Not recorded: this email was not sent via SMTP.');
});

test('SMTP transcript is shown as text and can be copied', async ({ page, context }) => {
	await context.grantPermissions(['clipboard-read', 'clipboard-write']);
	const errors = watchErrors(page);
	const transcript = 'SERVER -> CLIENT: 220 mail.example.com\nCLIENT -> SERVER: AUTH LOGIN\nSERVER -> CLIENT: 334 [hidden]\n<img src=x onerror=alert(1)>';
	await patchDetail(page, (mail) => {
		mail.meta.trace.transport = { mailer: 'smtp', host: 'smtp.example.com', port: 587, secure: 'tls', auth: true, auto_tls: true, user: 'j***@example.com' };
		mail.meta.trace.transcript = transcript;
		mail.meta.trace.transcript_status = 'stored';
		return mail;
	});
	await openMail(page, 'Password reset');
	await page.getByRole('tab', { name: 'Trace' }).click();

	const body = page.locator('#mailspur-d-body');
	await expect(body.locator('.mailspur-trace-table').first()).toContainText('smtp.example.com');
	await expect(body.locator('.mailspur-trace-table').first()).toContainText('j***@example.com');
	await expect(body.locator('.mailspur-trace-table').first()).toContainText('TLS');
	const pre = body.locator('pre.mailspur-trace-transcript');
	await expect(pre).toHaveText(transcript);
	await expect(body.locator('img')).toHaveCount(0); // Text only, never markup.

	await body.getByRole('button', { name: 'Copy' }).click();
	await expect(page.locator('#mailspur-toast')).toHaveText('Transcript copied.');
	// The Windows clipboard stores CRLF line breaks.
	expect((await page.evaluate(() => navigator.clipboard.readText())).replace(/\r\n/g, '\n')).toBe(transcript);
	expect(errors).toEqual([]);
});

test('entries without trace show a friendly note', async ({ page }) => {
	await patchDetail(page, (mail) => {
		delete mail.meta.trace;
		mail.source = 'import:wp-mail-logging';
		return mail;
	});
	await openMail(page, 'Password reset');
	await page.getByRole('tab', { name: 'Trace' }).click();
	await expect(page.locator('#mailspur-d-body .mailspur-trace-empty')).toHaveText('Imported entries have no trace: the other plugin did not record these details.');

	await page.unroute(DETAIL);
	await patchDetail(page, (mail) => {
		mail.meta = [];
		return mail;
	});
	await page.keyboard.press('Escape');
	await openMail(page, 'weekly newsletter');
	await page.getByRole('tab', { name: 'Trace' }).click();
	await expect(page.locator('#mailspur-d-body .mailspur-trace-empty')).toContainText('No trace is available for this entry.');
});

test('download .eml rebuilds the message', async ({ page }) => {
	await openMail(page, 'Password reset');
	const id = await page.locator('#mailspur-rows tr.is-current').getAttribute('data-id');

	const [download] = await Promise.all([page.waitForEvent('download'), page.locator('#mailspur-dialog [data-module-action="trace-eml"]').click()]);
	expect(download.suggestedFilename()).toBe(`mailspur-${id}.eml`);
	const eml = fs.readFileSync(await download.path(), 'utf8');
	expect(eml).toContain('Subject: Password reset');
	expect(eml).toContain('X-Mailspur-Reconstructed: yes');
	expect(eml).toContain('key=[redacted]');
	expect(eml).not.toContain('AbCdEf123');
	await expect(page.locator('#mailspur-toast')).toHaveText('Download started.');

	// Without the REST nonce (e.g. a link from elsewhere) the download is refused.
	const res = await page.request.get(`/wp-json/mailspur-email-log/v1/mails/${id}/eml`);
	expect(res.status()).toBe(401);
});

test('trace settings: transcript mode and raw source only with acknowledgement', async ({ page }) => {
	await page.goto(`${LOG}&tab=settings`);
	const mode = (value) => page.locator(`input[name="mailspur_settings[trace_transcript]"][value="${value}"]`);
	const raw = page.locator('#mailspur-trace-raw');
	const ack = page.locator('#mailspur-trace-raw-ack');
	const save = () => page.getByRole('button', { name: 'Save Changes' }).click();

	await expect(page.getByRole('heading', { name: 'Trace' })).toBeVisible();
	await expect(mode('failed')).toBeChecked();
	await expect(raw).not.toBeChecked();

	await mode('always').check();
	await raw.check();
	await save();
	await expect(mode('always')).toBeChecked();
	await expect(raw).not.toBeChecked(); // Not acknowledged.

	await raw.check();
	await ack.check();
	await save();
	await expect(raw).toBeChecked();
	await expect(ack).toBeChecked();

	// Back to the defaults for the other specs.
	await mode('failed').check();
	await raw.uncheck();
	await ack.uncheck();
	await save();
	await expect(mode('failed')).toBeChecked();
	await expect(raw).not.toBeChecked();
});
