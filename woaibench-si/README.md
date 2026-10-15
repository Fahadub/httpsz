# WOAIBench.si

**المؤشر العلمي لقياس نماذج الذكاء الاصطناعي في البرمجة — The Scientific Index of AI coding models**

[العربية](#العربية) · [English](#english)

---

<div dir="rtl">

## العربية

### ما هو WOAIBench.si؟

موقع ثنائي اللغة (العربية من اليمين إلى اليسار افتراضيًا، والإنجليزية) يقيس أداء نماذج الذكاء الاصطناعي في
أعمال برمجية عملية: واجهات، وألعاب، ومشاهد ثلاثية الأبعاد، ورسوم بيانية، وخوارزميات، وإصلاح أخطاء، ومهام وكيلية،
واستدلال، وبرمجة آمنة، وإمكانية الوصول، ومسار خاص بالعربية واتجاه RTL.
هدفه أن يكون **أدق وأكثر شفافية**: كل رقم يمكن تتبّعه إلى المخرجات والفحوص وأحكام المحكّمين التي صنعته.

الموقع مكتوب بـ PHP 8.1+ و MySQL/MariaDB فقط، دون Composer ولا خطوة بناء، ويعمل على استضافة مشتركة مثل Hostinger.

### المزايا

- **محاولات متكررة لكل مهمة** (3 افتراضيًا) مع المتوسط والتباين، و**فواصل ثقة 95%** بطريقة bootstrap الطبقية.
- **ترتيب إحصائي**: لا يتقدّم نموذج على آخر إلا إذا كان الفرق أكبر من هامش الخطأ.
- **لجنة تحكيم متعددة الشركات**: ثلاثة محكّمين من شركات مختلفة، والوسيط هو الحكم، ولا يحكّم نموذج على شركته،
  ويُضاف محكّم رابع تلقائيًا عند الخلاف الكبير. التحكيم **أعمى** (لا يرى المحكّم اسم النموذج).
- **معايير تقييم مفصّلة** بأوزان ونقاط معايرة بدل رقم واحد عام.
- **تقييم هجين**: فحوص آلية حتمية (ثابتة، وفي متصفح حقيقي، واختبارات وحدات) تُمزج بأحكام المحكّمين.
- **مسارات حتمية** للخوارزميات والاستدلال والمهام الوكيلية تُصحَّح باختبارات وإجابات دقيقة دون رأي نموذج لغوي.
- **التحكم في التلوث**: مجموعة علنية ومجموعة **محجوبة** لا تُنشر أبدًا، ومهام جديدة كل موسم.
- **فصل أعطال البنية التحتية** عن أخطاء النموذج: أخطاء المزوّد تُعاد ولا تُحتسب.
- **شفافية كاملة** للمهام العلنية: المخرجات ولقطات الشاشة ونتائج الفحوص ومبررات المحكّمين.
- **التكلفة وزمن الاستجابة** لكل مهمة، وحدّ باريتو للقيمة مقابل السعر.
- **ساحة بشرية عمياء** بتصويت ثنائي وتقييم Bradley–Terry مع فواصل ثقة.
- **لوحة صدارة كاملة مجانية**، ومقارنة حتى 4 نماذج، وتاريخ النتائج، و**مستشار** بأوزان شخصية،
  و**مختبر الملاءمة المحلية** (هل يعمل النموذج المفتوح على جهازك؟)، وواجهة JSON عامة و CSV، وشارات SVG قابلة للتضمين.

### المتطلبات

- PHP ‏8.1 أو أحدث (يُنصح بـ 8.2 أو 8.3) مع الامتدادات: `pdo_mysql` و`curl` و`json` و`mbstring` و`openssl`
  و`fileinfo`، ويُستحسن `gd` و`intl`.
- MySQL ‏5.7+ أو MariaDB ‏10.3+ بترميز utf8mb4.
- Apache أو LiteSpeed مع `mod_rewrite` وملفات `.htaccess` (متوفرة على Hostinger).
- مفتاح [OpenRouter](https://openrouter.ai/keys) لتشغيل القياسات (يمكن إضافته بعد التثبيت).
- اختياري: جهاز أو خادم VPS عليه Node.js لتشغيل المتحقق الآلي من المتصفح.

### التثبيت

1. ابنِ الحزمة: `bash tools/build-zip.sh` ← ينتج `dist/woaibench-si-<الإصدار>.zip`.
2. ارفعها إلى `public_html` واستخرجها (مدير الملفات في hPanel).
3. أنشئ قاعدة بيانات MySQL ومستخدمًا لها من hPanel.
4. افتح `https://نطاقك/install/` واتبع المعالج: فحص المتطلبات، ثم قاعدة البيانات (المضيف `localhost`)،
   ثم بيانات الموقع وحساب المالك ومفتاح OpenRouter الاختياري، ثم التثبيت.
5. احذف مجلد `install` بعد الانتهاء (المعالج يقفل نفسه على أي حال).

الدليل الكامل خطوة بخطوة لاستضافة Hostinger في [docs/DEPLOY.md](docs/DEPLOY.md).

### المهمة المجدولة (Cron)

العامل الخلفي ينفّذ التوليد والتحكيم والتجميع. أضف من hPanel ← Advanced ← Cron Jobs مهمة تعمل **كل دقيقة**:

<div dir="ltr">

```
/usr/bin/php /home/<user>/domains/<domain>/public_html/cron/worker.php --seconds=55
```

</div>

تعرض الشاشة الأخيرة في المعالج المسار الدقيق لموقعك. وإلى حين إعداد المهمة يمكنك إبقاء صفحة
لوحة الإدارة ← وحدة التحكم مفتوحة في المتصفح لتشغيل الطابور نفسه.

### المتحقق الآلي (اختياري)

لا تتوفر المتصفحات على الاستضافة المشتركة، لذا تُنفَّذ فحوص المتصفح الحقيقي بإحدى طريقتين:
محطة التحقق داخل لوحة الإدارة (تبويب مفتوح في متصفحك)، أو المتحقق الآلي `tools/verifier-node` على أي حاسوب
أو خادم VPS باستخدام رمز تحقق من لوحة الإدارة ← رموز API:

<div dir="ltr">

```
cd tools/verifier-node && npm install && npx playwright install chromium
node verify.mjs --site https://woaibench.si --token <verifier-token>
```

</div>

### الترقية إلى إصدار جديد

ارفع ملفات الإصدار الجديد فوق الحالية (الحزمة لا تحتوي `config/config.local.php` ولا محتوى `uploads/` فيبقيان كما هما)،
ثم من لوحة الإدارة ← الإعدادات: **ترقية قاعدة البيانات** ثم **مزامنة البيانات الأولية**.
المزامنة لا تحذف شيئًا ولا تستبدل مهمة عدّلها مدير إلا إذا طلبت ذلك صراحة.

### ملاحظات أمنية

- الأسرار (كلمة مرور قاعدة البيانات، مفتاح OpenRouter، `app_secret`) موجودة فقط في `config/config.local.php`
  الذي يكتبه المعالج، ولا يُرفع إلى المستودع ولا يُضمَّن في الحزمة، ومحميّ بملف `.htaccess`.
- مخرجات النماذج غير موثوقة: تُعرض داخل إطار معزول (`sandbox`) من أصل معتم، ولا تُدرج في صفحات الموقع أبدًا.
- جميع طلبات POST محمية من CSRF، ولوحة الإدارة بكلمات مرور مشفّرة bcrypt، مع حدود لمعدل الطلبات.
- إذا تسرّب مفتاح: ألغِه وأنشئ غيره من [openrouter.ai/keys](https://openrouter.ai/keys)، وغيّر كلمة مرور قاعدة البيانات من hPanel.
  التفاصيل في [docs/SECURITY.md](docs/SECURITY.md).

### التطوير المحلي

<div dir="ltr">

```
php -S 127.0.0.1:8080 index.php          # the front controller is also the router script
WB_DB_NAME=my_local_db php tools/dev/fixtures.php   # fictional sample data, local only
```

</div>

البيانات التجريبية في `tools/dev` وهمية تمامًا (شركات ونماذج مخترعة) ولا تُنشر أبدًا على أنها نتائج حقيقية.
عقد البنية الكامل في `docs/ARCHITECTURE.md`.

</div>

---

## English

### What is WOAIBench.si?

A bilingual (Arabic right-to-left by default, plus English) website that benchmarks AI models on practical
coding work: interfaces, games, 3D scenes, data visualisation, algorithms, debugging, agentic tool use,
reasoning, secure coding, accessibility, and an Arabic & RTL track. It is built to be **more accurate and more
transparent**: every number can be traced back to the outputs, checks and judge verdicts that produced it.

Plain PHP 8.1+ and MySQL/MariaDB — no Composer, no build step — designed for shared hosting such as Hostinger.

### Features

- **Repeated attempts** per task (3 by default) with mean and variance, and **95% confidence intervals** from a
  stratified bootstrap.
- **Statistical rank**: a model only outranks another when the gap is larger than the margin of error.
- **Multi-vendor judge panel**: three judges from different vendors, median verdict, judges never grade their own
  vendor, an automatic tie-break judge when the panel disagrees, and **blind judging** (no model names).
- **Criterion-level rubrics** with weights and calibration anchors instead of one holistic number.
- **Hybrid scoring**: deterministic automated checks (static, real-browser and unit tests) blended with judges.
- **Deterministic tracks** — algorithms, reasoning and agentic tasks are graded by tests and exact answers.
- **Contamination control**: a public set, a private **hold-out** set that is never published, fresh tasks each season.
- **Infrastructure ≠ model failure**: provider errors are retried and excluded.
- **Full transparency** for public tasks: outputs, screenshots, check results and judge rationales.
- **Cost & latency** per task and a price-performance Pareto frontier.
- **Blind human Arena** with Bradley–Terry ratings and confidence intervals.
- **Free full leaderboard**, Compare (up to 4 models), score history, a personal **Advisor**, the **Local Fit Lab**
  (will an open model run on my hardware?), public JSON API + CSV export and embeddable SVG badges.

### Requirements

- PHP 8.1+ (8.2 or 8.3 recommended) with `pdo_mysql`, `curl`, `json`, `mbstring`, `openssl`, `fileinfo`;
  `gd` and `intl` recommended.
- MySQL 5.7+ or MariaDB 10.3+ with utf8mb4.
- Apache or LiteSpeed with `mod_rewrite` and `.htaccess` support (standard on Hostinger).
- An [OpenRouter](https://openrouter.ai/keys) API key to run benchmarks (can be added after installation).
- Optional: any PC or VPS with Node.js for the headless browser verifier.

### Installation

1. Build the package: `bash tools/build-zip.sh` → `dist/woaibench-si-<version>.zip`.
2. Upload it to `public_html` and extract it (hPanel File Manager).
3. Create a MySQL database and user in hPanel.
4. Open `https://your-domain/install/` and follow the wizard: requirements → database (host `localhost`) →
   site, owner account and optional OpenRouter key → install.
5. Delete the `install` folder afterwards (the installer locks itself either way).

Step-by-step Hostinger guide: [docs/DEPLOY.md](docs/DEPLOY.md).

### Cron job

The background worker runs generation, judging and aggregation. In hPanel → Advanced → Cron Jobs add a job that
runs **every minute**:

```
/usr/bin/php /home/<user>/domains/<domain>/public_html/cron/worker.php --seconds=55
```

The installer's final screen prints the exact path for your site. Until the cron job exists, keeping
Admin → Console open in a browser tab drives the same queue.

### Verifier (optional)

Shared hosting has no browser, so real-browser checks run either in the admin **Verification Station** (a tab in
your own browser) or with the headless verifier `tools/verifier-node` on any PC/VPS, using a verifier token from
Admin → API tokens:

```
cd tools/verifier-node && npm install && npx playwright install chromium
node verify.mjs --site https://woaibench.si --token <verifier-token>
```

### Upgrading

Upload the new release over the existing files (the package never contains `config/config.local.php` or the
contents of `uploads/`, so both are kept), then in Admin → Settings run **Migrate database** and **Sync seeds**.
The seed sync never deletes anything and never overwrites a task an administrator edited unless you ask it to.

### Security notes

- Secrets (database password, OpenRouter key, `app_secret`) live only in `config/config.local.php`, written by the
  installer, never committed, never packaged, and blocked from the web by `.htaccess`.
- Model output is untrusted: it is only rendered inside sandboxed iframes with an opaque origin, never inlined
  into site pages.
- Every POST is CSRF-protected; admin passwords use bcrypt; logins, votes and APIs are rate-limited.
- If a key leaks: revoke it and create a new one at [openrouter.ai/keys](https://openrouter.ai/keys), and change the
  database password in hPanel. Details in [docs/SECURITY.md](docs/SECURITY.md).

### Local development

```
php -S 127.0.0.1:8080 index.php                     # the front controller is also the router script
WB_DB_NAME=my_local_db php tools/dev/fixtures.php   # fictional sample data, local only
```

Fixture data in `tools/dev` is entirely invented (fictional vendors and models) and must never be published as
real results. The full architecture contract is in `docs/ARCHITECTURE.md`.
