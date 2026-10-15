<?php
/** Task Forge: generate hold-out drafts with an LLM, review, save. */
defined('WB') || exit;
$o = $opts + ['track' => '', 'difficulty' => 2, 'output_type' => 'html', 'count' => 2, 'theme' => ''];
echo partial('admin/_head', ['title' => __('admin.nav.forge'), 'sub' => __('admin.forge.sub')]);
?>
<div class="alert alert-warn"><?= ui_icon('alert', 16) ?> <?= _e('admin.forge.review_warning') ?></div>
<div class="admin-split">
  <div class="stack-lg">
    <form class="card card-pad form stack" method="post" action="<?= e(url('/admin/forge')) ?>" data-busy-form>
      <?= csrf_field() ?>
      <h2 class="card-title"><?= ui_icon('wand', 18) ?> <?= _e('admin.forge.new_batch') ?></h2>
      <div class="admin-grid admin-grid-2">
        <div class="field"><label class="label" for="fg-track"><?= _e('admin.tasks.track') ?></label>
          <select class="select" id="fg-track" name="track"><?php foreach ($tracks as $t): ?><option value="<?= e($t['slug']) ?>"<?= $o['track'] === $t['slug'] ? ' selected' : '' ?>><?= e(L($t, 'name')) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label class="label" for="fg-type"><?= _e('admin.tasks.output_type') ?></label>
          <select class="select" id="fg-type" name="output_type"><?php foreach (\WB\Admin\TaskValidator::OUTPUT_TYPES as $ot): ?><option value="<?= e($ot) ?>"<?= $o['output_type'] === $ot ? ' selected' : '' ?>><?= _e('admin.otype.' . $ot) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label class="label" for="fg-diff"><?= _e('admin.tasks.difficulty') ?></label>
          <select class="select" id="fg-diff" name="difficulty"><?php foreach ([1 => 'common.diff_easy', 2 => 'common.diff_medium', 3 => 'common.diff_hard', 4 => 'common.diff_expert'] as $dv => $dk): ?><option value="<?= $dv ?>"<?= (int) $o['difficulty'] === $dv ? ' selected' : '' ?>><?= $dv ?> · <?= _e($dk) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label class="label" for="fg-count"><?= _e('admin.forge.count') ?></label>
          <input class="input t-num" id="fg-count" type="number" name="count" min="1" max="5" value="<?= (int) $o['count'] ?>"></div>
      </div>
      <div class="field"><label class="label" for="fg-theme"><?= _e('admin.forge.theme') ?></label>
        <input class="input" id="fg-theme" name="theme" maxlength="300" value="<?= e($o['theme']) ?>" placeholder="<?= _e('admin.forge.theme_ph') ?>"></div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit"<?= $model ? '' : ' disabled' ?>><?= ui_icon('sparkles', 16) ?><span><?= _e('admin.forge.generate') ?></span></button>
        <span class="t-xs t-muted"><?= $model ? _e('admin.forge.using', ['m' => \WB\Admin\Admin::iso($model)]) : _e('admin.forge.err_model') ?></span>
      </div>
      <p class="help" data-busy-note hidden><?= ui_icon('clock', 14) ?> <?= _e('admin.forge.busy') ?></p>
    </form>

    <?php if (!$drafts): ?>
      <?= ui_empty(__('admin.forge.no_drafts'), __('admin.forge.no_drafts_text'), 'wand') ?>
    <?php else: ?>
      <div class="cluster admin-forge-bar">
        <h2 class="t-h3"><?= _e('admin.forge.drafts', ['n' => count($drafts)]) ?></h2>
        <?php if (!empty($meta['model'])): ?><span class="t-xs t-muted"><?= _e('admin.forge.meta', ['m' => \WB\Admin\Admin::iso((string) $meta['model']), 'c' => $meta['cost'] !== null ? \WB\Admin\Admin::iso(fmt_money($meta['cost'])) : '—']) ?></span><?php endif; ?>
        <form method="post" action="<?= e(url('/admin/forge/clear')) ?>" data-confirm="<?= _e('admin.forge.discard_all_confirm') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-ghost" type="submit"><?= ui_icon('trash', 14) ?><span><?= _e('admin.forge.discard_all') ?></span></button></form>
      </div>
      <?php foreach ($drafts as $i => $d): ?>
        <article class="card card-pad stack admin-draft" id="draft-<?= (int) $i ?>">
          <div class="card-head">
            <h3 class="card-title"><?= e($d['title']) ?></h3>
            <?= $d['errors'] ? ui_badge(__('admin.forge.n_errors', ['n' => count($d['errors'])]), 'red', 'x') : ui_badge(__('admin.forge.valid'), 'green', 'check') ?>
            <?= $d['warnings'] ? ui_badge(__('admin.forge.n_warnings', ['n' => count($d['warnings'])]), 'gold') : '' ?>
          </div>
          <?php if ($d['errors'] || $d['warnings']): ?>
            <ul class="admin-msgs">
              <?php foreach ($d['errors'] as $m): ?><li class="is-error t-mono t-xs" dir="ltr"><?= e($m) ?></li><?php endforeach; ?>
              <?php foreach ($d['warnings'] as $m): ?><li class="is-warn t-mono t-xs" dir="ltr"><?= e($m) ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <form method="post" action="<?= e(url('/admin/forge/save')) ?>" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="index" value="<?= (int) $i ?>">
            <textarea class="textarea t-mono admin-code" id="draft-json-<?= (int) $i ?>" name="json" rows="18" dir="ltr" spellcheck="false" data-json-field="task" aria-label="<?= _e('admin.forge.draft_json') ?>"><?= e($d['json']) ?></textarea>
            <span class="admin-json-status" data-json-status="#draft-json-<?= (int) $i ?>"></span>
            <div class="form-actions">
              <button class="btn btn-primary btn-sm" type="submit"><?= ui_icon('lock', 14) ?><span><?= _e('admin.forge.save') ?></span></button>
              <button class="btn btn-ghost btn-sm" type="submit" formaction="<?= e(url('/admin/forge/clear')) ?>"><?= ui_icon('x', 14) ?><span><?= _e('admin.forge.discard') ?></span></button>
            </div>
          </form>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <aside class="stack-lg admin-aside">
    <section class="card card-pad stack-sm">
      <h2 class="card-title"><?= ui_icon('shield', 18) ?> <?= _e('admin.forge.how') ?></h2>
      <ol class="admin-steps t-small">
        <li><?= _e('admin.forge.how_1') ?></li>
        <li><?= _e('admin.forge.how_2') ?></li>
        <li><?= _e('admin.forge.how_3') ?></li>
        <li><?= _e('admin.forge.how_4') ?></li>
      </ol>
    </section>
    <section class="card card-pad stack-sm">
      <h2 class="card-title"><?= ui_icon('history', 18) ?> <?= _e('admin.forge.recent') ?></h2>
      <?php if (!$recent): ?><p class="t-small t-muted"><?= _e('admin.forge.recent_none') ?></p><?php endif; ?>
      <ul class="admin-list">
        <?php foreach ($recent as $r): ?>
          <li><a href="<?= e(url('/admin/tasks/' . $r['id'])) ?>"><?= e(L($r, 'title')) ?></a> <?= (int) $r['is_active'] ? ui_badge(__('admin.models.active'), 'green') : ui_badge(__('admin.forge.pending_review'), 'gold') ?></li>
        <?php endforeach; ?>
      </ul>
    </section>
  </aside>
</div>
<script type="application/json" id="task-spec"><?= js_json(\WB\Admin\TaskValidator::spec()) ?></script>
