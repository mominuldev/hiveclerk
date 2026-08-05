# Deliverable 17 — SSE Host-Compatibility Spike

**Sprint 3 · Risk R-2 · Decision TD-2**
**Status:** transport decision **confirmed**, with three amendments to §5.1 of the system architecture.
**Measured:** 2026-08-05

---

## 1. What this spike had to settle

TD-2 committed the product to server-sent events with a polling fallback. R-2 is the risk that the transport is defeated by buffering somewhere between PHP and the browser, and it is rated High/High — the single most likely source of launch-day support tickets.

The uncomfortable property of that risk is that **buffering is not a failure**. Every byte arrives, in order, uncorrupted. Nothing logs an error. The only symptom is that a streamed answer becomes a slow one, and the difference is invisible to any test that reads the response body as a whole.

So the spike needed to produce three things:

1. A transport that switches off everything it can reach.
2. A way to detect, from the receiving end, whether it worked.
3. Evidence that the detector can tell the two cases apart.

---

## 2. What was built

| Component | Purpose |
|---|---|
| `Api\Streaming\SseEncoder` | Pure framing. Static, no state. |
| `Api\Streaming\SseStream` | The transport: header set, buffer teardown, padding, flush, abort detection. |
| `Api\Streaming\StreamEnvironment` | Reports what the host does that we cannot switch off. |
| `Api\Controllers\StreamController` | `GET /system/stream/environment` and `GET /system/stream/probe`. |
| `tools/sse-probe.mjs` | The measuring client. Runs against localhost or any remote host with an application password. |

The encoder is separate from the transport for one reason: it can be round-tripped against `Ai\Streaming\SseParser`, which Sprint 2 already ships. Two classes written from the same specification can both be wrong the same way, but they cannot disagree silently. Eleven tests cover that round trip, including payloads containing newlines, unicode, a stream fed one byte at a time, and an event name containing a line break.

---

## 3. Method

The server emits *n* frames on a fixed cadence. Each frame carries the server's own elapsed milliseconds. The client records the wall-clock time each frame arrived.

Two signals, because either alone has a false positive:

- **`firstFrameEarly`** — the first frame arrived in the first half of the total duration. A late first frame alone could just be a slow cold start.
- **`pacingHeld`** — the median client inter-frame gap is at least 60% of the median *server* inter-frame gap. A small client gap alone could be a fast host that ignored the pacing.

Both together is streaming. Neither is buffering.

> **The comparison is against the server's observed gap, not the gap requested.** The first version of the tool compared against the requested figure and produced a nonsense reading: it reported a median client gap of 192 ms where 50 ms was asked for, which looked like a 4× stall. It was not. `usleep()` on this machine overshoots by roughly 4× under PHP-FPM. Judged against the requested figure, the tool was measuring the host's timer accuracy — a quantity with nothing to do with buffering.

---

## 4. Result — the host measured

`nginx/1.27.5` → PHP-FPM 8.4.7, `output_buffering=4096`, `zlib.output_compression=off`.

```
  seq   server ms   client ms   drift
    1           0          53   +53
    2         449         501   +52
    3         868         921   +53
    4        1317        1369   +52
    5        1763        1815   +52
    6        2199        2251   +52

  First frame at      53 ms
  Median server gap   446 ms
  Median client gap   446 ms
  Total               2252 ms

  VERDICT: streaming.
```

**Streaming.** The client gap equals the server gap to the millisecond, and the drift is *constant* at ~52 ms rather than accumulating. Constant drift is the signature that matters: it is fixed connection overhead, applied once. Accumulating drift would mean something was metering the flow.

### 4.1 The negative control

A verdict of "streaming" is worth nothing if the tool cannot report anything else. An endpoint emitting identical frames on an identical cadence, held in an output buffer until the request ended:

```
  seq  server_ms  client_ms
    1          0       3192
    2        395       3192
    ...
    8       2777       3192

  first frame at    3192 ms of 3192 ms total
  median client gap 0 ms   (median server gap 396 ms)
  pacingHeld=false  firstFrameEarly=false
```

Every frame at the same millisecond, both signals negative. The detector discriminates.

### 4.2 Two defects the measurement found

**`StreamEnvironment` reported a blocker on a host that was demonstrably streaming.** The removable-buffer check read `$buffer['del']` from `ob_get_status()`. PHP 8 removed that key and replaced it with a `flags` bitmask, so `empty( $buffer['del'] )` is true for every buffer on every supported PHP version — the check reported `BLOCK` unconditionally. Now reads `flags & PHP_OUTPUT_HANDLER_REMOVABLE`. Had the probe not contradicted it, this would have shipped as a permanent false alarm telling every customer their host cannot stream.

**A suspected 142 ms per-frame cost was not ours.** Frames were pacing ~142 ms slower than the sleep requested, which at token rates would cap a stream at seven tokens per second. A WordPress-free control measured the phases separately: `echo` 0.0 ms, `flush()` 0.0–0.1 ms, `connection_aborted()` 0.0 ms, `usleep(50000)` **190 ms**. The entire discrepancy is sleep imprecision in this virtualised environment. The write path costs nothing measurable.

---

## 5. Amendments to the architecture decision

The transport decision in §5.1 is **confirmed**. Three details change.

| § 5.1 said | Now | Why |
|---|---|---|
| `ob_end_flush()` on all levels | `ob_end_clean()`, counting discarded bytes | Anything sitting in a buffer when a stream opens is output nobody asked for — a notice, a plugin echoing during init. Flushing it prefixes those bytes to the first frame and makes **every** frame after it unparseable. One lost notice beats a dead stream. The byte count is reported on the `done` frame so the cause stays findable. |
| 4 KB padding | 4 KB padding, kept | Written as 2 KB first. The measurement corrected it: this host runs `output_buffering=4096`, so 2 KB sits *inside* the default buffer on any host where teardown fails — exactly the host padding exists for. |
| `Cache-Control: no-cache, no-transform` | `no-cache, no-store, no-transform, must-revalidate, private` | `no-transform` is the load-bearing token: it forbids an intermediary rewriting the body, and rewriting requires holding it first. The rest keeps the stream out of shared caches. |

One addition not in §5.1: **`ignore_user_abort( true )`**, with the stop decision taken explicitly via `connection_aborted()` after each frame. The instinct is the opposite — let PHP kill the script when the visitor leaves. But tokens already generated are already billed, and the usage row recording them is written *after* the generation loop. Being killed mid-write loses the record and understates spend in the direction nobody audits. The same reasoning produced the nullable `cost` column in Sprint 2.

---

## 6. What this spike did **not** establish

**Five shared hosts were named in the sprint plan. One host was measured.** That host is a local nginx + PHP-FPM development stack. It is representative of the *architecture* most managed WordPress hosts run, and it is not evidence about any of them.

This is the honest limit of what could be done from this machine, and the gap is the part of R-2 that remains open. Specifically unmeasured:

- **Cloudflare and comparable edges.** Detected and warned about, never observed. The 100-second proxy timeout is a separate concern from buffering and also unverified.
- **LiteSpeed**, which is common on cPanel hosting and buffers differently from nginx.
- **Apache with `mod_deflate`** configured above PHP, where the `no-gzip` environment variable is the only defence and a server-level override defeats it.
- **Hosts that lock `zlib.output_compression` on.**
- **Any host where `ob_end_clean()` fails**, which is now the one condition reported as a hard blocker.

### 6.1 Runbook — how to close it

The tooling is finished; only access to the hosts is missing. On each target host, install the plugin, create an application password, and run:

```bash
node tools/sse-probe.mjs \
  --url=https://site.example \
  --user=admin \
  --pass='xxxx xxxx xxxx xxxx xxxx xxxx' \
  --frames=20 --gap=250
```

Exit code `0` is streaming, `2` is not. The tool prints the environment report, the full per-frame table, and the verdict; paste that output into the row below.

| # | Host | Stack | Verdict | Notes |
|---|---|---|---|---|
| — | Local dev | nginx 1.27.5 / PHP-FPM 8.4 | ✅ streaming | first frame 53 ms, gaps matched exactly |
| 1 | _(SiteGround)_ | | ⬜ not run | |
| 2 | _(Bluehost)_ | | ⬜ not run | |
| 3 | _(Hostinger)_ | | ⬜ not run | |
| 4 | _(GoDaddy)_ | | ⬜ not run | |
| 5 | _(WP Engine)_ | | ⬜ not run | |

**This does not block Sprint 3.** Sprint 5 is where the streaming chat endpoint and the polling fallback are built, and the fallback is what makes a buffering host a degraded experience rather than a broken one. The matrix must be filled before Sprint 5 closes, and it is re-run as verification in Sprint 10 (sprint plan §12).

> **Sprint 5 closed with the matrix still empty.** The endpoint and the
> fallback were built and both were measured working on the same single
> host — streamed replies with citations, and the polling path returning
> its `202` in 59 ms and completing in 1.6 s. The transport decision is
> unchanged and the fallback now exists, but **M2's "4 of 5 hosts"
> criterion is unmet**, and one local nginx stack is still not evidence
> about the five hosts that carry the risk. The runbook above is unchanged
> and takes about ten minutes per host once there is access to one.

---

## 7. Residual risk

R-2 moves from **High/High** to **High likelihood / Medium impact** — likelihood unchanged because nothing was learned about the hosts that carry the risk, impact reduced because the detector now exists and is proven to discriminate, so a buffering host degrades to polling instead of appearing to hang.

It does not move further until the matrix above has rows in it.

---

**Approval:** ⬜ Awaiting sign-off · Reviewer: ______________ · Date: __________
