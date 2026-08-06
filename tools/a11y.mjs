/**
 * Accessibility audit of the live admin, in a real browser.
 *
 * The companion to `tests/frontend/accessibility.test.tsx`, which runs in
 * jsdom on every `npm run check` and is fast, offline, and blind to
 * anything needing layout — colour contrast above all, which it can only
 * report as "incomplete". It is also blind to *populated* UI: with no
 * server, `/leads/table` renders a filter bar and no table.
 *
 * This runs against the development site with its real content, so the
 * rows, drawers, charts and dialogs that make up most of the product are
 * actually in the page being audited. It needs a running site and takes
 * about a minute, which is why it is a tool rather than part of the gate.
 *
 * Usage:
 *   node tools/a11y.mjs                 # both themes, every screen
 *   node tools/a11y.mjs --theme=dark    # one theme
 *   node tools/a11y.mjs --route=leads/table
 *   node tools/a11y.mjs --json=out.json # full machine-readable results
 */

import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';

const SITE = 'http://wpproduct.test';
const THEME = process.argv.find((a) => a.startsWith('--theme='))?.split('=')[1];
const ROUTE = process.argv.find((a) => a.startsWith('--route='))?.split('=')[1];
const JSON_OUT = process.argv.find((a) => a.startsWith('--json='))?.split('=')[1];

/*
 * The published standard rather than a list we chose, so that a pass is
 * something a customer's own accessibility consultant would recognise.
 */
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

const SCREENS = ROUTE
  ? [ROUTE]
  : [
      'dashboard',
      'clerks',
      'conversations',
      'leads/pipeline',
      'leads/table',
      'leads/scoring',
      'email/sequences',
      'email/log',
      'knowledge/sources',
      'knowledge/gaps',
      'knowledge/playground',
      'knowledge/embedding',
      'integrations/connectors',
      'integrations/log',
      'analytics/overview',
      'analytics/costs',
      'settings/providers',
      'settings/licence',
      'settings/branding',
      'settings/privacy',
      'settings/system',
      'settings/audit',
      'onboarding',
    ];

const THEMES = THEME ? [THEME] : ['light', 'dark'];

/*
 * Mint short-lived session cookies rather than touching the user's
 * password. wp-admin needs both: auth_redirect() validates the "auth"
 * cookie on /wp-admin, is_user_logged_in() reads "logged_in" on /.
 */
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
const context = await browser.newContext({ viewport: { width: 1600, height: 1000 } });
await context.addCookies(cookies);

const page = await context.newPage();

const findings = [];
let audited = 0;

for (const theme of THEMES) {
  for (const route of SCREENS) {
    await page.goto(`${SITE}/wp-admin/admin.php?page=hiveclerk#/${route}`, {
      waitUntil: 'networkidle',
    });

    /*
     * The hash router does not reload, so a second route on the same page
     * needs the navigation forced and the render awaited. Without this,
     * every screen after the first audits whatever the first one rendered
     * and the run reports a clean bill of health for one screen twenty
     * times over.
     */
    await page.evaluate((r) => {
      window.location.hash = `#/${r}`;
    }, route);

    await page.evaluate((t) => {
      document.documentElement.setAttribute('data-theme', t);
    }, theme);

    await page.waitForTimeout(1500);

    const reached = await page.evaluate(() => window.location.hash);

    /*
     * The catch-all redirect means a mistyped route lands on the dashboard
     * and audits it under the wrong name. Recorded rather than assumed.
     */
    const landed = reached.replace(/^#\//, '');

    /*
     * Scoped to the plugin's own root. wp-admin's chrome — the toolbar,
     * the admin menu — is WordPress's markup and its violations are not
     * ours to fix; including them would bury anything we can act on.
     */
    const results = await new AxeBuilder({ page })
      .include('#hvc-root')
      .withTags(TAGS)
      .analyze();

    audited += 1;

    const nodes = await page.evaluate(
      () => document.querySelector('#hvc-root')?.querySelectorAll('*').length ?? 0
    );

    for (const violation of results.violations) {
      findings.push({
        theme,
        route,
        landed,
        id: violation.id,
        impact: violation.impact,
        help: violation.help,
        helpUrl: violation.helpUrl,
        count: violation.nodes.length,
        targets: violation.nodes.slice(0, 4).map((n) => n.target.join(' ')),
        sample: violation.nodes[0]?.failureSummary ?? '',
      });
    }

    const drift = landed !== route ? `  (landed on ${landed})` : '';
    const verdict = results.violations.length === 0 ? 'clean' : `${results.violations.length} violation types`;

    console.log(
      `${theme.padEnd(5)} ${route.padEnd(24)} ${String(nodes).padStart(5)} nodes  ${verdict}${drift}`
    );
  }
}

console.log(`\n${audited} screen/theme combinations audited against ${TAGS.join(', ')}.`);

if (findings.length === 0) {
  console.log('No WCAG A or AA violations.');
} else {
  /* Grouped by rule: one bad shared component is one fix, not thirty. */
  const byRule = new Map();

  for (const finding of findings) {
    const existing = byRule.get(finding.id) ?? { ...finding, where: [], total: 0 };

    existing.where.push(`${finding.theme}:${finding.route}`);
    existing.total += finding.count;
    byRule.set(finding.id, existing);
  }

  console.log(`\n${byRule.size} distinct rules failed:\n`);

  for (const rule of [...byRule.values()].sort((a, b) => b.total - a.total)) {
    console.log(`[${rule.impact}] ${rule.id} — ${rule.help}`);
    console.log(`  ${rule.total} element(s) across ${rule.where.length} screen/theme(s)`);
    console.log(`  first seen: ${rule.where[0]}  e.g. ${rule.targets[0]}`);
    console.log(`  ${rule.helpUrl}\n`);
  }
}

if (JSON_OUT) {
  writeFileSync(JSON_OUT, JSON.stringify(findings, null, 2));
  console.log(`full results written to ${JSON_OUT}`);
}

await browser.close();

process.exitCode = findings.length === 0 ? 0 : 1;
