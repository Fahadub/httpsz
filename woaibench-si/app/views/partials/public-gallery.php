<?php
/**
 * Output gallery (model / task pages). $items: [['run_id','shot_url'|null,'title','score','sub','color','icon','type']].
 * Screenshots come from uploads/shots; without one a styled placeholder is shown.
 */
defined('WB') || exit;
?>
<ul class="pub-gallery" role="list">
  <?php foreach ($items as $it): ?>
  <li>
    <a class="pub-shot card card-hover" href="<?= e(url('/run/' . (int) $it['run_id'])) ?>" style="--track:<?= e($it['color'] ?? '#D9B26A') ?>">
      <span class="pub-shot-media">
        <?php if (!empty($it['shot_url'])): ?>
          <img src="<?= e($it['shot_url']) ?>" alt="<?= _e('pub.shot_alt', ['title' => $it['title']]) ?>" loading="lazy" decoding="async" width="640" height="400">
        <?php else: ?>
          <span class="pub-shot-placeholder" aria-hidden="true"><?= ui_icon($it['type'] === 'html' ? 'image' : pub_type_icon((string) $it['type']), 30) ?><span class="t-xs"><?= _e('pub.no_shot') ?></span></span>
        <?php endif; ?>
        <span class="pub-shot-score t-num"><?= e(fmt_score($it['score'])) ?></span>
      </span>
      <span class="pub-shot-meta">
        <span class="pub-shot-title"><?= e($it['title']) ?></span>
        <span class="t-xs t-muted"><?= ui_icon((string) ($it['icon'] ?? 'layers'), 12) ?> <?= e($it['sub']) ?></span>
      </span>
    </a>
  </li>
  <?php endforeach; ?>
</ul>
