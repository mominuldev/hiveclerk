import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const SITE = 'http://wpproduct.test';
const OUT = process.argv[2] ?? 'shot.png';
const DIAGNOSE = process.argv.includes('--diagnose');

/*
 * Mint short-lived session cookies rather than touching the user's password.
 *
 * wp-admin needs both: auth_redirect() validates the "auth"-scheme cookie on
 * /wp-admin, while is_user_logged_in() reads the "logged_in" one on /.
 * Supplying only the second lands on the login screen with the username
 * pre-filled, which is exactly what happened the first time.
 */
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
    return {
      name,
      value,
      domain: 'wpproduct.test',
      path: path || '/',
      httpOnly: true,
    };
  });

const browser = await chromium.launch();
const context = await browser.newContext({
  viewport: { width: 1600, height: 1000 },
  deviceScaleFactor: 2,
});

await context.addCookies(cookies);

const page = await context.newPage();
const errors = [];
page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
page.on('pageerror', (e) => errors.push(String(e)));

const THEME = process.argv.find((a) => a.startsWith('--theme='))?.split('=')[1];
const ROUTE = process.argv.find((a) => a.startsWith('--route='))?.split('=')[1];

await page.goto(
  `${SITE}/wp-admin/admin.php?page=hiveclerk${ROUTE ? `#/${ROUTE}` : ''}`,
  { waitUntil: 'networkidle' }
);
await page.waitForTimeout(1200);

if (THEME) {
  await page.evaluate((t) => {
    document.documentElement.setAttribute('data-theme', t);
  }, THEME);
  await page.waitForTimeout(400);
}

if (DIAGNOSE) {
  const report = await page.evaluate(() => {
    const px = (el, ...props) => {
      if (!el) return null;
      const s = getComputedStyle(el);
      return Object.fromEntries(props.map((p) => [p, s.getPropertyValue(p)]));
    };
    const root = document.getElementById('hvc-root');
    const wpcontent = document.getElementById('wpcontent');
    const adminmenu = document.getElementById('adminmenuwrap');
    const heading = document.querySelector('#hvc-root h1');
    const link = document.querySelector('#hvc-root a');
    const cssRoot = getComputedStyle(document.documentElement);

    return {
      theme: document.documentElement.getAttribute('data-theme'),
      tokens: {
        canvas: cssRoot.getPropertyValue('--hvc-canvas').trim(),
        text: cssRoot.getPropertyValue('--hvc-text').trim(),
        accent: cssRoot.getPropertyValue('--hvc-accent').trim(),
      },
      adminMenuRight: adminmenu?.getBoundingClientRect().right,
      wpcontent: {
        ...px(wpcontent, 'margin-left', 'padding-left'),
        left: wpcontent?.getBoundingClientRect().left,
      },
      hvcRoot: {
        ...px(root, 'margin-left', 'background-color', 'color'),
        left: root?.getBoundingClientRect().left,
        width: root?.getBoundingClientRect().width,
      },
      headingColor: px(heading, 'color'),
      headingText: heading?.textContent,
      linkColor: px(link, 'color'),
      overlap:
        root && adminmenu
          ? root.getBoundingClientRect().left <
            adminmenu.getBoundingClientRect().right
          : null,
    };
  });
  console.log(JSON.stringify(report, null, 2));
}

await page.screenshot({ path: OUT, fullPage: false });
if (errors.length) console.log('CONSOLE ERRORS:', errors.slice(0, 5));
await browser.close();
console.log('saved', OUT);
