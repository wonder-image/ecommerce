// Real app Resource renderers and backend country JS; CLI fixture never bypasses web auth.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '/Users/andreamarinoni/.cache/wonder-tooling/playwright/node_modules/playwright-core');
const site = process.env.WI_TEST_SITE || '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';
const siteUrl = process.env.WI_TEST_URL || 'https://ecommerce.test';
const lib = path.resolve(__dirname, '../../../lib');
(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const context = await browser.newContext({ ignoreHTTPSErrors: true });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent(execFileSync('php', [path.join(__dirname, 'account-address-preview.php'), 'backend'], { encoding: 'utf8' }));
        for (const file of ['lib/bootstrap/bootstrap.css', 'backend/head.css']) {
            await page.addStyleTag({ content: fs.readFileSync(path.join(site, 'assets/lib/wonder-image/dist', file), 'utf8') });
        }
        await page.addScriptTag({ content: fs.readFileSync(path.join(site, 'assets/lib/wonder-image/dist/lib/jquery/jquery.js'), 'utf8') });
        await page.addScriptTag({ content: 'const pathApi = "' + siteUrl + '/api"; function ajaxRequestError() { window.stateError = true; }' });
        await page.route(`${siteUrl}/api/states/`, async route => {
            const response = await context.request.post(`${siteUrl}/api/states/`, { form: Object.fromEntries(new URLSearchParams(route.request().postData())) });
            await route.fulfill({ status: response.status(), contentType: 'application/json', body: await response.body() });
        });
        await page.addScriptTag({ content: fs.readFileSync(path.join(lib, 'src/build/backend/js/form/place.js'), 'utf8') });
        await page.evaluate(() => document.querySelector('[name="country"]').addEventListener('change', event => searchStates(event.target)));
        assert.equal(await page.locator('[name="province"]').inputValue(), 'BE');
        assert.equal(await page.locator('[name="label"]').getAttribute('required'), null);
        await page.selectOption('[name="country"]', 'IT');
        await page.waitForFunction(() => document.querySelector('[name="province"]').dataset.wiListStates === 'IT');
        assert.equal(await page.locator('[name="province"]').inputValue(), '');
        await page.selectOption('[name="province"]', 'BG');
        await page.evaluate(() => searchStates(document.querySelector('[name="country"]')));
        assert.equal(await page.locator('[name="province"]').inputValue(), 'BG');
        for (const width of [1280, 768, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Backend grid must not overflow');
        }
        assert.deepEqual(errors, []);
        console.log('Backend Resource: country/province, optional label, Bootstrap and responsive grid OK');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
