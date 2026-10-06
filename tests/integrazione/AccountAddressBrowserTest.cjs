// Uses real PHP renderers and lib styles/scripts; no user or session is created.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '/Users/andreamarinoni/.cache/wonder-tooling/playwright/node_modules/playwright-core');
const site = process.env.WI_TEST_SITE || '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';
const lib = path.resolve(__dirname, '../../../lib');
(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const context = await browser.newContext({ ignoreHTTPSErrors: true });
        const errors = [];
        for (const form of ['billing', 'shipping', 'modal']) {
            const page = await context.newPage();
            page.on('pageerror', error => errors.push(error.message));
            const html = execFileSync('php', [path.join(__dirname, 'account-address-preview.php'), form], { encoding: 'utf8' });
            await page.setContent(html);
            if (form !== 'modal') assert.equal(await page.locator('[name="country"]').isVisible(), true, 'Native select remains usable without JS');
            await page.addStyleTag({ content: fs.readFileSync(path.join(site, 'assets/lib/wonder-image/dist/frontend/lib.css'), 'utf8') });
            const login = await context.request.get('https://ecommerce.test/account/auth/login/');
            const styles = (await login.text()).matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g);
            for (const style of styles) { await page.addStyleTag({ content: style[1] }); }
            await page.addStyleTag({ content: fs.readFileSync(path.join(site, 'assets/lib/wonder-image/dist/lib/bootstrap/bootstrap-icons.css'), 'utf8') });
            await page.addStyleTag({ content: '@font-face { font-family: bootstrap-icons; src: url("https://ecommerce.test/assets/lib/wonder-image/dist/fonts/bootstrap-icons.woff2") format("woff2"); }' });
            await page.addScriptTag({ content: 'function check() {} function ajaxRequestError() { window.stateError = true; } const pathApi = "https://ecommerce.test/api";' });
            await page.addScriptTag({ content: fs.readFileSync(path.join(site, 'assets/lib/wonder-image/dist/lib/jquery/jquery.js'), 'utf8') });
            await page.route('https://ecommerce.test/api/states/', async route => {
                const response = await context.request.post('https://ecommerce.test/api/states/', { form: Object.fromEntries(new URLSearchParams(route.request().postData())) });
                await route.fulfill({ status: response.status(), contentType: 'application/json', body: await response.body() });
            });
            await page.addScriptTag({ content: fs.readFileSync(path.join(lib, 'src/build/frontend/js/form/select.js'), 'utf8') });
            await page.addScriptTag({ content: fs.readFileSync(path.join(lib, 'src/build/frontend/js/form/place.js'), 'utf8') });
            await page.addScriptTag({ content: fs.readFileSync(path.join(lib, 'src/build/global/js/form/conditional.js'), 'utf8') });
            await page.evaluate(() => { setInputCountry(); setInputCountry(); setConditional(); });
            if (form === 'modal') {
                await page.addStyleTag({ content: fs.readFileSync(path.join(site, 'assets/0.0/css/header-layouts.css'), 'utf8') });
                await page.evaluate(() => {
                    const header = document.createElement('header');
                    header.className = 'site-header';
                    header.style.height = '100px';
                    document.body.prepend(header);
                });
                await page.addScriptTag({ content: 'function disableScroll() {} function enableScroll() {}' });
                await page.addScriptTag({ content: fs.readFileSync(path.join(lib, 'src/build/frontend/js/modal.js'), 'utf8') });
                assert.equal(await page.locator('#preview-address').evaluate(el => el.inert), true);
                await page.locator('#open-address').click();
                await page.waitForFunction(() => document.querySelector('#preview-address').classList.contains('wi-show'));
                assert.equal(await page.evaluate(() => document.elementFromPoint(5, 5).closest('.wi-modal')?.id), 'preview-address', 'Modal backdrop must cover the header');
                assert.equal(await page.locator('[name="label"]').getAttribute('required'), null);
                assert.equal(await page.locator('form').getAttribute('action'), '/account/shipping-addresses/new/');
                assert.equal(await page.locator('[name="csrf_token"]').inputValue(), 'test-token');
                const footer = page.locator('#preview-address .wi-modal-footer');
                assert.deepEqual(await footer.locator('button').allTextContents(), ['Annulla', 'Salva modifiche']);
                assert.equal(await page.locator('form button[type="submit"], form .wi-close-modal, form a').count(), 0, 'Modal actions belong outside the body form');
                assert.equal(await footer.locator('[type="submit"]').evaluate(el => el.form.id), await page.locator('form').getAttribute('id'));
                await page.evaluate(() => document.querySelector('form').addEventListener('submit', event => {
                    event.preventDefault();
                    window.previewSubmission = { csrf: new FormData(event.target).get('csrf_token'), external: !event.target.contains(event.submitter) };
                }));
                await footer.locator('[type="submit"]').click();
                assert.deepEqual(await page.evaluate(() => window.previewSubmission), { csrf: 'test-token', external: true });
                await footer.locator('[type="button"]').click();
                assert.equal(await page.locator('#preview-address').evaluate(el => el.inert), true);
                assert.equal(await page.locator('#open-address').evaluate(el => el === document.activeElement), true);
                await page.locator('#open-address').click();
                assert.equal(await page.locator('#preview-address').evaluate(el => el.contains(document.activeElement)), true);
                await page.keyboard.press('Shift+Tab');
                assert.equal(await page.locator('#preview-address').evaluate(el => el.contains(document.activeElement)), true, 'Tab must remain in the dialog');
                await page.keyboard.press('Escape');
                assert.equal(await page.locator('#preview-address').evaluate(el => el.inert), true);
                assert.equal(await page.locator('#open-address').evaluate(el => el === document.activeElement), true);
                await page.evaluate(() => { modal('#preview-address'); setUpModal('#preview-address'); setUpModal('#preview-address'); });
                assert.equal(await page.locator('#preview-address').evaluate(el => el.inert), false, 'Legacy modal API must still open');
                await page.evaluate(() => {
                    const second = document.createElement('section');
                    second.id = 'second-modal';
                    second.className = 'wi-modal no-interaction';
                    second.innerHTML = '<div class="content"><button type="button" class="wi-close-modal">Close</button></div>';
                    document.body.appendChild(second);
                    modal('#second-modal');
                });
                assert.equal(await page.locator('#preview-address').evaluate(el => el.inert), true, 'Previous stacked dialog must be suspended');
                await page.keyboard.press('Escape');
                assert.equal(await page.locator('#second-modal').evaluate(el => el.inert), true);
                assert.equal(await page.locator('#preview-address').evaluate(el => el.inert), false);
                assert.equal(await page.evaluate(() => modal('#missing-modal')), false);
                await page.locator('#second-modal').evaluate(el => el.remove());
            }
            if (form === 'billing') {
                assert.equal(await page.locator('[name="business_name"]').isVisible(), false);
                await page.locator('[name="type"]').locator('..').locator('label').click();
                await page.keyboard.press('End');
                await page.keyboard.press('Enter');
                assert.equal(await page.locator('[name="business_name"]').isVisible(), true);
                await page.locator('[name="type"]').locator('..').locator('label').click();
                await page.keyboard.press('Home');
                await page.keyboard.press('Enter');
                assert.equal(await page.locator('[name="business_name"]').isVisible(), false);
                assert.equal(await page.locator('[name="business_name"]').locator('..').locator('..').isVisible(), false, 'the whole grid cell must disappear');
            }
            await page.locator('[name="country"]').dispatchEvent('focusout');
            assert.equal(await page.locator('[name="province"]').inputValue(), 'BG', 'focusout must preserve the selected province');
            const country = page.locator('[name="country"]').locator('..');
            await country.locator('label').click();
            const countrySearch = country.locator('.select-items input[role="combobox"]');
            assert.equal(await countrySearch.evaluate(el => el === document.activeElement), true, 'Label must activate and focus search');
            await countrySearch.fill('deutsch');
            assert.equal(await country.locator('[role="option"]:visible').count(), 1);
            assert.equal(await countrySearch.getAttribute('type'), 'text', 'No browser-specific search cancel icon');
            assert.equal(await country.locator('[data-wi-select-clear] .bi-x-lg').count(), 1);
            assert.equal(await country.locator('[data-wi-select-clear]').isVisible(), true);
            assert.notEqual(await country.locator('.bi-x-lg').evaluate(el => getComputedStyle(el, '::before').content), 'none');
            const iconBox = await country.locator('.bi-x-lg').boundingBox();
            assert.ok(iconBox.width > 5 && iconBox.height > 5, 'Bootstrap clear icon must occupy visible space');
            await country.locator('[data-wi-select-clear]').click();
            assert.equal(await countrySearch.inputValue(), '');
            assert.equal(await countrySearch.evaluate(el => el === document.activeElement), true);
            assert.ok(await country.locator('[role="option"]:visible').count() > 1);
            assert.equal(await page.locator('[name="country"]').inputValue(), 'IT', 'Clearing search must not change the saved value');
            await countrySearch.fill('deutsch');
            await page.keyboard.press('Enter');
            assert.equal(await page.locator('[name="country"]').inputValue(), 'DE');
            await page.waitForFunction(() => document.querySelector('[name="province"]').querySelector('option[value="BE"]'));
            assert.equal(await page.locator('[name="province"]').inputValue(), '');
            const province = page.locator('[name="province"]').locator('..');
            const emptyCountryBox = await country.locator('.select-selected').boundingBox();
            const emptyProvinceBox = await province.locator('.select-selected').boundingBox();
            assert.ok(Math.abs(emptyCountryBox.height - emptyProvinceBox.height) < 2, `Empty province height ${emptyProvinceBox.height} must equal country ${emptyCountryBox.height}`);
            assert.ok(Math.abs(emptyCountryBox.y - emptyProvinceBox.y) < 2, 'Country and empty province controls must align');
            await page.evaluate(() => {
                ['country', 'province'].forEach(name => document.querySelector(`[name="${name}"]`).parentElement.classList.add('wi-nf'));
            });
            const staticCountryBox = await country.locator('.select-selected').boundingBox();
            const staticProvinceBox = await province.locator('.select-selected').boundingBox();
            assert.ok(Math.abs(staticCountryBox.height - staticProvinceBox.height) < 2, 'No-floating selects retain equal heights');
            await page.evaluate(() => {
                ['country', 'province'].forEach(name => document.querySelector(`[name="${name}"]`).parentElement.classList.remove('wi-nf'));
            });
            await province.locator('label').click();
            await province.locator('.select-items input[role="combobox"]').fill('berl');
            await page.keyboard.press('Escape');
            assert.equal(await province.locator('.select-selected').evaluate(el => el === document.activeElement), true);
            if (form === 'modal') assert.equal(await page.locator('#preview-address').evaluate(el => !el.inert), true, 'First Esc closes only the select');
            await page.keyboard.press('ArrowDown');
            await province.locator('.select-items input[role="combobox"]').fill('berl');
            await page.keyboard.press('Enter');
            assert.equal(await page.locator('[name="province"]').inputValue(), 'BE', 'updated custom select must be interactive');
            if (process.env.WI_UX_SCREENSHOT_DIR) {
                await province.locator('label').click();
                await province.locator('.select-items input[role="combobox"]').fill('berl');
                await page.evaluate(() => document.fonts.ready);
                await page.waitForTimeout(350);
                await page.screenshot({ path: path.join(process.env.WI_UX_SCREENSHOT_DIR, `${form}-select.png`), fullPage: true });
                await page.keyboard.press('Escape');
            }
            await page.selectOption('[name="country"]', 'IT', { force: true });
            await page.waitForFunction(() => document.querySelector('[name="province"]').querySelector('option[value="BG"]'));
            for (const width of [1280, 768, 390]) {
                await page.setViewportSize({ width, height: 1100 });
                const layout = await page.evaluate(() => {
                    const grid = document.querySelector('form > .d-grid');
                    const cells = Array.from(grid.children).filter(el => getComputedStyle(el).display !== 'none');
                    return {
                        overflow: document.documentElement.scrollWidth > window.innerWidth,
                        widths: cells.map(el => {
                            const cell = el.getBoundingClientRect();
                            const input = el.querySelector('.wi-input-container').getBoundingClientRect();
                            const control = el.querySelector('.select-selected')?.getBoundingClientRect();
                            return Math.max(Math.abs(cell.width - input.width), control ? Math.abs(input.width - control.width) : 0);
                        }),
                        columns: getComputedStyle(grid).gridTemplateColumns.split(' ').length,
                    };
                });
                assert.equal(layout.overflow, false, `${form} overflow at ${width}`);
                assert.ok(layout.widths.every(delta => delta < 2), `${form} shrunk fields at ${width}: ${layout.widths}`);
                assert.equal(layout.columns, width <= 768 ? 4 : 12);
                if (form === 'modal') {
                    const boxes = await page.evaluate(() => {
                        const rect = selector => {
                            const r = document.querySelector(selector).getBoundingClientRect();
                            return { x: r.x, y: r.y, right: r.right, bottom: r.bottom };
                        };
                        return {
                            title: rect('.wi-modal-title'), close: rect('.wi-modal-close'), header: rect('.wi-modal-header'),
                            cancel: rect('.wi-modal-footer [type="button"]'), save: rect('.wi-modal-footer [type="submit"]'), footer: rect('.wi-modal-footer'),
                        };
                    });
                    assert.ok(boxes.close.x >= boxes.title.right, `Close icon on the right at ${width}`);
                    assert.ok(Math.abs(boxes.header.right - boxes.close.right - 16) < 2);
                    assert.ok(boxes.cancel.right <= boxes.save.x, 'Cancel precedes Save');
                    assert.ok(Math.abs(boxes.cancel.y - boxes.save.y) < 2, 'Actions share a row');
                    assert.ok(Math.abs(boxes.footer.right - boxes.save.right - 16) < 2, `Footer actions aligned right at ${width}`);
                    assert.ok(boxes.save.bottom < 1100, 'Footer remains in the viewport');
                }
                if (width > 768) {
                    for (const pair of [['country', 'province'], ['city', 'cap'], ['street', 'number'], ['phone_prefix', 'phone']]) {
                        const boxes = await Promise.all(pair.map(name => page.locator(`[name="${name}"]`).locator('..').boundingBox()));
                        assert.ok(Math.abs(boxes[0].y - boxes[1].y) < 2, `${form}: ${pair.join('/')} must share a row`);
                    }
                }
            }
            console.log(`${form}: grid, conditional fields, real country API and interactive province OK`);
            if (form === 'modal') {
                await page.locator('.wi-modal-close').click();
                assert.equal(await page.locator('#preview-address').evaluate(el => el.classList.contains('wi-show')), false);
                assert.equal(await page.locator('#preview-address').evaluate(el => el.inert), true);
            }
            await page.close();
        }
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
