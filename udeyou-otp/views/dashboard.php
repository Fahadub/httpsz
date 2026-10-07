<?php use Udeyou\Core\Session; $csrf = e(Session::csrfToken()); ?>
<h1>أهلاً، <?= e($client['company_name']) ?></h1>
<?php if ($notice): ?><div class="alert ok"><?= e($notice) ?></div><?php endif; ?>

<div class="grid3">
  <div class="card stat"><span>الرصيد</span><strong><?= (int) $client['credits'] ?></strong><small>رسالة متبقية</small></div>
  <div class="card stat"><span>المرسلة</span><strong><?= (int) ($stats['sent'] ?? 0) ?></strong><small>رسالة (حي)</small></div>
  <div class="card stat"><span>تم التحقق</span><strong><?= (int) ($stats['verified'] ?? 0) ?></strong><small>رمز</small></div>
</div>

<section class="card" id="keys">
  <h2>مفاتيح API</h2>
  <?php if ($newKey): ?>
    <div class="alert ok">
      <b>انسخ المفتاح الآن — لن يظهر مرة أخرى:</b>
      <code class="key" dir="ltr"><?= e($newKey) ?></code>
    </div>
  <?php endif; ?>
  <form method="post" action="/keys" class="row">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
    <input name="name" placeholder="اسم المفتاح (مثلاً: متجري)" maxlength="80">
    <select name="mode">
      <option value="live">Live — إرسال حقيقي</option>
      <option value="test">Test — للتجربة بدون إرسال</option>
    </select>
    <button class="btn">إنشاء مفتاح</button>
  </form>
  <table>
    <tr><th>الاسم</th><th>المفتاح</th><th>النوع</th><th>آخر استخدام</th><th></th></tr>
    <?php foreach ($keys as $k): ?>
      <tr class="<?= $k['revoked_at'] ? 'revoked' : '' ?>">
        <td><?= e($k['name']) ?></td>
        <td dir="ltr"><code><?= e($k['key_prefix']) ?>…</code></td>
        <td><?= $k['mode'] === 'live' ? 'Live' : 'Test' ?></td>
        <td dir="ltr"><?= e($k['last_used_at'] ?? '—') ?></td>
        <td>
          <?php if ($k['revoked_at']): ?>ملغي<?php else: ?>
          <form method="post" action="/keys/<?= (int) $k['id'] ?>/revoke" onsubmit="return confirm('إلغاء المفتاح نهائياً؟')">
            <input type="hidden" name="_csrf" value="<?= $csrf ?>"><button class="link danger">إلغاء</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$keys): ?><tr><td colspan="5" class="muted">لا توجد مفاتيح بعد.</td></tr><?php endif; ?>
  </table>
</section>

<section class="card">
  <h2>آخر الرسائل</h2>
  <table>
    <tr><th>المعرّف</th><th>إلى</th><th>المرسل</th><th>الحالة</th><th>النوع</th><th>الوقت (UTC)</th></tr>
    <?php foreach ($otps as $o): ?>
      <tr>
        <td dir="ltr"><code><?= e($o['public_id']) ?></code></td>
        <td dir="ltr"><?= e($o['recipient_email']) ?></td>
        <td><?= e($o['sender_name']) ?></td>
        <td><span class="badge <?= e($o['status']) ?>" title="<?= e($o['error_message'] ?? '') ?>"><?= e($o['status']) ?></span></td>
        <td><?= e($o['mode']) ?></td>
        <td dir="ltr"><?= e($o['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$otps): ?><tr><td colspan="6" class="muted">لا توجد رسائل بعد.</td></tr><?php endif; ?>
  </table>
</section>

<?php if ($credits): ?>
<section class="card">
  <h2>حركات الرصيد</h2>
  <table>
    <tr><th>المبلغ</th><th>النوع</th><th>ملاحظة</th><th>الوقت (UTC)</th></tr>
    <?php foreach ($credits as $c): ?>
      <tr><td dir="ltr"><?= (int) $c['amount'] > 0 ? '+' : '' ?><?= (int) $c['amount'] ?></td><td><?= e($c['type']) ?></td><td><?= e($c['note'] ?? $c['reference'] ?? '') ?></td><td dir="ltr"><?= e($c['created_at']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</section>
<?php endif; ?>
