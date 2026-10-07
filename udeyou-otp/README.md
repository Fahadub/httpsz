# Udeyou OTP — منصة إرسال رموز التحقق بالبريد عبر API

منصة CPaaS بسيطة: الشركات والمتاجر تسجّل، تأخذ مفتاح API، وترسل رموز OTP لعملائها عبر سيرفرات بريد **udeyou.com**،
والرسالة تظهر في صندوق الوارد باسم متجر العميل:  **`متجر الورد <no-reply@udeyou.com>`**.

- لغة: **PHP 8.1+ بدون إطار عمل** (Vanilla PHP منظّم) + **MySQL** + **PHPMailer** (SMTP).
- واجهة بسيطة بالعربي: تسجيل / دخول / لوحة تحكم / مفاتيح / سجل الرسائل / صفحة توثيق / لوحة إدارة.
- اختبارات تكامل كاملة (42 اختبار) تعمل على قاعدة بيانات حقيقية.

---

## لماذا Vanilla PHP وليس Laravel؟

| | Vanilla PHP (المختار) | Laravel |
|---|---|---|
| الرفع على استضافة Hostinger المشتركة | رفع ملفات فقط | يحتاج إعداد أكثر (artisan، صلاحيات storage، cache) |
| حجم الكود | ~2000 سطر تقدر تقرأها كلها | آلاف الملفات |
| الأداء لكل طلب | أخف | أثقل قليلاً |
| مناسب لـ | MVP / بداية المشروع | لما يكبر المشروع (فواتير، طوابير، فريق) |

الكود مقسم بنفس فلسفة Laravel (Controllers / Services / Repositories)، فالانتقال لـ Laravel لاحقاً سهل إذا احتجته.

---

## ماذا أحتاج من الدومين `udeyou.com`؟

1. **استضافة PHP 8.1+** (أي خطة Hostinger Web/Business تكفي) والدومين مربوط فيها، مع **SSL مفعّل** (مجاني في hPanel).
2. **قاعدة بيانات MySQL** — نعم **لازم قاعدة بيانات**: لحفظ العملاء، المفاتيح، الرصيد، الرموز وسجلها. تنشئها من hPanel ← Databases (مجانية).
3. **صندوق بريد** مثل `no-reply@udeyou.com` من hPanel ← Emails، مع كلمة مروره (هذا هو "سيرفر البريد" الذي يرسل منه النظام).
4. **سجلات DNS للبريد (مهم جداً حتى لا تذهب الرسائل للـ Spam)**:
   - **SPF**: `TXT  @  "v=spf1 include:_spf.mail.hostinger.com ~all"`
   - **DKIM**: يُفعّل من hPanel ← Emails ← (إعدادات DNS / Connect domain). إذا الدومين يستخدم Nameservers هوستنجر غالباً يُضاف تلقائياً.
   - **DMARC**: `TXT  _dmarc  "v=DMARC1; p=none; rua=mailto:you@udeyou.com"` (لاحقاً ارفعه لـ `quarantine`).
   - تحقق من القيم الدقيقة من hPanel، ثم اختبر بإرسال رسالة لـ https://www.mail-tester.com
5. (اختياري) **ساب دومين** مثل `api.udeyou.com` إذا تبي الـ API منفصل عن الموقع الرئيسي.

> **لماذا لا نرسل "من" بريد المتجر نفسه؟** لو أرسلنا كـ `shop@gmail.com` من سيرفراتنا، Gmail/Outlook سيرفضونها (SPF/DMARC).
> الحل الصحيح المعمول به: **العنوان ثابت** (`no-reply@udeyou.com`) و**الاسم ديناميكي** (اسم المتجر)، ومع `reply_to` اختياري لبريد دعم المتجر.

> **Hostinger Mail API؟** على حد علمي هوستنجر لا توفر API إرسال بريد معاملاتي (مثل Amazon SES)، لذلك الطريقة العملية هي **SMTP** عبر PHPMailer.
> انتبه أن صناديق بريد هوستنجر لها **حد إرسال يومي** حسب الخطة — راجعه في hPanel. عندما يكبر الحجم انتقل لـ Amazon SES / Postmark / Brevo:
> فقط اكتب كلاس جديد يطبّق `MailCarrier` (ملف واحد) وبدون تغيير أي منطق آخر.

---

## هيكل المشروع

```
udeyou-otp/
├── public/                    ← جذر الويب (الملف الوحيد المكشوف للإنترنت)
│   ├── index.php              ← Front controller + جدول المسارات
│   ├── .htaccess              ← تحويل كل الطلبات لـ index.php + تمرير Authorization + HTTPS
│   └── assets/style.css
├── src/
│   ├── Core/                  ← Config(.env) / Database(PDO) / Router / Request / Response / Session(CSRF) / View
│   ├── Repositories/          ← كل استعلامات SQL (Clients / ApiKeys / Otps)
│   ├── Services/
│   │   ├── ApiKeyService.php  ← توليد udy_live_/udy_test_ والتحقق منها (تُخزن كـ SHA-256 فقط)
│   │   ├── CreditService.php  ← حجز/خصم/استرجاع الرصيد بشكل ذرّي + سجل حركات
│   │   ├── OtpService.php     ← منطق الإرسال والتحقق + حدود الإرسال
│   │   ├── OtpTemplate.php    ← قالب البريد (عربي/إنجليزي/قالب العميل)
│   │   └── Mail/              ← MailCarrier (واجهة) + SmtpMailCarrier (PHPMailer) + LogMailCarrier (للتجربة)
│   └── Controllers/
│       ├── Api/OtpController.php
│       └── Web/ (Auth / Dashboard / Admin)
├── views/                     ← صفحات HTML بسيطة (RTL)
├── database/schema.sql        ← جداول MySQL
├── bin/                       ← install.php / credits.php / cleanup.php
├── tests/run.php              ← اختبارات التكامل
├── .env.example               ← كل الإعدادات
└── .htaccess                  ← لو رفعت المشروع كامل داخل public_html
```

## قاعدة البيانات (4 جداول)

| الجدول | الغرض | أهم الأعمدة |
|---|---|---|
| `clients` | الشركات/المتاجر المشتركة | `company_name`, `email`, `password_hash`, **`credits`**, `is_admin`, `status` |
| `api_keys` | مفاتيح كل شركة | `mode` (live/test), `key_prefix` (للعرض), **`key_hash`** (SHA-256)، `revoked_at` |
| `otps` | كل رمز مُرسل (وهو سجل الرسائل أيضاً) | `public_id`, `recipient_email`, `sender_name`, **`code_hash`** (HMAC)، `status`, `attempts`, `expires_at` |
| `credit_transactions` | دفتر حركات الرصيد (تدقيق) | `amount` (+/-), `type` (signup_bonus/topup/otp_send/refund/adjustment) |

التفاصيل الكاملة في [`database/schema.sql`](database/schema.sql).

## كيف يعمل الإرسال (الـ Flow)

```
POST /api/v1/otp/send
  1. التحقق من المفتاح  (sha256(key) → api_keys، غير ملغي، الحساب نشط)       → 401 / 403
  2. التحقق من الحقول  (to, sender_name, template يحتوي {{code}} ...)          → 422
  3. حدود الإرسال     (60 طلب/دقيقة، دقيقة بين رسالتين لنفس البريد، 5/ساعة)     → 429
  4. Transaction: UPDATE clients SET credits = credits-1 WHERE credits >= 1   → 402 إذا صفر
                  + INSERT otps (pending)       ← مستحيل يصير الرصيد سالب حتى مع طلبات متزامنة
  5. إرسال البريد عبر SMTP باسم المتجر
       نجح  → status=sent، وإلغاء الرموز السابقة لنفس البريد → 201
       فشل  → status=failed + استرجاع الرصيد (refund)       → 502
```

**الأمان باختصار:** المفاتيح تُخزن كـ hash فقط وتظهر مرة واحدة · الرموز تُخزن كـ HMAC-SHA256 مربوط بـ `otp_id` وسرّ التطبيق ·
الرمز يُستخدم مرة واحدة وينتهي (5 دقائق افتراضياً) ويُقفل بعد 5 محاولات خاطئة (عمليات ذرّية ضد السباق) ·
حماية من حقن الهيدر في اسم المرسل · قالب العميل نص عادي يُعقّم (لا يمكن استغلال المنصة لإرسال HTML/تصيّد) ·
CSRF + جلسات آمنة في لوحة التحكم · `.env` و`src` غير قابلة للوصول من الويب.

---

## التوثيق للعملاء (الربط عبر API)

بعد التشغيل افتح **`https://udeyou.com/docs`** — فيها كل شيء مع أمثلة PHP / Node.js / Python / cURL. باختصار:

```bash
# إرسال
curl -X POST https://udeyou.com/api/v1/otp/send \
  -H "Authorization: Bearer udy_live_..." -H "Content-Type: application/json" \
  -d '{"to":"customer@gmail.com","sender_name":"متجر الورد"}'
# → {"success":true,"data":{"otp_id":"otp_...","expires_at":"...","credits_remaining":99,...}}

# تحقق
curl -X POST https://udeyou.com/api/v1/otp/verify \
  -H "Authorization: Bearer udy_live_..." -H "Content-Type: application/json" \
  -d '{"otp_id":"otp_...","code":"482913"}'
# → {"success":true,"data":{"verified":true,...}}
```

| Endpoint | الوصف |
|---|---|
| `POST /api/v1/otp/send` | إرسال رمز (`to`, `sender_name` إلزامية؛ `template`, `subject`, `lang`, `expires_in`, `code_length`, `code`, `reply_to` اختيارية) |
| `POST /api/v1/otp/verify` | التحقق (`code` + `otp_id` أو `to`) |
| `GET /api/v1/balance` | الرصيد الحالي |
| `GET /api/v1/health` | فحص حالة الخدمة |

مفاتيح `udy_test_...` لا ترسل بريد ولا تخصم رصيد وترجّع الرمز في الرد — مثالية للمطورين أثناء الربط.

---

## التشغيل محلياً (أو داخل Claude Code)

```bash
cd udeyou-otp
composer install
cp .env.example .env
#   عدّل: APP_SECRET (php -r "echo bin2hex(random_bytes(32));") وبيانات DB
#   وللتجربة المحلية:  MAIL_DRIVER=log  APP_ENV=local  SESSION_SECURE_COOKIE=false  APP_URL=http://127.0.0.1:8080

php bin/install.php                                     # إنشاء الجداول
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t public public/index.php
```

افتح http://127.0.0.1:8080 ، سجّل حساب، أنشئ مفتاح، وجرّب الـ curl أعلاه. مع `MAIL_DRIVER=log` الرسائل تُكتب في `storage/logs/mail.log` بدل الإرسال.

### الاختبارات

```bash
php tests/run.php            # يحتاج السيرفر يعمل + MAIL_DRIVER=log
```

تغطي: المصادقة، التحقق من الحقول، وضع test، الإرسال الحي وخصم الرصيد، حقن الهيدر، الانتهاء، القفل بعد 5 محاولات،
**8 طلبات متزامنة برصيد 3 → بالضبط 3 تنجح والرصيد 0**، استرجاع الرصيد عند فشل الإرسال، إلغاء المفاتيح، إيقاف الحساب، CSRF، ولوحة التحكم.

### عبر Claude Code

افتح المجلد في Claude Code واطلب مثلاً:
- «شغّل السيرفر والاختبارات» — ملف `CLAUDE.md` يشرح له الأوامر.
- «أضف endpoint لإعادة الإرسال» / «أضف دفع عبر Moyasar لشحن الرصيد» / «انقل الإرسال لـ Amazon SES».

---

## الرفع على Hostinger (خطوة بخطوة)

1. **قاعدة البيانات:** hPanel ← Databases ← MySQL Databases ← أنشئ قاعدة + مستخدم. احفظ الاسم/المستخدم/كلمة المرور.
2. **البريد:** hPanel ← Emails ← أنشئ `no-reply@udeyou.com`، واضبط SPF/DKIM/DMARC (القسم أعلاه).
3. **الملفات:** شغّل `composer install --no-dev` على جهازك (أو عبر SSH في هوستنجر)، ثم ارفع **محتويات** مجلد `udeyou-otp` مباشرةً داخل `public_html` (بحيث يكون `public_html/.htaccess` و`public_html/public/` و`public_html/vendor/`).
   ملف `.htaccess` الموجود في الجذر يجعل `public/` هو جذر الموقع ويمنع الوصول لـ `.env` و`src` وغيرها.
4. **الإعدادات:** انسخ `.env.example` إلى `.env` وعبّئ القيم الحقيقية (`APP_ENV=production`، `MAIL_DRIVER=smtp`، `ADMIN_EMAIL` = بريدك).
5. **الجداول:** عبر SSH: `php bin/install.php` — أو بدون SSH: phpMyAdmin ← Import ← `database/schema.sql`.
6. **SSL:** hPanel ← Security ← SSL ← فعّل الشهادة المجانية.
7. **حساب المدير:** افتح `https://udeyou.com/register` وسجّل **بنفس `ADMIN_EMAIL`** ← يصير حسابك مدير ويظهر رابط «الإدارة» لشحن رصيد العملاء.
8. **Cron (اختياري):** hPanel ← Advanced ← Cron Jobs ← يومياً: `php /home/USER/domains/udeyou.com/public_html/bin/cleanup.php 30`
9. **اختبار حقيقي:** أنشئ مفتاح live وأرسل لبريدك، ثم اختبر التقييم على mail-tester.com.

شحن رصيد عميل: من صفحة `/admin`، أو عبر SSH: `php bin/credits.php client@email.com 1000 "فاتورة 12"`.

---

## الخطوات القادمة المقترحة

- بوابة دفع لشحن الرصيد تلقائياً (Moyasar / Tap / Stripe).
- تأكيد بريد المطور عند التسجيل + استعادة كلمة المرور.
- Webhooks لإبلاغ العميل بحالة الرسالة.
- طابور إرسال (Queue) عند الأحجام الكبيرة، ومزود بريد معاملاتي (SES) بدل صندوق بريد عادي.
- إرسال OTP عبر SMS / WhatsApp بنفس الـ API (فقط Carrier جديد).
