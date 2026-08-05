/*
 * Measures whether a host actually streams.
 *
 * The server cannot answer this about itself. PHP writes a byte and
 * considers it sent; whether it reached the browser then, or sat in
 * nginx's buffer until the request finished, is only visible from the
 * receiving end. So the server emits frames on a known cadence carrying
 * its own elapsed time, this records when each one arrived, and the
 * verdict is the comparison.
 *
 * Local run (mints a session against the dev site):
 *   node tools/sse-probe.mjs
 *
 * Remote host, using an application password:
 *   node tools/sse-probe.mjs --url=https://host.example \
 *     --user=admin --pass='xxxx xxxx xxxx xxxx xxxx xxxx'
 */

import { execSync } from 'node:child_process';

const arg = (name, fallback = null) =>
  process.argv.find((a) => a.startsWith(`--${name}=`))?.split('=').slice(1).join('=') ??
  fallback;

const SITE = (arg('url', 'http://wpproduct.test') ?? '').replace(/\/$/, '');
const WP_PATH = arg('wp-path', '/Users/mominul/Sites/wpproduct');
const FRAMES = Number(arg('frames', '12'));
const GAP = Number(arg('gap', '250'));
const USER = arg('user');
const PASS = arg('pass');

const API = `${SITE}/wp-json/hiveclerk/v1`;

/*
 * Authentication.
 *
 * Against a remote host an application password is the only thing that
 * works without a browser. Locally we mint a session instead, so no
 * credential has to exist or be typed. The nonce is generated inside the
 * same eval that creates the session token, and after the cookie has been
 * placed in $_COOKIE — wp_create_nonce() hashes the session token, so a
 * nonce made before the cookie exists is a nonce for a different session
 * and fails with a 403 that looks like a permissions bug.
 */
function credentials() {
  if (USER && PASS) {
    const basic = Buffer.from(`${USER}:${PASS}`).toString('base64');
    return { headers: { Authorization: `Basic ${basic}` } };
  }

  const php = [
    '$uid=1;$exp=time()+3600;',
    '$m=WP_Session_Tokens::get_instance($uid);$t=$m->create($exp);',
    '$c=wp_generate_auth_cookie($uid,$exp,"logged_in",$t);',
    '$_COOKIE[LOGGED_IN_COOKIE]=$c;',
    'wp_set_current_user($uid);',
    'echo LOGGED_IN_COOKIE."\\t".$c."\\t".wp_create_nonce("wp_rest");',
  ].join('');

  const [name, value, nonce] = execSync(`cd ${WP_PATH} && wp eval '${php}'`, {
    encoding: 'utf8',
  })
    .trim()
    .split('\t');

  return {
    headers: { Cookie: `${name}=${value}`, 'X-WP-Nonce': nonce },
  };
}

const { headers } = credentials();

// ---------------------------------------------------------------- environment

const envResponse = await fetch(`${API}/system/stream/environment`, { headers });

if (!envResponse.ok) {
  console.error(`Environment check failed: ${envResponse.status}`);
  console.error((await envResponse.text()).slice(0, 400));
  process.exit(1);
}

const env = (await envResponse.json()).data ?? (await envResponse.json());

console.log(`\n  ${SITE}`);
console.log(`  ${'─'.repeat(64)}`);
console.log(`  SAPI              ${env.sapi}`);
console.log(`  Server            ${env.server_software}`);
console.log(`  Proxy             ${env.proxy ?? 'none detected'}`);
console.log(`  output_buffering  ${env.output_buffering || '(empty)'}`);
console.log(`  zlib compression  ${env.zlib_compression ? 'on' : 'off'}`);
console.log(`  max_execution     ${env.max_execution_time || 'unlimited'}`);
console.log(`  buffer levels     ${env.buffer_levels}`);
console.log('');

for (const f of env.findings ?? []) {
  const mark = { ok: ' ok ', warn: 'warn', block: 'BLOCK' }[f.severity] ?? '?';
  console.log(`  [${mark}] ${f.label}: ${f.detail}`);
}

// ---------------------------------------------------------------------- probe

console.log(`\n  Probing ${FRAMES} frames at ${GAP} ms...\n`);

const start = performance.now();
const arrivals = [];

const response = await fetch(`${API}/system/stream/probe?frames=${FRAMES}&gap=${GAP}`, {
  headers: { ...headers, Accept: 'text/event-stream' },
});

if (!response.ok) {
  console.error(`Probe failed: ${response.status}`);
  console.error((await response.text()).slice(0, 400));
  process.exit(1);
}

const contentType = response.headers.get('content-type') ?? '';
const accelBuffering = response.headers.get('x-accel-buffering');

/*
 * Read frame by frame rather than awaiting response.text().
 *
 * Awaiting the whole body would make every host look identical: the
 * promise resolves once, at the end, whether the bytes trickled in or
 * arrived together. The timing has to be taken at the chunk boundary,
 * which means reading the stream by hand.
 */
const decoder = new TextDecoder();
let buffer = '';
let done = null;

for await (const chunk of response.body) {
  buffer += decoder.decode(chunk, { stream: true });

  let cut;
  while ((cut = buffer.indexOf('\n\n')) !== -1) {
    const block = buffer.slice(0, cut);
    buffer = buffer.slice(cut + 2);

    const event = /^event:\s?(.*)$/m.exec(block)?.[1];
    const data = block
      .split('\n')
      .filter((l) => l.startsWith('data:'))
      .map((l) => l.slice(5).replace(/^ /, ''))
      .join('\n');

    if (event === 'tick') {
      arrivals.push({
        ...JSON.parse(data),
        client_ms: Math.round(performance.now() - start),
      });
    } else if (event === 'done') {
      done = JSON.parse(data);
    }
  }
}

if (arrivals.length === 0) {
  console.error('  No frames received. The stream produced nothing parseable.');
  process.exit(1);
}

// -------------------------------------------------------------------- verdict

const medianOf = (xs) => {
  const s = [...xs].sort((a, b) => a - b);
  return s.length ? s[Math.floor(s.length / 2)] : 0;
};

const clientGaps = arrivals.slice(1).map((f, i) => f.client_ms - arrivals[i].client_ms);
const serverGaps = arrivals.slice(1).map((f, i) => f.server_ms - arrivals[i].server_ms);

/*
 * Compare against the gaps the server observed, not the gap it was asked
 * for. usleep() is not accurate everywhere — on this development machine
 * a 50 ms sleep measures 190 ms — and a client gap judged against the
 * requested figure is really measuring the host's timer, which has
 * nothing to do with whether anything buffered.
 */
const median = medianOf(clientGaps);
const serverMedian = medianOf(serverGaps);
const ttfb = arrivals[0].client_ms;
const total = Math.round(performance.now() - start);

console.log('  seq   server ms   client ms   drift');
for (const f of arrivals) {
  const drift = f.client_ms - f.server_ms;
  console.log(
    `  ${String(f.seq).padStart(3)}   ${String(f.server_ms).padStart(9)}   ` +
      `${String(f.client_ms).padStart(9)}   ${drift >= 0 ? '+' : ''}${drift}`
  );
}

/*
 * Two independent signals, because either alone has a false positive.
 *
 * A tiny median gap on its own could just be a fast host that ignored the
 * pacing. A late first frame on its own could be a slow cold start. Both
 * together is buffering: the server spaced the frames and they still all
 * landed at the end.
 */
const pacingHeld = median >= serverMedian * 0.6;
const firstFrameEarly = ttfb < total * 0.5;
const streaming = pacingHeld && firstFrameEarly;

console.log(`\n  Content-Type        ${contentType}`);
console.log(
  `  X-Accel-Buffering   ${
    accelBuffering ??
    '(absent — nginx consumes this header, so its absence means it was read)'
  }`
);
console.log(`  Frames              ${arrivals.length} of ${FRAMES}`);
console.log(`  First frame at      ${ttfb} ms`);
console.log(`  Median server gap   ${serverMedian} ms (asked for ${GAP} ms)`);
console.log(`  Median client gap   ${median} ms`);
console.log(`  Total               ${total} ms`);

if (done?.discarded_bytes) {
  console.log(
    `  Discarded           ${done.discarded_bytes} bytes of stray output before the stream`
  );
}

console.log('');
if (streaming) {
  console.log('  VERDICT: streaming. Frames arrived on the cadence they were sent.');
} else if (!firstFrameEarly) {
  console.log(
    '  VERDICT: buffered. Nothing arrived until the response was complete —' +
      '\n           something between PHP and here is holding the body.'
  );
} else {
  console.log(
    '  VERDICT: partially buffered. The stream opened, but frames arrived in' +
      '\n           bursts rather than as they were sent.'
  );
}
console.log('');

process.exit(streaming ? 0 : 2);
