import { chromium } from 'playwright';

/**
 * Drive the public widget in a real browser and measure it.
 *
 * The admin equivalent is tools/shot.mjs, which has to mint auth cookies.
 * This one deliberately does not: the widget is what an anonymous visitor
 * sees, and logging in to look at it would test a page no visitor loads.
 *
 * Usage:
 *   node tools/widget-shot.mjs [out.png] [--theme=dark] [--ask="question"]
 *                              [--reduced-motion] [--keyboard]
 */

const SITE = process.argv.find((a) => a.startsWith('--site='))?.split('=')[1] ?? 'http://wpproduct.test';
const OUT = process.argv[2]?.startsWith('--') ? 'widget.png' : (process.argv[2] ?? 'widget.png');
const THEME = process.argv.find((a) => a.startsWith('--theme='))?.split('=')[1] ?? 'light';
const ASK = process.argv.find((a) => a.startsWith('--ask='))?.split('=')[1];
const REDUCED = process.argv.includes('--reduced-motion');
const KEYBOARD = process.argv.includes('--keyboard');

const browser = await chromium.launch();
const context = await browser.newContext({
  viewport: { width: 1280, height: 900 },
  deviceScaleFactor: 2,
  colorScheme: THEME === 'dark' ? 'dark' : 'light',
  reducedMotion: REDUCED ? 'reduce' : 'no-preference',
});

const page = await context.newPage();
const errors = [];

page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
page.on('pageerror', (e) => errors.push(String(e)));

const started = Date.now();

await page.goto(SITE, { waitUntil: 'load' });
await page.waitForSelector('#hvc-widget-root', { timeout: 10_000 });

console.log(`widget mounted after ${Date.now() - started} ms`);

// Everything is inside a shadow root, so every locator goes through the
// host. Playwright pierces open shadow roots automatically, which is the
// one reason the root is `open` rather than `closed`.
const host = page.locator('#hvc-widget-root');

console.log(`  theme attribute  ${await host.getAttribute('data-theme')}`);
console.log(`  position         ${await host.getAttribute('data-position')}`);

// The launcher must be a real 44px target, per D11 §15.
const launcher = host.locator('.launcher');
const box = await launcher.boundingBox();

console.log(`  launcher         ${Math.round(box.width)}×${Math.round(box.height)} px`);

if (box.height < 44) {
  errors.push(`launcher is ${box.height}px tall, under the 44px minimum`);
}

// Does the page's own CSS leak in, or ours leak out?
const bleed = await page.evaluate(() => {
  const body = getComputedStyle(document.body);

  return { bodyFont: body.fontFamily, bodyColor: body.color };
});

console.log(`  page body font   ${bleed.bodyFont.slice(0, 48)}`);

if (KEYBOARD) {
  await page.keyboard.press('Tab');

  const focused = await page.evaluate(() => {
    const root = document.getElementById('hvc-widget-root')?.shadowRoot;

    return root?.activeElement?.className ?? document.activeElement?.tagName;
  });

  console.log(`  first tab stop   ${focused}`);
}

await launcher.click();
await host.locator('.panel').waitFor({ timeout: 5000 });

console.log('  panel opened');

if (ASK) {
  const field = host.locator('textarea');

  await field.fill(ASK);
  await field.press('Enter');

  const bubble = host.locator('.row.clerk .bubble').last();

  await bubble.waitFor({ timeout: 60_000 });

  const firstPaint = Date.now();

  // Wait for the typing indicator to be replaced by text.
  await host
    .locator('.row.clerk .bubble p')
    .last()
    .waitFor({ timeout: 60_000 });

  console.log(`  first text       ${Date.now() - firstPaint} ms after the bubble appeared`);

  await page.waitForTimeout(2500);

  const answer = await bubble.innerText();

  console.log(`  answer           ${answer.replace(/\n/g, ' ').slice(0, 160)}`);

  const sources = await host.locator('.source').count();

  console.log(`  citations shown  ${sources}`);
}

await page.screenshot({ path: OUT, fullPage: false });

console.log(`\nwrote ${OUT}`);

if (errors.length) {
  console.log('\nconsole errors:');
  errors.forEach((e) => console.log(`  ${e}`));
}

await browser.close();

process.exit(errors.length ? 1 : 0);
