<?php use Udeyou\Core\Session; ?>
<div class="card narrow">
  <h1>إنشاء حساب مطور</h1>
  <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>
  <form method="post" action="/register">
    <input type="hidden" name="_csrf" value="<?= e(Session::csrfToken()) ?>">
    <label>اسم الشركة / المتجر <input name="company_name" value="<?= e($old['company_name'] ?? '') ?>" required maxlength="120"></label>
    <label>البريد الإلكتروني <input type="email" name="email" value="<?= e($old['email'] ?? '') ?>" required dir="ltr"></label>
    <label>كلمة المرور (8 أحرف على الأقل) <input type="password" name="password" required minlength="8" dir="ltr"></label>
    <button class="btn full">إنشاء الحساب</button>
  </form>
  <p class="muted">لديك حساب؟ <a href="/login">سجّل الدخول</a></p>
</div>
