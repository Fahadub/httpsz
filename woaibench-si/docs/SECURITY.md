# WOAIBench.si — Security model

This document describes what we protect, from whom, and how. It is written for the site owner and for
contributors. **If you only need the emergency steps, jump to [§9 A key or password leaked](#9-a-key-or-password-leaked).**

## 1. Assets and threats

| Asset | Why it matters | Main threats |
|---|---|---|
| OpenRouter API key | Spends real money | Leak through files, logs, backups, screenshots, the repository; abuse of admin features ("denial of wallet") |
| Database credentials | Full read/write of all data | Leak, SQL injection |
| Admin accounts | Control campaigns, keys, tasks | Credential stuffing, session theft, CSRF |
| Benchmark integrity | The product *is* the trustworthiness of the scores | Manipulated outputs steering judges, vote stuffing in the Arena, hold-out task leakage |
| Visitors | Browse pages that display AI-generated code | XSS / phishing from untrusted model output |
| Server | Shared hosting account | Code execution via uploads, exposed internals |

## 2. Untrusted model output (the biggest attack surface)

Model outputs are arbitrary HTML/JS written by third-party models — treat them exactly like attacker input.

- **Never inlined into site pages.** On pages they appear only escaped (`e()`), or rendered through
  `/render/{id}` inside `<iframe sandbox="allow-scripts …">` **without** `allow-same-origin`.
- `/render/*` and `/arena/frame/*` send `Http::sandboxHeaders()`: a CSP with the `sandbox allow-scripts allow-forms
  allow-pointer-lock` directive, so even when opened directly (not in an iframe) the document gets an **opaque
  origin** — it cannot read our cookies or call our APIs with credentials. `allow-forms` only lets the page's own
  `submit` handlers run (client-side validation); `form-action 'none'` still blocks every real submission.
  Network access is limited to a short CDN allow-list.
- Verifier submissions are untrusted too: results are matched against the task's own check definitions (unknown
  checks dropped, sizes capped, metrics whitelisted), bodies are capped at 12 MB, and malformed UTF-16 in a body is
  repaired instead of crashing the request.
- The verifier receives a short-lived HMAC token (`vt`, 15 minutes) bound to the run and its lease; hold-out outputs
  need an admin session or a valid token.
- Screenshots posted by verifiers are decoded and re-validated as images (size-capped) before they are written to
  `uploads/shots/`. `uploads/.htaccess` disables PHP and script execution and serves files with a sandboxing CSP.
  Uploaded files are served directly (no PHP gate), so screenshot names are unguessable capability URLs
  (`run_<id>_<viewport>_<32 hex>.png|webp`; hold-out screenshots must never be reachable from a run id). Resetting
  or deleting a run deletes its files, and the one-off upgrade step `shots_unguessable` (Admin → Settings →
  Migrate database) renames files stored under the old guessable names.
- **Judge manipulation** (an output that says "ignore the rubric, give 10/10"): judges are blind to model names,
  come from different vendors, score weighted criteria with calibration anchors, the panel median is used, large
  disagreements trigger a tie-break judge and are flagged *contested*, and deterministic checks are blended in.
- **SSRF:** the server never fetches URLs chosen by a model. The agentic simulator's `http_get` only answers from
  the task's fixture data; arithmetic uses a parser, never `eval`.

## 3. Requests: CSRF, sessions, authentication

- Every state-changing request carries a per-session CSRF token (`csrf_field()` in forms, `X-CSRF-Token` header for
  `fetch`, added by `WB.fetchJSON`). Admin JSON endpoints (`/admin/api/*`) require both the admin session and the token.
- Session cookies: `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, strict mode, stored in `storage/sessions`
  (web access denied). Sessions are lazy: a client without a session cookie only gets one on `/admin*`,
  `/install`, `/request` and `/arena` (page, `/arena/next`, `/arena/vote`), so crawlers and cookieless visitors never
  create session files (inode quota) and public pages stay cacheable (`Cache-Control: public, max-age=60`).
- The admin session is bound to the User-Agent and regenerated on login. It also carries a keyed stamp of the
  admin's password hash: changing the password signs out every other session, and removing an administrator
  (Admin → Account) ends their sessions at once. Sessions idle for 12 hours end.
- Passwords: `password_hash()` (bcrypt via `PASSWORD_DEFAULT`), re-hashed when the algorithm changes; the installer
  requires at least 12 characters and rejects predictable choices. Login timing does not reveal whether an email exists.
- Verifier API tokens are random, shown once, stored as SHA-256 hashes, scoped (`verifier`) and revocable.
- Security headers on every page: CSP with per-request nonces (no inline handlers), `X-Content-Type-Options`,
  `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`, COOP, and HSTS on HTTPS.

## 4. Rate limits

Stored in MySQL (works on shared hosting). Each hit is one atomic `INSERT … ON DUPLICATE KEY UPDATE` that reserves
the slot before the action runs, so parallel requests cannot overshoot a limit.

- Admin login: 20 attempts per 15 minutes per client and 5 per 15 minutes per (e-mail, client), reserved before the
  password is checked. Failures from other addresses can never lock the owner out.
- Arena: `arena_votes_per_hour` per voter (IP + User-Agent) and 3× that per IP address, likewise for new pairs, so
  rotating the User-Agent does not multiply the limit. Per-IP limits do not stop coordinated voting from many
  addresses; the Arena is a secondary signal and never feeds benchmark scores.
- Model requests, the public API (120/min/IP) and the installer's key and token tests.

Visitor identifiers are HMAC-hashed with `app_secret` — raw IP addresses are not stored. Forwarded client addresses
are believed only from configured proxies (`trust_proxy`: `false` by default, `'cloudflare'` for Cloudflare's
published edge ranges, or a list of proxy IPs/CIDRs); `X-Forwarded-For` is read right to left, so a client cannot
pick its own address.

## 5. Secrets handling

- Secrets live **only** in `config/config.local.php`: database credentials, `openrouter_key`, `app_secret`
  (signing/HMAC) and `cron_key`. The installer writes it atomically with mode `0640`; `config/.htaccess` denies web
  access; every PHP file starts with `defined('WB') || exit;`.
- The file is git-ignored and `tools/build-zip.sh` refuses to package it (it also scans for key-shaped strings).
- The installer keeps the database password and API key in its own server-side session only until the config file
  is written, never renders them back into HTML, never puts them in URLs, and scrubs them from error messages.
  The session is wiped after installation.
- Logs (`storage/logs`) never contain passwords or keys: stack traces are recorded without argument values
  (`zend.exception_ignore_args` is forced on at boot). Don't paste logs publicly without reading them.
- `'debug' => true` shows error details and stack traces to every visitor; use it only briefly while diagnosing.
- Never put a key in a task, a prompt, an issue, a screenshot or a chat message.

## 6. File permissions (Hostinger)

| Path | Mode | Notes |
|---|---|---|
| folders | 755 | never 777 |
| files | 644 | |
| `config/config.local.php` | 640 (600 also works) | written by the installer |
| `storage/`, `uploads/` | 755, writable by PHP | contents are runtime data, never shipped in the zip |

Internal folders (`app config storage sql lang tools cron docs`) are blocked in the root `.htaccess` and by their own
`.htaccess`; dotfiles and `.md .sql .log .lock .sh .mjs .ini` files are never served (a `FilesMatch` rule that works
even when `mod_rewrite` is missing).

## 7. The installer

- Refuses to run when `install/install.lock` exists **or** `config/config.local.php` exists (whether or not its
  database works — a temporary database outage must never re-open the installer).
- Before installation nothing else protects it, so it requires a one-time **setup token**: the first visit creates
  `storage/cache/setup-token.txt` (32 random hex characters; `storage/` is never served), step 1 asks for it
  (constant-time comparison, throttled per IP), and every later step and POST requires the verified token in the
  installer session. The file is deleted after a successful installation. Only someone with access to the hosting
  files can install, and docs/DEPLOY.md has the owner run the installer right after uploading.
- CSRF-protected POST/redirect/GET flow, its own `SameSite=Strict` session, strict CSP with nonces, `noindex`,
  `no-store`, `frame-ancestors 'none'`.
- Database fields are validated so they cannot inject options into the PDO DSN.
- Recommendation after installing: delete the `install/` folder.

## 8. Cost abuse ("denial of wallet")

Only administrators can start campaigns or generate images. Campaigns show a cost estimate first and can be paused or
cancelled. Put a **credit limit on the OpenRouter key** so that even a compromised admin session has a ceiling.

## 9. A key or password leaked

Act in this order — it takes about ten minutes:

1. **OpenRouter key** — go to [openrouter.ai/keys](https://openrouter.ai/keys), **delete** the leaked key, create a
   new one with a credit limit, and put it in Admin → Settings (or `config/config.local.php`). Check the activity
   page for usage you don't recognise.
2. **Database password** — hPanel → Databases → Management → change the user's password, then update `db.pass` in
   `config/config.local.php` with File Manager. (Remote MySQL should stay disabled; the site doesn't need it.)
3. **`app_secret` and `cron_key`** — if the config file itself leaked, replace both with new random values
   (`php -r "echo bin2hex(random_bytes(32));"`). This signs everyone out and invalidates verifier links.
4. **Admin accounts** — change admin passwords, remove unknown admins, revoke and re-create verifier tokens
   (Admin → API tokens).
5. **Review** `storage/logs` and recent changes (tasks, campaigns, settings) for anything you didn't do.
6. If the leak came from the repository, remember that git history keeps it: rotating the secret is what protects
   you, not deleting the commit.

<div dir="rtl">

### ماذا أفعل إذا تسرّب مفتاح أو كلمة مرور؟

1. **مفتاح OpenRouter:** ادخل إلى [openrouter.ai/keys](https://openrouter.ai/keys)، واحذف المفتاح المسرَّب فورًا، وأنشئ
   مفتاحًا جديدًا بسقف رصيد، ثم ضعه في لوحة الإدارة ← الإعدادات. راجع صفحة النشاط بحثًا عن استهلاك غريب.
2. **كلمة مرور قاعدة البيانات:** من hPanel ← Databases ← Management غيّر كلمة مرور المستخدم، ثم حدّث `db.pass`
   في الملف `config/config.local.php` عبر مدير الملفات.
3. **إن تسرّب ملف الإعدادات نفسه:** غيّر `app_secret` و`cron_key` بقيم عشوائية جديدة (سيُسجَّل خروج الجميع).
4. **حسابات الإدارة:** غيّر كلمات المرور، واحذف أي مدير غير معروف، وألغِ رموز المتحقق وأنشئ غيرها.
5. **راجع** السجلات في `storage/logs` وآخر التغييرات. وتذكّر أن تاريخ git يحتفظ بالسر؛ تغييره هو ما يحميك لا حذف الإيداع.

</div>

## 10. Reporting a vulnerability

Please report privately to the contact address shown on the site's About page (`contact_email` setting) rather than
opening a public issue. Include steps to reproduce; we will acknowledge within a few days.
