<?php
/**
 * Public task library. Vars: tasks, filters (track, difficulty, type, q), tracks, counts, best (task_id => best model).
 * Hold-out tasks only ever appear as a count.
 */
defined('WB') || exit;

$f = $filters;
$q = static fn(array $over) => url('/tasks', array_merge(['track' => $f['track'] ?: null, 'difficulty' => $f['difficulty'] ?: null, 'type' => $f['type'] ?: null, 'q' => $f['q'] ?: null], $over));
$filtered = $f['track'] !== '' || $f['difficulty'] || $f['type'] !== '' || $f['q'] !== '';
?>
<section class="section pub-hero">
  <div class="container-wide">
    <div class="pub-hero-grid">
      <div class="stack-sm">
        <p class="eyebrow"><?= _e('pub.tasks.eyebrow') ?></p>
        <h1 class="t-h1"><?= _e('pub.tasks.h1') ?></h1>
        <p class="t-lead t-muted pub-lead"><?= _e('pub.tasks.lead') ?></p>
      </div>
      <div class="card card-glass card-pad pub-holdout-card">
        <span class="pub-holdout-icon"><?= ui_icon('lock', 22) ?></span>
        <div class="stack-sm">
          <p class="t-h3 t-num"><?= _e('pub.tasks.counts', ['pub' => $counts['public'], 'hold' => $counts['holdout']]) ?></p>
          <p class="t-small t-muted"><?= _e('pub.tasks.holdout', ['n' => $counts['holdout']]) ?></p>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section pub-section-tight">
  <div class="container-wide stack">
    <nav class="pub-chips" aria-label="<?= _e('pub.lb.tracks_nav') ?>">
      <a class="chip<?= $f['track'] === '' ? ' active' : '' ?>" href="<?= e($q(['track' => null])) ?>"><?= ui_icon('layers', 14) ?><span><?= _e('common.all') ?></span></a>
      <?php foreach ($tracks as $slug => $t): ?>
        <a class="chip<?= $f['track'] === $slug ? ' active' : '' ?>" style="--track:<?= e($t['color']) ?>" href="<?= e($q(['track' => $slug])) ?>"<?= $f['track'] === $slug ? ' aria-current="page"' : '' ?>><?= ui_icon($t['icon'], 14) ?><span><?= e(L($t, 'name')) ?></span><span class="pub-chip-n t-num"><?= (int) $t['public_count'] ?></span></a>
      <?php endforeach; ?>
    </nav>

    <form class="pub-toolbar card card-glass" method="get" action="<?= e(url('/tasks')) ?>" role="search">
      <?php if ($f['track'] !== ''): ?><input type="hidden" name="track" value="<?= e($f['track']) ?>"><?php endif; ?>
      <label class="input-group pub-search">
        <?= ui_icon('search', 16) ?>
        <span class="sr-only"><?= _e('pub.tasks.search') ?></span>
        <input class="input" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="<?= _e('pub.tasks.search') ?>" maxlength="80">
      </label>
      <label class="pub-field-inline"><span class="sr-only"><?= _e('pub.difficulty') ?></span>
        <select class="select" name="difficulty" data-autosubmit>
          <option value=""><?= _e('pub.tasks.any_difficulty') ?></option>
          <?php foreach ([1 => 'common.diff_easy', 2 => 'common.diff_medium', 3 => 'common.diff_hard', 4 => 'common.diff_expert'] as $d => $k): ?>
            <option value="<?= $d ?>"<?= $f['difficulty'] === $d ? ' selected' : '' ?>><?= _e($k) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="pub-field-inline"><span class="sr-only"><?= _e('pub.output_type') ?></span>
        <select class="select" name="type" data-autosubmit>
          <option value=""><?= _e('pub.tasks.any_type') ?></option>
          <?php foreach (WB\Data\Tasks::OUTPUT_TYPES as $ty): ?>
            <option value="<?= e($ty) ?>"<?= $f['type'] === $ty ? ' selected' : '' ?>><?= _e('pub.type.' . $ty) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn btn-primary btn-sm" type="submit"><?= ui_icon('filter', 16) ?><span><?= _e('pub.tasks.apply') ?></span></button>
      <?php if ($filtered): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('/tasks')) ?>"><?= _e('pub.tasks.reset') ?></a><?php endif; ?>
    </form>

    <p class="t-small t-muted" aria-live="polite"><?= _e('pub.tasks.found', ['n' => count($tasks)]) ?></p>

    <?php if (!$tasks): ?>
      <?= ui_empty($filtered ? __('pub.tasks.none_match') : __('pub.tasks.empty_title'), $filtered ? __('pub.tasks.none_match_text') : __('pub.tasks.empty_text'), 'list') ?>
    <?php else: ?>
    <ul class="pub-task-grid" role="list">
      <?php foreach ($tasks as $t):
          $b = $best[(int) $t['id']] ?? null;
          $grading = WB\Data\Tasks::grading($t);
      ?>
      <li>
        <article class="card card-hover pub-task-card" style="--track:<?= e($t['color']) ?>">
          <div class="pub-task-top">
            <?= ui_track_badge($t) ?>
            <span class="pub-type" data-tip="<?= _e('pub.type.' . $t['output_type']) ?>"><?= ui_icon(pub_type_icon((string) $t['output_type']), 16) ?><span class="sr-only"><?= _e('pub.type.' . $t['output_type']) ?></span></span>
          </div>
          <h2 class="pub-task-title"><a class="pub-stretch" href="<?= e(url('/task/' . $t['slug'])) ?>"><?= e(L($t, 'title')) ?></a></h2>
          <?php if (L($t, 'summary') !== ''): ?><p class="t-small t-muted pub-clamp"><?= e(L($t, 'summary')) ?></p><?php endif; ?>
          <div class="pub-task-meta">
            <?= ui_difficulty((int) $t['difficulty']) ?>
            <span class="pub-grading" aria-label="<?= _e('pub.graded_by') ?>">
              <?php foreach ($grading as $g): ?><span class="pub-grade" data-tip="<?= _e('pub.grade.' . $g) ?>"><?= ui_icon(pub_grading_icon($g), 13) ?><span class="sr-only"><?= _e('pub.grade.' . $g) ?></span></span><?php endforeach; ?>
            </span>
          </div>
          <div class="pub-task-best">
            <?php if ($b && $b['model']): ?>
              <span class="t-xs t-muted"><?= _e('pub.tasks.best') ?></span>
              <span class="pub-task-best-row"><?= ui_model_chip($b['model'], false, false) ?><?= ui_heat_cell($b['score']) ?></span>
            <?php else: ?>
              <span class="t-xs t-faint"><?= _e('pub.tasks.no_results') ?></span>
            <?php endif; ?>
          </div>
        </article>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</section>
