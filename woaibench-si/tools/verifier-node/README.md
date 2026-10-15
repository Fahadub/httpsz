# WOAIBench.si — headless verifier

`verify.mjs` runs the **browser checks, unit tests and screenshots** for benchmark runs without a human
keeping the admin *Verification Station* tab open. It talks to the same verifier API as the Station
(`/api/verifier/next|submit|release`), opens every output in headless Chromium (Playwright) at desktop
and mobile size, waits for the in-page probe, takes real `page.screenshot()` captures and submits them.

Hostinger shared hosting cannot run a browser, so run this on **your own computer or any small VPS**.
The site itself only needs to be reachable over HTTPS.

## 1. Requirements

* Node.js **18.17+** (20 LTS or 22 LTS recommended)
* ~400 MB disk for Chromium, ~300 MB RAM per parallel run
* Outbound HTTPS to your site (and to the CDNs model outputs may load: cdnjs, jsDelivr, unpkg, esm.sh,
  Skypack, threejs.org, Google Fonts)

## 2. Install

```bash
# copy the folder tools/verifier-node/ anywhere (it is self-contained), then:
cd verifier-node
npm install
npx playwright install chromium          # downloads the matching Chromium build
# Linux servers also need the system libraries once:
sudo npx playwright install-deps chromium
```

**Arabic and emoji in screenshots** (Linux servers): install fonts, otherwise Arabic text renders as boxes:

```bash
sudo apt-get install -y fonts-noto-core fonts-noto-color-emoji fonts-kacst fonts-dejavu-core
```

Windows and macOS already ship suitable fonts.

## 3. Create a token

In the admin panel open **API tokens** → create a token with scope **verifier**. The token is shown once;
store it like a password (it can claim and submit verification results, nothing else).

## 4. Run

```bash
node verify.mjs --site https://woaibench.si --token <TOKEN>                 # keeps polling the queue
node verify.mjs --site https://woaibench.si --token <TOKEN> --once          # drain the queue, then exit
node verify.mjs --site https://woaibench.si --token <TOKEN> --concurrency 3
```

Environment variables work too: `WB_SITE`, `WB_VERIFIER_TOKEN` (handy for services — keeps the token
out of the process list).

| Option | Meaning |
|---|---|
| `--site <url>` | Site origin, plus the base path when the site lives in a sub-folder |
| `--token <token>` | Verifier API token |
| `--concurrency <n>` | Parallel runs (1–8, default 2). Each run gets its own browser context |
| `--once` | Process until the queue is empty, then exit (good for cron) |
| `--max-runs <n>` | Exit after *n* runs |
| `--idle <s>` | Longest pause between polls while the queue is empty (default 30 s) |
| `--headed` | Show the browser window (debugging) |
| `--no-swiftshader` | Use the real GPU for WebGL instead of the software renderer |
| `--quiet` | Log only per-run summaries and problems |

`Ctrl+C` finishes the runs in flight; a second `Ctrl+C` releases them back to the queue and exits.
Exit codes: `0` ok · `2` bad arguments · `3` token rejected · `4` Chromium/Playwright missing.

### Browser binary

By default the Chromium that Playwright downloaded is used. If its revision does not match, the script
also tries `$PLAYWRIGHT_BROWSERS_PATH`, `/opt/pw-browsers`, `~/.cache/ms-playwright` and the usual
system locations (`/usr/bin/chromium`, `google-chrome`). Force a binary with
`WB_CHROMIUM=/path/to/chrome` (aliases: `PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH`, `CHROMIUM_PATH`).

WebGL: servers without a GPU render WebGL in software (SwiftShader, enabled by default). Frame-rate
checks are therefore stricter on small VPSs than on a desktop with a GPU — prefer a machine with at least
2 dedicated vCPUs and keep `--concurrency` ≤ the number of cores.

## 5. Keep it running

**systemd (Linux VPS)** — `/etc/systemd/system/woaibench-verifier.service`:

```ini
[Unit]
Description=WOAIBench.si headless verifier
After=network-online.target

[Service]
WorkingDirectory=/opt/verifier-node
Environment=WB_SITE=https://woaibench.si
Environment=WB_VERIFIER_TOKEN=<TOKEN>
ExecStart=/usr/bin/node verify.mjs --concurrency 2 --quiet
Restart=always
RestartSec=10
User=verifier

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now woaibench-verifier
journalctl -u woaibench-verifier -f
```

**pm2**: `pm2 start verify.mjs --name wb-verifier -- --site https://woaibench.si --token <TOKEN>`

**Windows**: run it in a terminal, or create a Task Scheduler task that starts
`node C:\verifier-node\verify.mjs --site … --token … --once` every few minutes.

## 6. How a run is verified

1. `POST /api/verifier/next` returns a leased run with a signed `render_url` (valid 15 minutes, only
   while the lease is held) and the task's browser checks / unit tests.
2. For each viewport (desktop 1280×800, then mobile 390×844) a fresh browser context opens
   `render_url&mode=playwright`. The page is served with a CSP `sandbox` header, so model code runs with an
   opaque origin: no cookies, no storage, no access to the site's API.
3. The probe (`assets/js/probe.js`, injected first in `<head>`) records errors, waits for `load`, evaluates
   the checks (`loads`, `no_errors`, `nonblank`, `selector`, `text`, `animates`, `fps`, `responsive`,
   `keyboard`, `click`, `rtl`, `a11y_basic`, `canvas`) and runs JS unit tests in a Worker with per-case
   time limits. The mobile pass only re-evaluates the cheap layout checks; `responsive` is taken from it.
4. The verifier reads `window.__WB_PROBE_RESULT_JSON`, captures a screenshot and submits everything.
5. A page that freezes (infinite loop) gets no result: after the probe's deadline + 6 s the run is
   submitted with every check failed ("page unresponsive") — it is the model's failure. Network or
   browser problems instead **release** the run so it is retried.

## 7. Troubleshooting

| Symptom | Fix |
|---|---|
| `Unauthorised` | Token revoked or wrong scope; create a new **verifier** token |
| `Could not launch Chromium` | `npx playwright install chromium` (+ `install-deps` on Linux) or set `WB_CHROMIUM` |
| Arabic shows as boxes | Install the fonts listed in step 2 |
| Many runs released with `probe did not load` | The site blocks `/assets/js/probe.js` or `/render/*` — check `.htaccess` / firewall rules |
| WebGL tasks fail `canvas`/`nonblank` | Remove `--no-swiftshader`, or install GPU drivers |
