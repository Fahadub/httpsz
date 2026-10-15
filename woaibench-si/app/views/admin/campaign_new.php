<?php
/** New campaign: pick models + tasks, runs, gen params; live estimate. */
defined('WB') || exit;
$oldModels = array_map('intval', (array) ($old['model_ids'] ?? []));
$oldTasks = array_map('intval', (array) ($old['task_ids'] ?? []));
$runs = (int) ($old['runs_per_task'] ?? $runs_default);
echo partial('admin/_head', [
    'back' => url('/admin/campaigns'),
    'title' => __('admin.campaigns.new'),
    'sub' => __('admin.campaigns.new_sub'),
]);
?>
<?php if (!$engine): ?><div class="alert alert-warn"><?= _e('admin.engine_missing', ['c' => 'WB\\Eval\\Campaign']) ?></div><?php endif; ?>
<?php if ($judges < 3): ?><div class="alert alert-warn"><?= ui_icon('scale', 16) ?> <?= _e('admin.campaigns.few_judges', ['n' => $judges]) ?> <a href="<?= e(url('/admin/models', ['judge' => 1])) ?>"><?= _e('admin.nav.models') ?></a></div><?php endif; ?>
<?php $judge_gaps = $judge_gaps ?? []; ?>
<?php if ($judges > 0 && $judge_gaps): ?><div class="alert alert-warn"><?= ui_icon('scale', 16) ?> <span><?= _e('admin.models.judge_gaps', ['n' => count($judge_gaps), 'need' => max(1, (int) setting('judge_count')) + 1]) ?> <a href="<?= e(url('/admin/models', ['judge' => 1])) ?>"><?= _e('admin.nav.models') ?></a></span></div><?php endif; ?>
<form class="form admin-split" method="post" action="<?= e(url('/admin/campaigns/new')) ?>" data-campaign-form data-estimate-url="<?= e(url('/admin/api/estimate')) ?>">
  <?= csrf_field() ?>
  <div class="stack-lg">
    <section class="card card-pad stack">
      <div class="field"><label class="label" for="cp-name"><?= _e('admin.campaigns.name') ?></label><input class="input" id="cp-name" name="name" value="<?= e((string) ($old['name'] ?? __('admin.campaigns.default_name', ['s' => setting('season'), 'd' => gmdate('Y-m-d')]))) ?>" maxlength="190" required></div>
    </section>

    <section class="card card-pad stack">
      <div class="card-head">
        <h2 class="card-title"><?= ui_icon('robot', 18) ?> <?= _e('admin.campaigns.models') ?> <span class="t-muted t-small" data-count-models></span></h2>
        <div class="cluster">
          <input class="input input-sm admin-mini-search" type="search" placeholder="<?= _e('common.search') ?>" data-pick-search="#pick-models" aria-label="<?= _e('common.search') ?>">
          <button class="btn btn-sm btn-ghost" type="button" data-pick-all="#pick-models"><?= _e('admin.select_all') ?></button>
          <button class="btn btn-sm btn-ghost" type="button" data-pick-none="#pick-models"><?= _e('admin.select_none') ?></button>
        </div>
      </div>
      <?php if (!$models): ?><?= ui_empty(__('admin.models.empty'), '', 'robot', '<a class="btn btn-sm btn-primary" href="' . e(url('/admin/models/sync')) . '">' . _e('admin.sync.title') . '</a>') ?><?php endif; ?>
      <div class="admin-pick-grid" id="pick-models">
        <?php foreach ($models as $m): ?>
          <label class="admin-pick" data-pick-item data-search="<?= e(strtolower($m['name'] . ' ' . $m['provider'])) ?>">
            <input type="checkbox" name="model_ids[]" value="<?= (int) $m['id'] ?>"<?= in_array((int) $m['id'], $oldModels, true) ? ' checked' : '' ?>>
            <span class="admin-pick-body">
              <?= ui_model_chip($m, false) ?>
              <span class="t-xs t-muted t-num"><bdi><?= e(fmt_price($m['price_in'])) ?></bdi> · <bdi><?= e(fmt_price($m['price_out'])) ?></bdi><?= (int) $m['is_judge'] ? ' · ' . _e('admin.models.judge') : '' ?></span>
              <?php if ($judges > 0 && isset($judge_gaps[(int) $m['id']])): ?><span class="t-xs" data-tip="<?= _e('admin.models.judge_gap_tip', ['n' => (int) $judge_gaps[(int) $m['id']]]) ?>"><?= ui_badge(__('admin.models.judge_gap_badge'), 'gold') ?></span><?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card card-pad stack">
      <div class="card-head">
        <h2 class="card-title"><?= ui_icon('list', 18) ?> <?= _e('admin.campaigns.tasks') ?> <span class="t-muted t-small" data-count-tasks></span></h2>
        <div class="cluster">
          <button class="btn btn-sm btn-ghost" type="button" data-pick-all="#pick-tasks"><?= _e('admin.select_all') ?></button>
          <button class="btn btn-sm btn-ghost" type="button" data-pick-none="#pick-tasks"><?= _e('admin.select_none') ?></button>
        </div>
      </div>
      <?php if (!$groups): ?><?= ui_empty(__('admin.tasks.empty'), '', 'list') ?><?php endif; ?>
      <div class="stack" id="pick-tasks">
        <?php foreach ($groups as $slug => $g): ?>
          <fieldset class="admin-pick-group" style="--track:<?= e($g['track']['color']) ?>">
            <legend class="cluster">
              <?= ui_track_badge($g['track']) ?>
              <button class="btn btn-sm btn-ghost" type="button" data-pick-all="#grp-<?= e($slug) ?>"><?= _e('admin.campaigns.whole_track', ['n' => count($g['tasks'])]) ?></button>
            </legend>
            <div class="admin-pick-list" id="grp-<?= e($slug) ?>">
              <?php foreach ($g['tasks'] as $t): ?>
                <label class="admin-pick admin-pick-task">
                  <input type="checkbox" name="task_ids[]" value="<?= (int) $t['id'] ?>"<?= in_array((int) $t['id'], $oldTasks, true) ? ' checked' : '' ?>>
                  <span class="admin-pick-body"><span><?= e(L($t, 'title')) ?></span><span class="t-xs t-muted"><span class="t-mono"><?= e($t['output_type']) ?></span> · <?= ui_difficulty((int) $t['difficulty']) ?><?= $t['visibility'] === 'holdout' ? ' · ' . ui_badge(__('admin.tasks.holdout'), 'violet') : '' ?></span></span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card card-pad stack">
      <h2 class="card-title"><?= ui_icon('sliders', 18) ?> <?= _e('admin.campaigns.options') ?></h2>
      <div class="admin-grid admin-grid-2">
        <div class="field"><label class="label" for="cp-runs"><?= _e('admin.campaigns.runs_per_task') ?></label><input class="input t-num" id="cp-runs" type="number" name="runs_per_task" min="1" max="10" value="<?= $runs ?>" data-estimate-input><span class="help"><?= _e('admin.campaigns.runs_help') ?></span></div>
        <div class="field"><label class="checkbox"><input type="checkbox" name="include_in_scores" value="1"<?= !isset($old['include']) || $old['include'] ? ' checked' : '' ?>> <?= _e('admin.campaigns.include_help') ?></label></div>
      </div>
      <div class="field"><label class="label" for="cp-gen"><?= _e('admin.campaigns.gen_params') ?></label>
        <textarea class="textarea t-mono" id="cp-gen" name="gen_params" rows="4" dir="ltr" spellcheck="false" data-json-field="object" placeholder='{"temperature": 0.2}'><?= e((string) ($old['gen_params'] ?? '')) ?></textarea>
        <span class="admin-json-status" data-json-status="#cp-gen"></span>
        <span class="help"><?= _e('admin.campaigns.gen_help') ?></span></div>
    </section>
  </div>

  <aside class="admin-aside">
    <section class="card card-glass card-pad stack admin-estimate" aria-live="polite">
      <h2 class="card-title"><?= ui_icon('coin', 18) ?> <?= _e('admin.campaigns.estimate') ?></h2>
      <div class="admin-estimate-total"><span class="t-num" data-est-total>$0</span><span class="t-xs t-muted"><?= _e('admin.campaigns.est_total') ?></span></div>
      <dl class="admin-dl">
        <dt><?= _e('admin.campaigns.est_runs') ?></dt><dd class="t-num" data-est-runs>0</dd>
        <dt><?= _e('admin.campaigns.est_gen') ?></dt><dd class="t-num" data-est-gen>—</dd>
        <dt><?= _e('admin.campaigns.est_judge') ?></dt><dd class="t-num" data-est-judge>—</dd>
      </dl>
      <ul class="admin-est-models t-xs" data-est-models></ul>
      <p class="help" data-est-note><?= _e('admin.campaigns.est_note') ?></p>
      <button class="btn btn-primary btn-block btn-lg" type="submit"<?= $engine ? '' : ' disabled' ?>><?= ui_icon('play', 18) ?><span><?= _e('admin.campaigns.create') ?></span></button>
      <p class="t-xs t-muted"><?= _e('admin.campaigns.create_help') ?></p>
    </section>
  </aside>
</form>
