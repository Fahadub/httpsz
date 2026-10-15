<?php
/**
 * Changelog. Vars: entries (changelog rows, newest first).
 */
defined('WB') || exit;
?>
<section class="section pub-hero">
  <div class="container stack-sm">
    <p class="eyebrow"><?= _e('pub.log.eyebrow') ?></p>
    <h1 class="t-h1"><?= _e('pub.log.h1') ?></h1>
    <p class="t-lead t-muted pub-lead"><?= _e('pub.log.lead') ?></p>
  </div>
</section>
<section class="section pub-section-tight">
  <div class="container">
    <?php if (!$entries): ?>
      <?= ui_empty(__('pub.log.empty'), __('pub.log.empty_text'), 'history') ?>
    <?php else: ?>
    <ol class="pub-log">
      <?php foreach ($entries as $en): ?>
        <li class="pub-log-item" id="v<?= e(preg_replace('/[^A-Za-z0-9.\-]/', '', (string) $en['version'])) ?>">
          <div class="pub-log-meta">
            <span class="badge badge-gold t-mono"><?= e($en['version']) ?></span>
            <time class="t-xs t-muted" datetime="<?= e(gmdate('c', (int) strtotime($en['published_at'] . ' UTC'))) ?>"><?= e(fmt_date($en['published_at'])) ?></time>
          </div>
          <article class="card card-pad stack-sm">
            <h2 class="t-h3"><?= e(L($en, 'title')) ?></h2>
            <?php $body = trim(L($en, 'body')); if ($body !== ''): ?>
              <div class="prose pub-log-body" dir="auto">
                <?php foreach (preg_split('/\R{2,}/', $body) ?: [] as $para): ?><p><?= nl2br(e(trim($para))) ?></p><?php endforeach; ?>
              </div>
            <?php endif; ?>
          </article>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
  </div>
</section>
