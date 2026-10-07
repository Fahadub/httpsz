<section class="hero">
  <h1>أرسل رموز التحقق (OTP) بالبريد عبر API واحد</h1>
  <p>اربط متجرك أو تطبيقك في دقائق: طلب واحد لإرسال الرمز، وطلب واحد للتحقق منه. الرسالة تظهر لعميلك باسم متجرك.</p>
  <?php if (empty($client)): ?>
    <a href="/register" class="btn">ابدأ مجاناً</a>
  <?php else: ?>
    <a href="/dashboard" class="btn">لوحة التحكم</a>
  <?php endif; ?>
  <a href="/docs" class="btn ghost">التوثيق</a>
</section>
<div class="grid3">
  <div class="card"><h3>1. أنشئ حساب</h3><p>واحصل على مفتاح API من لوحة التحكم.</p></div>
  <div class="card"><h3>2. أرسل الرمز</h3><p><code>POST /api/v1/otp/send</code></p></div>
  <div class="card"><h3>3. تحقق منه</h3><p><code>POST /api/v1/otp/verify</code></p></div>
</div>
