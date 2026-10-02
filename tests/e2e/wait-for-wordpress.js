// Playwright globalSetup: the Playground server opens its port (and even answers requests) before the
// blueprint has activated the plugin and seeded the log. tests/e2e/seed.php writes .cache/e2e-out/seeded last.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const marker = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', '..', '.cache', 'e2e-out', 'seeded');

export default async function waitForWordPress() {
	const deadline = Date.now() + 5 * 60_000;
	while (Date.now() < deadline) {
		if (fs.existsSync(marker)) return;
		await new Promise((resolve) => setTimeout(resolve, 500));
	}
	throw new Error('Playground did not finish the blueprint (no .cache/e2e-out/seeded).');
}
