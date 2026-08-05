/*
 * Proves the error boundaries contain a render throw instead of letting it
 * unmount the admin — the Sprint 9 failure mode, reproduced deliberately.
 *
 * Run with: node tools/boundary-probe.mjs
 *
 * A boundary nobody has ever triggered is an untested claim, and this is
 * the one component in the app whose entire job only happens when
 * something else has already gone wrong. The throw is injected at the
 * network layer rather than in the source, so what is being tested is the
 * built bundle a customer would receive.
 *
 * The failure it reproduces: a well-formed envelope missing a nested
 * array the screen maps over unguarded. That is the shape of the bug that
 * blanked every route in Sprint 9 — a payload the types promised and the
 * server did not send.
 *
 * Passing means three things at once: the app is still mounted, the
 * boundary is on screen, and the chrome outside it survived.
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const SITE = 'http://wpproduct.test';

const php = [
  '$uid=1;$exp=time()+3600;',
  '$m=WP_Session_Tokens::get_instance($uid);$t=$m->create($exp);',
  'echo AUTH_COOKIE."\\t".wp_generate_auth_cookie($uid,$exp,"auth",$t)."\\t".ADMIN_COOKIE_PATH."\\n";',
  'echo LOGGED_IN_COOKIE."\\t".wp_generate_auth_cookie($uid,$exp,"logged_in",$t)."\\t".COOKIEPATH;',
].join('');

const cookies = execSync(`cd /Users/mominul/Sites/wpproduct && wp eval '${php}'`, {
  encoding: 'utf8',
})
  .trim()
  .split('\n')
  .map((line) => {
    const [name, value, path] = line.split('\t');
    return { name, value, domain: 'wpproduct.test', path: path || '/', httpOnly: true };
  });

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1400, height: 900 } });
await context.addCookies(cookies);

const page = await context.newPage();

/*
 * Force a throw during render of the settings screen only. Everything
 * outside the boundary — sidebar, roster, top bar — must survive.
 */
await page.addInitScript(() => {
  const realFetch = window.fetch;

  window.fetch = async (...args) => {
    const url = String(args[0]);

    if (url.includes('system/health')) {
      // A well-formed envelope whose `cron` object has lost its `events`
      // array. The screen maps over it unguarded, which is exactly the
      // shape of the Sprint 9 bug: a payload the types promised and the
      // server did not send.
      return new Response(
        JSON.stringify({
          data: {
            php: { version: '8.4', memory_limit: '512M', max_execution_time: '30', openssl: true },
            wordpress: { version: '7.0', multisite: false, cron_disabled: false },
            mysql: { version: '9.3', mariadb: false, charset: 'utf8mb4', collation: 'utf8mb4_unicode_520_ci' },
            database: { version: 10, latest: 10, tables_present: 27, tables_total: 27, missing: [] },
            queue: { driver: 'wp-cron', depth: 0 },
            cron: { scheduled: 3, overdue: 0 },
            providers: [],
            object_cache: { persistent: false, note: 'none' },
          },
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } }
      );
    }

    return realFetch(...args);
  };
});

const errors = [];
page.on('pageerror', (e) => errors.push(String(e)));

await page.goto(`${SITE}/wp-admin/admin.php?page=hiveclerk#/settings/system`, {
  waitUntil: 'networkidle',
});
await page.waitForTimeout(2000);

const report = await page.evaluate(() => {
  const root = document.getElementById('hvc-root');
  const alert = document.querySelector('#hvc-root [role="alert"]');

  return {
    // The whole app unmounting is the thing being tested for.
    rootHasChildren: (root?.children.length ?? 0) > 0,
    rootText: (root?.textContent ?? '').trim().length,
    boundaryShown: Boolean(alert),
    boundaryHeading: alert?.querySelector('h2')?.textContent ?? null,
    // Chrome outside the boundary must still be on screen.
    sidebarPresent: Boolean(document.querySelector('#hvc-root nav')),
    tabsPresent: Array.from(document.querySelectorAll('#hvc-root a')).some((a) =>
      (a.textContent ?? '').includes('Providers')
    ),
  };
});

console.log(JSON.stringify(report, null, 2));
console.log('page errors:', errors.length);

await browser.close();

const passed =
  report.rootHasChildren && report.boundaryShown && report.sidebarPresent;

console.log(passed ? 'RESULT: PASS' : 'RESULT: FAIL');
process.exit(passed ? 0 : 1);
