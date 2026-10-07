<?php use Udeyou\Core\Session; ?>
<div class="card narrow">
  <h1>تسجيل الدخول</h1>
  <?php if (!empty($error)): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/login">
    <input type="hidden" name="_csrf" value="<?= e(Session::csrfToken()) ?>">
    <label>البريد الإلكتروني <input type="email" name="email" value="<?= e($old['email'] ?? '') ?>" required dir="ltr"></label>
    <label>كلمة المرور <input type="password" name="password" required dir="ltr"></label>
    <button class="btn full">دخول</button>
  </form>
  <p class="muted">ليس لديك حساب؟ <a href="/register">سجّل الآن</a></p>
</div>
