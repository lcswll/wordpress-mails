// Statistics tab, dashboard widget and alert settings in a real browser (existing seed: 63 mails today, 1 failed).
// Read-only towards the log: other specs assert exact counts, so nothing here sends or deletes mails.
import { expect, test } from '@playwright/test';

const LOG = '/wp-admin/admin.php?page=mailspur-email-log';
const STATS = `${LOG}&tab=stats`;

function watch(page) {
	const errors = [];
	const external = [];
	page.on('console', (msg) => {
		// The preview's CSP/sandbox reports blocked content – that is the protection working, not an error.
		if (msg.type() === 'error' && !/Content Security Policy|about:srcdoc/.test(msg.text())) errors.push(msg.text());
	});
	page.on('pageerror', (err) => errors.push(err.message));
	// Everything the plugin renders is local; WordPress core itself may load Gravatar in the admin bar.
	page.on('request', (req) => {
		const url = new URL(req.url());
		if (url.protocol.startsWith('http') && !['127.0.0.1', 'localhost'].includes(url.hostname) && !url.hostname.endsWith('gravatar.com')) {
			external.push(req.url());
		}
	});
	return { errors, external };
}

test.describe.configure({ mode: 'serial' });

test('statistics tab shows KPIs, charts and top lists', async ({ page }) => {
	const seen = watch(page);
	await page.goto(STATS);

	await expect(page.locator('.mailspur-nav a.is-active')).toHaveText('Statistics');
	const tiles = page.locator('#msi-kpis .msi-kpi');
	await expect(tiles).toHaveCount(5);
	await expect(tiles.nth(0).locator('.msi-kpi-value')).toHaveText('63');
	await expect(tiles.nth(1).locator('.msi-kpi-value')).toHaveText('1');
	await expect(tiles.nth(1)).toContainText(/1\.6\s%\sof all emails/);
	await expect(page.locator('[data-range="30"]')).toHaveAttribute('aria-pressed', 'true');

	// Charts: stacked columns, failure-rate line, heatmap – each with a table view.
	await expect(page.locator('#msi-volume svg path').first()).toBeVisible();
	await expect(page.locator('#msi-volume-legend li')).toHaveCount(4);
	await expect(page.locator('#msi-rate .msi-line')).toHaveCount(1);
	await expect(page.locator('#msi-heat rect.msi-cell')).toHaveCount(7 * 24);
	await expect(page.locator('.msi-table summary').first()).toHaveText('Show data as table');
	await page.locator('#msi-volume').locator('..').locator('summary').click();
	await expect(page.locator('#msi-volume').locator('..').locator('table tbody tr')).toHaveCount(30);

	// Top lists: the 60 order mails are grouped into one subject, the source is WordPress core.
	await expect(page.locator('#msi-subjects li').first()).toContainText('[Example Shop] Your order #… has been received');
	await expect(page.locator('#msi-subjects li').first().locator('.msi-bar-value')).toHaveText('60');
	await expect(page.locator('#msi-domains li').first()).toContainText('example.com');
	await expect(page.locator('#msi-sources li').first()).toContainText('WordPress');
	// Source bars open the log filtered by that sender (source filter of the log).
	await expect(page.locator('#msi-sources li a').first()).toHaveAttribute('href', /[?&]source=core(&|$)/);
	// The XSS subject is plain text.
	await expect(page.locator('#msi-subjects img')).toHaveCount(0);

	await expect(page.locator('.msi-history')).toContainText('Alerts are off.');
	expect(seen.errors).toEqual([]);
	expect(seen.external).toEqual([]);
});

test('hover and keyboard show tooltips, Enter opens the log for that day', async ({ page }) => {
	await page.goto(STATS);
	const plot = page.locator('#msi-volume .msi-plot');
	await expect(plot).toBeVisible();

	const box = await plot.locator('svg').boundingBox();
	await page.mouse.move(box.x + box.width - 12, box.y + box.height / 2);
	await expect(page.locator('#msi-tooltip')).toBeVisible();
	await expect(page.locator('#msi-tooltip')).toContainText('63');

	await page.mouse.move(0, 0);
	await plot.focus();
	await expect(page.locator('#msi-tooltip')).toContainText('Total');
	await page.keyboard.press('ArrowLeft');
	await page.keyboard.press('End');
	await page.keyboard.press('Enter');
	await expect(page).toHaveURL(/page=mailspur-email-log.*[?&]after=\d{4}-\d{2}-\d{2}/);
	await expect(page).toHaveURL(/[?&]before=\d{4}-\d{2}-\d{2}/);
	await expect(page).not.toHaveURL(/tab=stats/);
	await expect(page.locator('#mailspur-rows tr[data-id]')).toHaveCount(25, { timeout: 20_000 });

	// Heatmap cells respond to the keyboard too.
	await page.goto(STATS);
	await page.locator('#msi-heat .msi-plot').focus();
	await page.keyboard.press('ArrowRight');
	await expect(page.locator('#msi-heat rect.is-active')).toHaveCount(1);
	await expect(page.locator('#msi-tooltip')).toBeVisible();
});

test('range presets, custom range and links into the log', async ({ page }) => {
	await page.goto(STATS);
	await page.locator('[data-range="7"]').click();
	await expect(page).toHaveURL(/range=7/);
	await expect(page.locator('[data-range="7"]')).toHaveAttribute('aria-pressed', 'true');
	await expect(page.locator('#msi-volume').locator('..').locator('table tbody tr')).toHaveCount(7);

	await page.locator('[data-range="365"]').click();
	// Long ranges switch to weekly buckets.
	await expect(page.locator('#msi-volume').locator('..').locator('table thead th').first()).toHaveText('Week');

	await page.locator('[data-range="custom"]').click();
	await expect(page.locator('#msi-custom')).toBeVisible();
	await page.locator('#msi-from').fill('2020-01-01');
	await page.locator('#msi-to').fill('2020-01-10');
	await page.locator('#msi-custom button').click();
	await expect(page).toHaveURL(/range=custom&from=2020-01-01&to=2020-01-10/);
	await expect(page.locator('#msi-kpis .msi-kpi').first().locator('.msi-kpi-value')).toHaveText('0');
	await expect(page.locator('#msi-volume .msi-empty')).toHaveText('No emails in this period.');

	// The custom range survives a reload.
	await page.reload();
	await expect(page.locator('#msi-from')).toHaveValue('2020-01-01');

	await page.goto(STATS);
	await page.locator('#msi-subjects a').first().click();
	await expect(page).toHaveURL(/[?&]s=%5BExample\+Shop%5D\+Your\+order\+%23/);
	await expect(page.locator('#mailspur-rows tr[data-id]')).toHaveCount(25, { timeout: 20_000 });
	await expect(page.locator('[data-count="all"]')).toHaveText('60');
});

test('dashboard widget links to failed emails', async ({ page }) => {
	const seen = watch(page);
	await page.goto('/wp-admin/');
	const widget = page.locator('#mailspur_insights_widget');
	await expect(widget).toBeVisible();
	await expect(widget.locator('h2')).toContainText('Emails – last 7 days');
	await expect(widget.locator('.msi-widget-value').first()).toHaveText('63');
	await expect(widget.locator('svg.msi-widget-chart g')).toHaveCount(7);
	await widget.getByRole('link', { name: 'View 1 failed emails' }).click();
	await expect(page.locator('#mailspur-rows tr[data-id]')).toHaveCount(1, { timeout: 20_000 });
	await expect(page.locator('[data-status="failed"]')).toHaveAttribute('aria-pressed', 'true');
	expect(seen.errors).toEqual([]);
});

test('alert settings: https-only webhook, test alert, opt-in note', async ({ page }) => {
	await page.goto(`${LOG}&tab=settings`);
	const section = page.locator('#mailspur-alerts');
	await expect(section).toHaveText('Monitoring alerts');
	await expect(page.locator('.mailspur-alerts-intro')).toContainText('off by default');
	await expect(page.locator('input[name="mailspur_settings[alert_failures]"]')).not.toBeChecked();
	await expect(page.locator('input[name="mailspur_settings[alert_silence]"]')).not.toBeChecked();

	// Plain http is rejected server-side (browser validation bypassed like a crafted request would).
	await page.locator('#mailspur-alert-webhook').evaluate((el) => el.setAttribute('type', 'text'));
	await page.locator('#mailspur-alert-webhook').fill('http://hooks.example/insecure');
	await page.getByRole('button', { name: 'Save Changes' }).click();
	await expect(page.locator('#setting-error-mailspur_alert_webhook')).toContainText('only valid https URLs');
	await expect(page.locator('#mailspur-alert-webhook')).toHaveValue('');
	await page.waitForLoadState('load'); // Deferred scripts attach their handlers after parsing.

	// The test alert goes through REST; mocked here so no mail is logged (other specs count the log).
	await page.route(/alerts(%2F|\/)test/, (route) =>
		route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ email: true, webhook: 200 }) }),
	);
	await page.getByRole('button', { name: 'Send test alert' }).click();
	await expect(page.locator('#mailspur-test-result')).toHaveText('Email: sent · Webhook: HTTP 200');

	await page.unroute(/alerts(%2F|\/)test/);
	await page.route(/alerts(%2F|\/)test/, (route) =>
		route.fulfill({ status: 400, contentType: 'application/json', body: JSON.stringify({ code: 'mailspur_no_channel', message: 'Enter an email address or a webhook URL and save the settings first.' }) }),
	);
	await page.getByRole('button', { name: 'Send test alert' }).click();
	await expect(page.locator('#mailspur-test-result')).toContainText('save the settings first');
	await expect(page.locator('#mailspur-test-result')).toHaveClass(/is-error/);
});

test('statistics tab has no horizontal scrolling on mobile', async ({ page }) => {
	await page.setViewportSize({ width: 375, height: 812 });
	await page.goto(STATS);
	await expect(page.locator('#msi-heat rect.msi-cell').first()).toBeVisible();
	const overflow = await page.evaluate(() => {
		const el = document.querySelector('.mailspur-insights');
		return el.scrollWidth - el.clientWidth;
	});
	expect(overflow).toBeLessThanOrEqual(0);
});
