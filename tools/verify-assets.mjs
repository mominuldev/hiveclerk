/**
 * Asserts the committed build output matches the committed source.
 *
 * Run with:  node tools/verify-assets.mjs
 * Wired into `npm run check`.
 *
 * ## Why this exists
 *
 * `assets/` is in version control because WordPress.org distributes
 * source ZIPs with no build step, so the files a customer installs are
 * the files in the repository — not the files a build would produce from
 * it. Nothing checked those were the same thing.
 *
 * The failure is quiet in the way that matters: a change to
 * `admin-app/src` merged without a rebuild ships an admin screen running
 * yesterday's code, and every gate stays green because every gate reads
 * the source. It was found by accident, when a stylesheet turned out to
 * be missing utilities the app referenced.
 *
 * ## Why it can work now
 *
 * It could not have before. Tailwind 4 discovered classes by scanning the
 * whole project directory, so the stylesheet was a function of whatever
 * files happened to be lying around — a stray note at the plugin root
 * changed the output. Comparing a fresh build against the committed one
 * would have failed for reasons that had nothing to do with the source.
 * With the content scan bounded to `admin-app`, a rebuild is
 * reproducible and the comparison means something.
 *
 * Content is compared, not filenames. Vite's hashes already encode the
 * content, but a hash mismatch alone cannot say whether anything
 * meaningful changed, and this has to be readable by whoever it stops.
 */

import { execSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, mkdtempSync, readdirSync, readFileSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, relative, resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const assets = join(root, 'assets');

/** Every file under a directory, relative to it, sorted. */
function walk(dir, base = dir) {
  if (!statSync(dir, { throwIfNoEntry: false })?.isDirectory()) return [];

  return readdirSync(dir, { withFileTypes: true })
    .flatMap((entry) => {
      const full = join(dir, entry.name);
      return entry.isDirectory() ? walk(full, base) : [relative(base, full)];
    })
    .sort();
}

/** Content hashes keyed by path, so a rename is visible as such. */
function fingerprint(dir) {
  return new Map(
    walk(dir).map((file) => [
      file,
      createHash('sha256').update(readFileSync(join(dir, file))).digest('hex'),
    ])
  );
}

const committed = fingerprint(assets);

if (committed.size === 0) {
  console.error('No committed assets found. Run npm run build and commit the result.');
  process.exit(1);
}

// Keep the committed output aside, rebuild in place, compare, restore.
// Building into a temporary directory instead would need every Vite
// config to take an outDir override, and a check that does not exercise
// the real build configuration is checking the wrong thing.
const stash = mkdtempSync(join(tmpdir(), 'hvc-assets-'));
cpSync(assets, stash, { recursive: true });

let rebuilt;

try {
  execSync('npm run build:only', { cwd: root, stdio: 'pipe' });
  rebuilt = fingerprint(assets);
} finally {
  rmSync(assets, { recursive: true, force: true });
  cpSync(stash, assets, { recursive: true });
  rmSync(stash, { recursive: true, force: true });
}

const changed = [];
const added = [];
const removed = [];

for (const [file, hash] of rebuilt) {
  if (!committed.has(file)) added.push(file);
  else if (committed.get(file) !== hash) changed.push(file);
}

for (const file of committed.keys()) {
  if (!rebuilt.has(file)) removed.push(file);
}

if (added.length === 0 && changed.length === 0 && removed.length === 0) {
  console.log(`assets/ matches a fresh build (${committed.size} files).`);
  process.exit(0);
}

console.error('Committed assets do not match a build of the committed source.\n');

for (const [label, list] of [
  ['would be rebuilt differently', changed],
  ['a build produces, but is not committed', added],
  ['is committed, but a build does not produce', removed],
]) {
  if (list.length > 0) {
    console.error(`  ${label}:`);
    for (const file of list) console.error(`    ${file}`);
  }
}

console.error(
  '\nRun `npm run build` and commit assets/. WordPress.org ships this' +
    '\ndirectory verbatim, so whatever is here is what customers execute.'
);

process.exit(1);
