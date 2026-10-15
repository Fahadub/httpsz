<?php
/**
 * Compare up to four models. Vars: rows (all scored models), sel (selected rows), season, tracks, h2h, values.
 * The results block is a partial so compare.js can swap it without a page reload (?fragment=1).
 */
defined('WB') || exit;

$options = array_map(static fn($r) => [
    'slug' => $r['slug'], 'name' => $r['name'], 'vendor' => $r['provider_name'] ?: $r['provider'],
    'provider' => $r['provider'], 'score' => $r['score'], 'provisional' => $r['provisional'], 'open' => $r['open_weights'],
], $rows);
$selSlugs = array_map(static fn($r) => $r['slug'], $sel);
?>
<section class="section pub-hero">
  <div class="container-wide stack">
    <div class="stack-sm">
      <p class="eyebrow"><?= _e('pub.cmp.eyebrow') ?></p>
      <h1 class="t-h1"><?= _e('pub.cmp.h1') ?></h1>
      <p class="t-lead t-muted pub-lead"><?= _e('pub.cmp.lead') ?></p>
    </div>

    <?php if ($rows): ?>
    <div class="card card-glass pub-picker" data-cmp-picker data-base="<?= e(url('/compare')) ?>">
      <div class="pub-picker-chips" data-cmp-chips aria-live="polite">
        <?php foreach ($sel as $i => $r): ?>
          <span class="pub-pchip pub-c<?= $i ?>" data-slug="<?= e($r['slug']) ?>">
            <i class="pub-dot" aria-hidden="true"></i><span><?= e($r['name']) ?></span>
            <button type="button" class="pub-pchip-x" data-cmp-remove="<?= e($r['slug']) ?>" aria-label="<?= _e('pub.cmp.remove', ['name' => $r['name']]) ?>"><?= ui_icon('x', 14) ?></button>
          </span>
        <?php endforeach; ?>
      </div>
      <div class="pub-combo" data-cmp-combo hidden>
        <label class="input-group">
          <?= ui_icon('plus', 16) ?>
          <span class="sr-only"><?= _e('pub.cmp.add') ?></span>
          <input class="input" type="text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="cmp-listbox" placeholder="<?= _e('pub.cmp.add') ?>" data-cmp-input autocomplete="off">
        </label>
        <ul class="pub-listbox card" id="cmp-listbox" role="listbox" data-cmp-listbox hidden></ul>
      </div>
      <form class="pub-picker-fallback" method="get" action="<?= e(url('/compare')) ?>" data-cmp-fallback>
        <?php for ($i = 0; $i < 4; $i++): ?>
          <label class="field"><span class="label"><?= _e('pub.cmp.slot', ['n' => $i + 1]) ?></span>
            <select class="select" name="m<?= $i + 1 ?>">
              <option value="">—</option>
              <?php foreach ($rows as $r): ?><option value="<?= e($r['slug']) ?>"<?= ($selSlugs[$i] ?? '') === $r['slug'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
            </select>
          </label>
        <?php endfor; ?>
        <button class="btn btn-primary" type="submit"><?= _e('pub.cmp.go') ?></button>
      </form>
      <div class="pub-picker-actions cluster">
        <button class="btn btn-ghost btn-sm" type="button" data-cmp-share hidden><?= ui_icon('link', 16) ?><span><?= _e('pub.cmp.share') ?></span></button>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="section pub-section-tight">
  <div class="container-wide" id="cmp-results" data-cmp-results aria-live="polite">
    <?php if (!$rows): ?>
      <?= ui_empty(__('pub.lb.empty_title'), __('pub.lb.empty_text'), 'scale') ?>
    <?php else: ?>
      <?= partial('partials/public-compare-results', ['rows' => $rows, 'sel' => $sel, 'season' => $season, 'tracks' => $tracks, 'h2h' => $h2h, 'values' => $values]) ?>
    <?php endif; ?>
  </div>
</section>
<script type="application/json" id="cmp-options"><?= js_json(['models' => $options, 'selected' => $selSlugs, 'max' => 4]) ?></script>
<?= pub_js_strings() ?>
