# WOAIBench.si — دليل النشر على Hostinger · Deployment guide (Hostinger)

[العربية](#العربية) · [English](#english)

> لا تكتب أي كلمة مرور أو مفتاح في هذا الملف أو في أي ملف داخل المستودع.
> Never write a password or API key into this file or anywhere in the repository.

---

<div dir="rtl">

## العربية

### 0. قبل أن تبدأ

- حساب Hostinger مع النطاق `woaibench.si` موجّهًا إلى الاستضافة.
- ملف الحزمة `woaibench-si-<الإصدار>.zip` (يُبنى بالأمر `bash tools/build-zip.sh` من نسخة المستودع).
- مفتاح OpenRouter (اختياري أثناء التثبيت): من [openrouter.ai/keys](https://openrouter.ai/keys)، ويُستحسن ضبط
  **سقف شهري للرصيد** على المفتاح نفسه.

### 1. رفع الملفات إلى `public_html`

**الطريقة الموصى بها — مدير الملفات:**
1. hPanel ← **Files** ← **File Manager** ← افتح المجلد `public_html`.
2. احذف صفحة Hostinger الافتراضية إن وُجدت (`default.php` أو `index.html`).
3. زر **Upload** ← اختر ملف الـ zip ← بعد الرفع انقر عليه بالزر الأيمن ← **Extract** ← استخرجه في `public_html` نفسه
   (الملفات داخل الحزمة في الجذر مباشرة، دون مجلد إضافي).
4. تأكد من وجود الملف المخفي `.htaccess` في `public_html` (فعّل «إظهار الملفات المخفية» إن لزم).
5. احذف ملف الـ zip بعد الاستخراج.

**بديل — FTP:** من hPanel ← Files ← FTP Accounts خذ بيانات الاتصال، وارفع محتوى الحزمة إلى `public_html` ببرنامج
مثل FileZilla، مع تفعيل «إظهار الملفات المخفية» كي لا يُتجاهل `.htaccess`.

**بديل — Git:** hPanel ← Advanced ← **Git** يتيح سحب مستودع إلى `public_html`. انتبه: المستودع يحتوي مجلدات
التطوير (`tools/dev` و`docs`)؛ هي محجوبة عن الويب عبر `.htaccess`، لكن الحزمة أنظف.

**الصلاحيات:** المجلدات 755 والملفات 644. يجب أن تكون المجلدات `config` و`storage` و`uploads` قابلة للكتابة
(وهي كذلك افتراضيًا على Hostinger). لا تستخدم 777.

### 2. اختيار إصدار PHP

hPanel ← **Advanced** ← **PHP Configuration** ← تبويب **PHP version** ← اختر **8.3** (أو 8.2) ← **Update**.
في تبويب **PHP extensions** تأكد من تفعيل: `pdo_mysql` و`curl` و`mbstring` و`openssl` و`fileinfo` و`gd` و`intl`.

### 3. قاعدة البيانات

1. hPanel ← **Databases** ← **Management** (MySQL Databases).
2. أنشئ قاعدة بيانات ومستخدمًا. تضيف Hostinger معرّف حسابك في بداية الاسمين تلقائيًا، فيصبحان بالشكل
   `u123456789_xxxx` (اسم قاعدة البيانات) و`u123456789_yyyy` (المستخدم).
3. اختر كلمة مرور قوية واحفظها في مدير كلمات المرور — **لا تضعها في أي ملف أو رسالة**.
4. اربط المستخدم بقاعدة البيانات مع **جميع الصلاحيات** (All privileges).

**لا تحتاج إلى Remote MySQL.** الموقع يتصل بقاعدة البيانات محليًا عبر المضيف `localhost`.
أما اسم الخادم بصيغة `srvXXXX.hstgr.io` أو عنوان IP الخادم فهما للاتصال **من أجهزة أخرى** فقط (مثل برنامج
إدارة قواعد بيانات على حاسوبك)، وهذا يتطلب تفعيل Remote MySQL ولا علاقة له بعمل الموقع.

### 4. شهادة SSL

hPanel ← **Security** ← **SSL** ← فعّل الشهادة المجانية للنطاق (Install / Activate). انتظر حتى تصبح «Active».
ملف `.htaccess` يحوّل كل الزيارات إلى `https://` تلقائيًا ويحذف `www.` من العنوان.

### 5. تشغيل معالج التثبيت

افتح **https://woaibench.si/install/** واتبع الخطوات:

1. **المتطلبات** — يجب أن تكون البنود الإلزامية خضراء. تنبيه HTTPS يختفي بعد تفعيل SSL.
2. **قاعدة البيانات** — المضيف `localhost`، المنفذ `3306`، ثم اسم قاعدة البيانات والمستخدم وكلمة المرور من الخطوة 3.
   يختبر المعالج الاتصال وإصدار الخادم ودعم utf8mb4 وصلاحية إنشاء الجداول. كلمة المرور لا تُعرض مرة أخرى أبدًا.
3. **الموقع والمالك** — عنوان الموقع (`https://woaibench.si`)، والاسم، واللغة الافتراضية، وحساب المدير
   (كلمة مرور من 12 حرفًا على الأقل)، ومفتاح OpenRouter اختياريًا مع زر «اختبر المفتاح».
4. **التثبيت** — يُنشئ الجداول، ويحمّل المسارات والمهام وملفات العتاد، ويُنشئ حساب المالك، ويكتب
   `config/config.local.php` بمفاتيح سرية جديدة، ثم يقفل نفسه بالملف `install/install.lock`.

بعد النجاح: **احذف المجلد `install`** من مدير الملفات (الأكثر أمانًا، مع أن القفل يمنع تشغيله).

### 6. المهمة المجدولة (Cron)

hPanel ← **Advanced** ← **Cron Jobs** ← أضف مهمة جديدة:

- النوع: **Custom** (أمر مخصص).
- الأمر (تعرض الشاشة الأخيرة للمعالج المسار الدقيق لموقعك):

<div dir="ltr">

```
/usr/bin/php /home/u123456789/domains/woaibench.si/public_html/cron/worker.php --seconds=55
```

</div>

- التوقيت: **كل دقيقة** — `* * * * *` (Minute, Hour, Day, Month, Weekday كلها `*`).

تعمل كل نسخة 55 ثانية ثم تنتهي، والنسخ المتداخلة تخرج فورًا بفضل قفل ملف. للتحقق: لوحة الإدارة ← لوحة المعلومات
تعرض آخر نبضة للعامل. إن ظهر في سجل المهمة أن إصدار PHP قديم، فاستخدم مسار PHP 8.x الخاص بسطر الأوامر الذي
يرشدك إليه دعم Hostinger.

**بلا مهام مجدولة؟** تعرض الشاشة الأخيرة للمعالج عنوان `…/worker/tick?key=…` يمكن لخدمة web-cron خارجية
استدعاؤه كل دقيقة. عامله ككلمة مرور.

### 7. حدود PHP

الطلبات الطويلة إلى النماذج تحتاج وقتًا وذاكرة:

- hPanel ← Advanced ← PHP Configuration ← تبويب **PHP options**:
  `max_execution_time` = **300** أو أكثر، و`memory_limit` = **256M**، و`upload_max_filesize` و`post_max_size` = 32M.
- أو أنشئ الملف `public_html/.user.ini` (محجوب عن الويب عبر `.htaccess`):

<div dir="ltr">

```ini
memory_limit = 256M
max_execution_time = 300
upload_max_filesize = 32M
post_max_size = 32M
```

</div>

قد يستغرق تطبيق `.user.ini` بضع دقائق. أوامر الـ Cron (سطر الأوامر) لا تخضع عادةً لحد زمن التنفيذ.

### 8. تشغيل أول قياس

1. ادخل إلى **https://woaibench.si/admin** بحساب المالك.
2. **الإعدادات**: تأكد من مفتاح OpenRouter ومن الموسم (`YYYY.MM`) ووضع التحقق.
3. **النماذج ← المزامنة مع OpenRouter**: ابحث عن النماذج المطلوبة وفعّلها.
4. **المحكّمون**: علّم ثلاثة نماذج على الأقل من شركات **مختلفة** كمحكّمين ورتّبها (يفضّل أن تدعم الصور).
5. **الحملات ← حملة جديدة**: اختر النماذج والمهام وعدد المحاولات، وراجع **تقدير التكلفة**، ثم ابدأ.
6. أبقِ **وحدة التحكم** مفتوحة أو اعتمد على الـ Cron؛ وشغّل محطة التحقق أو المتحقق الآلي للفحوص في المتصفح.
7. بعد اكتمال التغطية تظهر النتائج في لوحة الصدارة مع فواصل الثقة.

#### مهام الاختبار المحجوبة (Hold-out)

المستودع **عام**، لذلك كل المهام المضمَّنة في `sql/seed/tasks/` عامة عمدًا (افترض أن النماذج ربما رأتها
أثناء تدريبها). مهام الاختبار المحجوبة تُنشأ **داخل لوحة الإدارة فقط**: عبر **مصنع المهام (Task Forge)**
(مسودة ← مراجعة ← تفعيل كمهمة محجوبة) أو عبر محرّر المهام مع ضبط الظهور على «محجوبة».

- لا تُضِفها إلى `sql/seed/` ولا تلتزم بها (commit) في أي مستودع، ولا تنشر نصوصها في أي مكان عام.
- تبقى في قاعدة بياناتك وحدها؛ لذا اشمل قاعدة البيانات في النسخ الاحتياطي. مزامنة البذور لا تحذفها ولا تعدّلها.
- الموقع العام يعرض عددها فقط، ولا يعرض نصوصها ولا مخرجاتها، وتُفتح للمتحقق برمز مؤقت فقط.
- جدّدها كل موسم، وأوقف المهمة التي يُشتبه في تسرّبها.

### 9. ضبط التكاليف

- اضبط **سقف رصيد** على مفتاح OpenRouter نفسه؛ هذا هو صمّام الأمان الأهم.
- ابدأ بحملة صغيرة (نموذجان × بضع مهام × محاولة واحدة) لمعايرة التقدير قبل الحملات الكاملة.
- راجع تقدير التكلفة قبل كل حملة، ويمكنك إيقاف أي حملة مؤقتًا أو إلغاؤها في أي لحظة.
- النماذج ذات الاستدلال الطويل أغلى بكثير؛ اضبط `gen_max_tokens` بما يناسب.
- اختر محكّمين جيدين واقتصاديين؛ التحكيم يُضاف إلى تكلفة كل تشغيل.
- توليد الصور في استوديو الهوية مدفوع أيضًا.
- راقب الإنفاق في لوحة المعلومات وفي صفحة النشاط على OpenRouter.

### 10. المتحقق الآلي (اختياري، على حاسوب أو VPS)

يحتاج Node.js 18 أو أحدث:

<div dir="ltr">

```
cd tools/verifier-node
npm install
npx playwright install chromium
node verify.mjs --site https://woaibench.si --token <verifier-token>
```

</div>

أنشئ الرمز من لوحة الإدارة ← رموز API (النطاق verifier). يُعرض الرمز مرة واحدة ويُخزَّن مُجزّأً (hash).
يمكن إلغاؤه في أي وقت. بديل بلا تثبيت: صفحة محطة التحقق في لوحة الإدارة داخل متصفحك.

### 11. النسخ الاحتياطي

- hPanel ← **Files** ← **Backups**: نسخ تلقائية بحسب الباقة، ويمكن تنزيل نسخة من الملفات وقاعدة البيانات.
- قاعدة البيانات يدويًا: hPanel ← Databases ← **phpMyAdmin** ← Export ← SQL.
- احتفظ بنسخة آمنة (مشفّرة) من `config/config.local.php` ومن المجلد `uploads/`.
- خذ نسخة قبل كل ترقية.

### 12. الترقية إلى إصدار جديد

1. خذ نسخة احتياطية (القسم 11).
2. ارفع حزمة الإصدار الجديد واستخرجها فوق `public_html` (تُستبدل الملفات البرمجية فقط؛ الحزمة لا تحتوي
   `config/config.local.php` ولا محتوى `uploads/` فيبقيان كما هما).
3. لوحة الإدارة ← الإعدادات ← **ترقية قاعدة البيانات** (يطبّق تغييرات المخطط والترحيلات الجديدة مرة واحدة).
4. ثم **مزامنة البيانات الأولية** (تضيف المهام الجديدة وتحدّث المهام التي لم يعدّلها مدير؛ لا تحذف شيئًا).
5. إن أعادت الحزمة إنشاء المجلد `install` فاحذفه مجددًا (لن يعمل على أي حال لوجود ملف الإعدادات).

### 13. حل المشكلات

| العَرَض | الحل |
|---|---|
| خطأ 500 | راجع `storage/logs/app-YYYY-MM-DD.log` و`storage/logs/php-error.log`. للتشخيص فقط يمكنك وضع `'debug' => true` في `config/config.local.php` ثم إعادته إلى `false`. |
| صفحة بيضاء | غالبًا إصدار PHP غير مناسب أو امتداد ناقص أو نفاد الذاكرة: تحقق من القسمين 2 و7 ومن `php-error.log`. |
| الصفحة الرئيسية تعمل وبقية الروابط 404 | ملف `.htaccess` مفقود (ملف مخفي لم يُرفع) أو معطوب: ارفعه من الحزمة. |
| انتهاء المهلة (Timeout) | ارفع `max_execution_time` (القسم 7)؛ العمل الطويل يجري في الـ Cron، ويمكن خفض `gen_timeout_s` من الإعدادات. |
| «الموقع مثبّت بالفعل» | المعالج مقفل عمدًا. للترقية استخدم القسم 12؛ لإعادة التثبيت اتبع التعليمات الظاهرة في الصفحة. |
| فشل الاتصال بقاعدة البيانات | المضيف `localhost`، والمستخدم مرتبط بقاعدة البيانات بكل الصلاحيات، والاسمان يبدآن بمعرّف الحساب. |
| لا تتقدّم الحملات | تأكد من عمل الـ Cron (آخر نبضة في لوحة المعلومات) أو افتح وحدة التحكم؛ وتأكد من صلاحية مفتاح OpenRouter ورصيده. |
| تشغيلات عالقة في «بانتظار التحقق» | شغّل محطة التحقق أو المتحقق الآلي، أو غيّر وضع التحقق إلى optional. |

</div>

---

## English

### 0. Before you start

- A Hostinger plan with the domain `woaibench.si` pointed at it.
- The package `woaibench-si-<version>.zip` (build it with `bash tools/build-zip.sh` from a repository checkout).
- An OpenRouter API key (optional during installation) from [openrouter.ai/keys](https://openrouter.ai/keys) —
  give the key itself a **monthly credit limit**.

### 1. Upload the files to `public_html`

**Recommended — File Manager:**
1. hPanel → **Files** → **File Manager** → open `public_html`.
2. Delete Hostinger's placeholder page if present (`default.php` or `index.html`).
3. **Upload** the zip → right-click it → **Extract** into `public_html` itself (the package has its files at the
   root, no wrapping folder).
4. Make sure the hidden `.htaccess` file is in `public_html` (enable "show hidden files" if needed).
5. Delete the zip afterwards.

**Alternative — FTP:** get the credentials in hPanel → Files → FTP Accounts and upload the package contents to
`public_html` with a client such as FileZilla, with hidden files shown so `.htaccess` is not skipped.

**Alternative — Git:** hPanel → Advanced → **Git** can deploy a repository into `public_html`. The repository also
contains development folders (`tools/dev`, `docs`); `.htaccess` blocks them from the web, but the package is cleaner.

**Permissions:** folders 755, files 644. `config`, `storage` and `uploads` must be writable (they are by default on
Hostinger). Never use 777.

### 2. Select the PHP version

hPanel → **Advanced** → **PHP Configuration** → **PHP version** tab → choose **8.3** (or 8.2) → **Update**.
In the **PHP extensions** tab make sure `pdo_mysql`, `curl`, `mbstring`, `openssl`, `fileinfo`, `gd` and `intl`
are enabled.

### 3. The database

1. hPanel → **Databases** → **Management** (MySQL Databases).
2. Create a database and a user. Hostinger prefixes both with your account ID, so they look like
   `u123456789_xxxx` (database) and `u123456789_yyyy` (user).
3. Pick a strong password and keep it in a password manager — **never put it in a file or a message**.
4. Assign the user to the database with **All privileges**.

**Remote MySQL is NOT needed.** The site reaches its database locally through the host `localhost`.
The `srvXXXX.hstgr.io` hostname and the server IP are only for connections **from other machines** (for example a
database client on your PC), which requires Remote MySQL and has nothing to do with the site itself.

### 4. SSL certificate

hPanel → **Security** → **SSL** → install/activate the free certificate for the domain and wait until it is
"Active". The bundled `.htaccess` then redirects every request to `https://` and drops `www.`.

### 5. Run the installer

Open **https://woaibench.si/install/** and follow the wizard:

1. **Requirements** — all required items must be green. The HTTPS warning disappears once SSL is active.
2. **Database** — host `localhost`, port `3306`, then the database name, user and password from step 3. The
   installer tests the connection, server version, utf8mb4 support and the right to create tables. The password
   is never shown again.
3. **Site & owner** — site address (`https://woaibench.si`), name, default language, the owner account (password
   of at least 12 characters) and, optionally, the OpenRouter key with a **Test key** button.
4. **Install** — creates the tables, loads tracks, tasks and hardware profiles, creates the owner account, writes
   `config/config.local.php` with fresh secret keys and locks itself with `install/install.lock`.

Afterwards, **delete the `install` folder** in File Manager (the lock already blocks it; deleting is safest).

### 6. Cron job

hPanel → **Advanced** → **Cron Jobs** → create a job:

- Type: **Custom**.
- Command (the installer's final screen shows the exact path for your site):

```
/usr/bin/php /home/u123456789/domains/woaibench.si/public_html/cron/worker.php --seconds=55
```

- Schedule: **every minute** — `* * * * *` (Minute, Hour, Day, Month and Weekday all `*`).

Each run works for 55 seconds and exits; overlapping runs exit immediately thanks to a file lock. Check that it
works on the admin dashboard (worker heartbeat). If the cron output says the PHP version is too old, use the
PHP 8.x command-line binary path that Hostinger support gives you.

**No cron on your plan?** The installer's final screen shows a private `…/worker/tick?key=…` address that an
external web-cron service can call every minute. Treat it like a password.

### 7. PHP limits

Long model calls need time and memory:

- hPanel → Advanced → PHP Configuration → **PHP options** tab: `max_execution_time` = **300** or more,
  `memory_limit` = **256M**, `upload_max_filesize` and `post_max_size` = 32M.
- Or create `public_html/.user.ini` (blocked from the web by `.htaccess`):

```ini
memory_limit = 256M
max_execution_time = 300
upload_max_filesize = 32M
post_max_size = 32M
```

`.user.ini` changes can take a few minutes to apply. Cron (command-line) runs are normally not subject to the
execution-time limit.

### 8. Run the first benchmark

1. Sign in at **https://woaibench.si/admin** with the owner account.
2. **Settings**: check the OpenRouter key, the season (`YYYY.MM`) and the verification mode.
3. **Models → Sync from OpenRouter**: find the models you want and enable them.
4. **Judges**: mark at least three models from **different vendors** as judges and order them (vision-capable is best).
5. **Campaigns → New**: choose models, tasks and attempts, review the **cost estimate**, start.
6. Keep **Console** open or rely on cron; run the Verification Station or the headless verifier for browser checks.
7. Once coverage is complete, scores appear on the leaderboard with confidence intervals.

#### Hold-out tasks

The repository is **public**, so every task shipped in `sql/seed/tasks/` is deliberately public (assume models
may have seen it during training). Hold-out tasks are created **only inside the admin panel**: with the
**Task Forge** (draft → review → activate as hold-out) or with the task editor, visibility *Hold-out*.

- Never add them to `sql/seed/`, never commit them to any repository, never post their prompts anywhere public.
- They live only in your database, so include the database in your backups. Seed sync never deletes or edits them.
- The public site shows how many exist, never their prompts or outputs; verifiers open them with a short-lived token only.
- Refresh them every season and deactivate any task you suspect has leaked.

### 9. Cost control

- Put a **credit limit on the OpenRouter key itself** — the most important safety valve.
- Start small (2 models × a few tasks × 1 attempt) to calibrate the estimate before full campaigns.
- Review the estimate before every campaign; pause or cancel any campaign at any time.
- Long-reasoning models cost far more; tune `gen_max_tokens`.
- Choose good but economical judges — judging is added to every run.
- Image generation in the Brand Studio is paid as well.
- Watch spending on the dashboard and on OpenRouter's activity page.

### 10. Headless verifier (optional, PC or VPS)

Requires Node.js 18+:

```
cd tools/verifier-node
npm install
npx playwright install chromium
node verify.mjs --site https://woaibench.si --token <verifier-token>
```

Create the token in Admin → API tokens (scope `verifier`). It is shown once and stored hashed; revoke it at any
time. No-install alternative: the Verification Station page in the admin panel, in your own browser.

### 11. Backups

- hPanel → **Files** → **Backups**: automatic backups depending on the plan; files and databases can be downloaded.
- Database by hand: hPanel → Databases → **phpMyAdmin** → Export → SQL.
- Keep a safe (encrypted) copy of `config/config.local.php` and of `uploads/`.
- Take a backup before every upgrade.

### 12. Updating to a new version

1. Back up (section 11).
2. Upload the new package and extract it over `public_html` (only code is replaced: the package never contains
   `config/config.local.php` or the contents of `uploads/`, so both are kept).
3. Admin → Settings → **Migrate database** (applies schema changes and new migrations exactly once).
4. Then **Sync seeds** (adds new tasks and updates tasks no administrator has edited; never deletes anything).
5. If the package re-created the `install` folder, delete it again (it refuses to run anyway because the config
   file exists).

### 13. Troubleshooting

| Symptom | Fix |
|---|---|
| HTTP 500 | Read `storage/logs/app-YYYY-MM-DD.log` and `storage/logs/php-error.log`. For diagnosis only, set `'debug' => true` in `config/config.local.php`, then back to `false`. |
| Blank page | Usually a wrong PHP version, a missing extension or memory exhaustion: see sections 2 and 7 and `php-error.log`. |
| Home page works, other links 404 | `.htaccess` is missing (hidden file not uploaded) or damaged: upload it from the package. |
| Timeouts | Raise `max_execution_time` (section 7); long work runs in cron; lower `gen_timeout_s` in Settings if needed. |
| "Already installed" | The installer is locked on purpose. To upgrade see section 12; to reinstall follow the steps shown on that page. |
| Database connection fails | Host `localhost`; the user is assigned to the database with all privileges; both names carry the account prefix. |
| Campaigns do not progress | Check cron (heartbeat on the dashboard) or open Console; check the OpenRouter key and its credit. |
| Runs stuck in "awaiting verification" | Run the Verification Station or the headless verifier, or switch the verification mode to `optional`. |
