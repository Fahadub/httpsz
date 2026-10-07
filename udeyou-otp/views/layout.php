<?php use Udeyou\Core\Config; use Udeyou\Core\Session; ?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? '') ?> · <?= e(Config::get('APP_NAME', 'Udeyou OTP')) ?></title>
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="top">
  <div class="wrap">
    <a class="brand" href="/"><?= e(Config::get('APP_NAME', 'Udeyou OTP')) ?></a>
    <nav>
      <a href="/docs">التوثيق</a>
      <?php if (!empty($client)): ?>
        <a href="/dashboard">لوحة التحكم</a>
        <?php if ($client['is_admin']): ?><a href="/admin">الإدارة</a><?php endif; ?>
        <form method="post" action="/logout" class="inline">
          <input type="hidden" name="_csrf" value="<?= e(Session::csrfToken()) ?>">
          <button class="link">خروج</button>
        </form>
      <?php else: ?>
        <a href="/login">دخول</a>
        <a href="/register" class="btn small">حساب جديد</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="wrap"><?= $content ?></main>
</body>
</html>
