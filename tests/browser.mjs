// SPDX-License-Identifier: AGPL-3.0-or-later
// Optional real-browser check against the disposable public preview.
import fs from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
const root = process.cwd();
const { chromium } = await import(pathToFileURL(path.join(root, '.dev/browser/node_modules/playwright/index.mjs')));
const origin = (await fs.readFile(path.join(root, '.dev/public-url'), 'utf8')).trim();
const account = JSON.parse(await fs.readFile(path.join(root, '.dev/ui-smoke.json'), 'utf8'));
let browser;
let stage = 'launch';
try {
    browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined, headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    stage = 'login page';
    await page.goto(`${origin}/admin/login`, { waitUntil: 'networkidle' });
    await page.screenshot({ path: path.join(root, '.dev/login-smoke.png'), fullPage: true });
    stage = 'login email';
    await page.locator('[id="form.login"]').fill(account.email);
    stage = 'login password';
    await page.locator('[id="form.password"]').fill(account.password);
    stage = 'sign in';
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 30000 });
    stage = 'creation controls';
    await page.goto(`${origin}/admin/servers/create`, { waitUntil: 'networkidle' });
    const wizardSteps = [...(await page.content()).matchAll(/fi-sc-wizard-header-step-label">([^<]+)</g)].map((m) => m[1]);
    if (wizardSteps.at(-1) !== 'MC Router') {
        throw new Error(`MC Router is not the final creation step: ${wizardSteps.join(', ')}`);
    }
    stage = 'edit tab';
    await page.goto(`${origin}/admin/servers/1/edit?tab=mc-router%3A%3Adata%3A%3Atab`, { waitUntil: 'networkidle' });
    await page.getByRole('tab', { name: 'MC Router', exact: true }).click();
    await page.getByRole('switch').first().waitFor();
    await page.screenshot({ path: path.join(root, '.dev/edit-tab.png'), fullPage: true });
    stage = 'plugin settings';
    await page.goto(`${origin}/admin/plugins`, { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Settings', exact: true }).first().click();
    await page.getByLabel(/Hostname suffix/).waitFor();
    await page.screenshot({ path: path.join(root, '.dev/plugin-settings.png'), fullPage: true });
    console.log('OK: public browser login, native creation controls, native edit tab and plugin settings');
} catch (error) {
    if (stage === 'launch') console.error(error.message);
    // Never print Playwright action logs: failed fill() calls can include passwords.
    console.error(`Browser smoke test failed at ${stage}; review local screenshots/locators without displaying credentials.`);
    process.exitCode = 1;
} finally {
    await browser?.close();
}
