/**
 * Screenshots the FAQ source editor in both themes.
 *
 * The editor lives inside a modal, so it cannot be reached by a route alone
 * — the shot has to open the dialog and choose the type. Kept as a script
 * rather than folded into shot.mjs because "drive the UI to a state, then
 * capture it" is a different job from "load a route".
 *
 * Usage: node tools/faq-shot.mjs <out-dir>
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const SITE = 'http://wpproduct.test';
const OUT = process.argv[2] ?? '.';

const php = [
  '$uid=1;$exp=time()+3600;',
  '$m=WP_Session_Tokens::get_instance($uid);$t=$m->create($exp);',
  'echo AUTH_COOKIE."\\t".wp_generate_auth_cookie($uid,$exp,"auth",$t)."\\t".ADMIN_COOKIE_PATH."\\n";',
  'echo LOGGED_IN_COOKIE."\\t".wp_generate_auth_cookie($uid,$exp,"logged_in",$t)."\\t".COOKIEPATH;',
].join('');

const cookies = execSync(
  `cd /Users/mominul/Sites/wpproduct && wp eval '${php}'`,
  { encoding: 'utf8' }
)
  .trim()
  .split('\n')
  .map((line) => {
    const [name, value, path] = line.split('\t');
    return { name, value, domain: 'wpproduct.test', path: path || '/', httpOnly: true };
  });

const browser = await chromium.launch();
const context = await browser.newContext({
  viewport: { width: 1600, height: 1100 },
  deviceScaleFactor: 2,
});
await context.addCookies(cookies);

const page = await context.newPage();
const errors = [];
page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
page.on('pageerror', (e) => errors.push(String(e)));

await page.goto(`${SITE}/wp-admin/admin.php?page=hiveclerk#/knowledge`, {
  waitUntil: 'networkidle',
});
await page.waitForTimeout(1200);

await page.getByRole('button', { name: /add source/i }).click();
await page.waitForTimeout(400);

await page.getByLabel(/source type/i).selectOption('faq');
await page.waitForTimeout(300);

// Fill the first pair so the screenshot shows the editor working rather than
// an empty form, and open the import panel so both halves are visible.
await page.getByLabel('Question').first().fill('Do you ship to Ireland?');
await page
  .getByLabel(/^Answer$/)
  .first()
  .fill('Yes — delivery is £8 and takes four working days.');
await page.getByRole('button', { name: /import csv/i }).click();
await page.waitForTimeout(400);

for (const theme of ['light', 'dark']) {
  await page.evaluate((t) => {
    document.documentElement.setAttribute('data-theme', t);
  }, theme);
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${OUT}/faq-editor-${theme}.png` });
  console.log('saved', `${OUT}/faq-editor-${theme}.png`);
}

// Focus ring on the first interactive control inside the editor, so the
// keyboard path is captured rather than assumed.
await page.keyboard.press('Tab');
const focused = await page.evaluate(() => {
  const el = document.activeElement;
  return el ? `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}` : 'none';
});
console.log('focus after Tab:', focused);

if (errors.length) console.log('CONSOLE ERRORS:', errors.slice(0, 5));
else console.log('no console errors');

await browser.close();
