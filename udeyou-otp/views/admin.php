<?php use Udeyou\Core\Session; $csrf = e(Session::csrfToken()); ?>
<h1>إدارة العملاء</h1>
<?php if ($notice): ?><div class="alert ok"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<section class="card">
  <h2>اختبار إعدادات البريد (SMTP)</h2>
  <form method="post" action="/admin/test-mail">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
    <button class="btn">إرسال بريد تجريبي إلى <?= e($client['email']) ?></button>
  </form>
</section>
<section class="card">
  <table>
    <tr><th>#</th><th>الشركة</th><th>البريد</th><th>الرصيد</th><th>المرسلة</th><th>الحالة</th><th>إضافة رصيد</th><th></th></tr>
    <?php foreach ($clients as $c): ?>
      <tr class="<?= $c['status'] !== 'active' ? 'revoked' : '' ?>">
        <td><?= (int) $c['id'] ?></td>
        <td><?= e($c['company_name']) ?><?= $c['is_admin'] ? ' ⭐' : '' ?></td>
        <td dir="ltr"><?= e($c['email']) ?></td>
        <td><?= (int) $c['credits'] ?></td>
        <td><?= (int) $c['sent_count'] ?></td>
        <td><?= $c['status'] === 'active' ? 'نشط' : 'موقوف' ?></td>
        <td>
          <form method="post" action="/admin/clients/<?= (int) $c['id'] ?>/credits" class="row tight">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <input type="number" name="amount" placeholder="+1000" required class="short">
            <input name="note" placeholder="ملاحظة" class="short">
            <button class="btn small">تنفيذ</button>
          </form>
        </td>
        <td>
          <?php if (!$c['is_admin']): ?>
          <form method="post" action="/admin/clients/<?= (int) $c['id'] ?>/toggle">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>">
            <button class="link <?= $c['status'] === 'active' ? 'danger' : '' ?>"><?= $c['status'] === 'active' ? 'إيقاف' : 'تفعيل' ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</section>
