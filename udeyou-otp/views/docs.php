<?php use Udeyou\Core\Config; $base = rtrim((string) Config::get('APP_URL', 'https://udeyou.com'), '/'); ?>
<div class="card docs">
  <h1>دليل الربط عبر الـ API</h1>
  <p>الرابط الأساسي: <code dir="ltr"><?= e($base) ?>/api/v1</code> — كل الطلبات والردود بصيغة JSON.</p>

  <h2>1) المصادقة</h2>
  <p>أنشئ مفتاح من <a href="/dashboard">لوحة التحكم</a> وأرسله في كل طلب بالهيدر:</p>
  <pre><code>Authorization: Bearer sk_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</code></pre>
  <ul>
    <li><code>sk_live_...</code> يرسل بريد حقيقي ويخصم <b>1 رصيد</b> لكل رسالة ناجحة (لا يُخصم شيء إذا فشل الإرسال).</li>
    <li><code>sk_test_...</code> للتجربة: لا يرسل بريد ولا يخصم رصيد، ويرجّع الرمز في الرد.</li>
    <li>⚠️ المفتاح سرّي: استخدمه من الخادم (Backend) فقط، لا تضعه في JavaScript المتصفح أو تطبيق الجوال.</li>
  </ul>

  <h2>2) إرسال رمز — <code dir="ltr">POST /otp/send</code></h2>
  <table>
    <tr><th>الحقل</th><th>إلزامي</th><th>الوصف</th></tr>
    <tr><td><code>to</code></td><td>نعم</td><td>بريد العميل النهائي.</td></tr>
    <tr><td><code>sender_name</code></td><td>نعم</td><td>اسم متجرك (2-60 حرف) — يظهر كاسم المرسل في صندوق الوارد.</td></tr>
    <tr><td><code>template</code></td><td>لا</td><td>نص الرسالة (نص عادي حتى 1000 حرف) ويجب أن يحتوي <code>{{code}}</code>. متغيرات إضافية: <code>{{sender_name}}</code> <code>{{minutes}}</code>.</td></tr>
    <tr><td><code>subject</code></td><td>لا</td><td>عنوان الرسالة (حتى 120 حرف، يقبل نفس المتغيرات).</td></tr>
    <tr><td><code>lang</code></td><td>لا</td><td><code>ar</code> (افتراضي) أو <code>en</code> للقالب الافتراضي.</td></tr>
    <tr><td><code>expires_in</code></td><td>لا</td><td>مدة الصلاحية بالثواني (60-3600). الافتراضي 300 = 5 دقائق.</td></tr>
    <tr><td><code>code_length</code></td><td>لا</td><td>طول الرمز (4-10). الافتراضي 6.</td></tr>
    <tr><td><code>code</code></td><td>لا</td><td>إذا تبي تولّد الرمز بنفسك أرسله هنا، وإلا نولّده نحن.</td></tr>
    <tr><td><code>reply_to</code></td><td>لا</td><td>بريد دعم متجرك لو رد العميل على الرسالة.</td></tr>
  </table>
  <pre><code>curl -X POST <?= e($base) ?>/api/v1/otp/send \
  -H "Authorization: Bearer sk_live_..." \
  -H "Content-Type: application/json" \
  -d '{
    "to": "customer@gmail.com",
    "sender_name": "متجر الورد",
    "template": "رمز الدخول إلى متجر الورد: {{code}} (صالح {{minutes}} دقائق)"
  }'</code></pre>
  <p>الرد (201):</p>
  <pre><code>{
  "success": true,
  "data": {
    "otp_id": "otp_8f2a9c...",
    "to": "customer@gmail.com",
    "status": "sent",
    "mode": "live",
    "expires_in": 300,
    "expires_at": "2026-10-07T12:05:00Z",
    "credits_remaining": 99
  }
}</code></pre>
  <p>احفظ <code>otp_id</code> في جلسة المستخدم لاستخدامه في التحقق.</p>

  <h2>3) التحقق من الرمز — <code dir="ltr">POST /otp/verify</code></h2>
  <p>أرسل <code>code</code> مع <code>otp_id</code> (مفضّل) أو <code>to</code> (يتحقق من آخر رمز أُرسل لهذا البريد).</p>
  <pre><code>curl -X POST <?= e($base) ?>/api/v1/otp/verify \
  -H "Authorization: Bearer sk_live_..." \
  -H "Content-Type: application/json" \
  -d '{"otp_id": "otp_8f2a9c...", "code": "482913"}'</code></pre>
  <pre><code>// نجاح (200)
{ "success": true, "data": { "verified": true, "otp_id": "otp_8f2a9c...", "to": "customer@gmail.com", "verified_at": "..." } }

// رمز خطأ (400)
{ "success": false, "error": { "code": "invalid_code", "message": "The code is incorrect.", "attempts_remaining": 4 } }</code></pre>
  <p>الرمز يُستخدم مرة واحدة فقط، وينتهي بعد المدة المحددة، ويُقفل بعد 5 محاولات خاطئة. إرسال رمز جديد لنفس البريد يُلغي الرموز السابقة.</p>

  <h2>4) الرصيد — <code dir="ltr">GET /balance</code></h2>
  <pre><code>curl <?= e($base) ?>/api/v1/balance -H "Authorization: Bearer sk_live_..."</code></pre>

  <h2>5) أكواد الأخطاء</h2>
  <table>
    <tr><th>HTTP</th><th>code</th><th>المعنى</th></tr>
    <tr><td>400</td><td>invalid_json</td><td>الجسم ليس JSON صالح.</td></tr>
    <tr><td>400</td><td>invalid_code</td><td>الرمز غير صحيح.</td></tr>
    <tr><td>401</td><td>invalid_api_key</td><td>المفتاح مفقود أو خاطئ أو ملغي.</td></tr>
    <tr><td>402</td><td>insufficient_credits</td><td>الرصيد انتهى.</td></tr>
    <tr><td>403</td><td>account_suspended</td><td>الحساب موقوف.</td></tr>
    <tr><td>404</td><td>otp_not_found</td><td>لا يوجد رمز مطابق.</td></tr>
    <tr><td>409</td><td>otp_already_used</td><td>الرمز استُخدم مسبقاً.</td></tr>
    <tr><td>410</td><td>otp_expired</td><td>انتهت صلاحية الرمز.</td></tr>
    <tr><td>422</td><td>validation_error</td><td>حقول ناقصة/خاطئة (التفاصيل في <code>error.fields</code>).</td></tr>
    <tr><td>429</td><td>too_many_attempts / resend_too_soon / recipient_limit / rate_limited</td><td>تجاوز الحدود (انتظر <code>retry_after</code> ثانية).</td></tr>
    <tr><td>502</td><td>delivery_failed</td><td>تعذّر الإرسال، ولم يُخصم رصيد.</td></tr>
  </table>

  <h2>6) أمثلة جاهزة</h2>
  <h3>PHP</h3>
  <pre><code>function udeyou(string $path, array $body): array {
    $ch = curl_init('<?= e($base) ?>/api/v1' . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . getenv('UDEYOU_KEY'), 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res;
}

// 1) إرسال
$send = udeyou('/otp/send', ['to' => $email, 'sender_name' => 'متجري']);
$_SESSION['otp_id'] = $send['data']['otp_id'];

// 2) تحقق
$check = udeyou('/otp/verify', ['otp_id' => $_SESSION['otp_id'], 'code' => $_POST['code']]);
if ($check['success']) { /* سجّل دخول العميل */ }</code></pre>

  <h3>Node.js</h3>
  <pre><code>const res = await fetch('<?= e($base) ?>/api/v1/otp/send', {
  method: 'POST',
  headers: { Authorization: `Bearer ${process.env.UDEYOU_KEY}`, 'Content-Type': 'application/json' },
  body: JSON.stringify({ to: 'customer@gmail.com', sender_name: 'My Store', lang: 'en' }),
});
const { data } = await res.json(); // data.otp_id</code></pre>

  <h3>Python</h3>
  <pre><code>import os, requests
r = requests.post("<?= e($base) ?>/api/v1/otp/verify",
    headers={"Authorization": f"Bearer {os.environ['UDEYOU_KEY']}"},
    json={"otp_id": otp_id, "code": user_code}, timeout=20)
print(r.json()["success"])</code></pre>
</div>
