<?php
/** Step 1 — server requirements. Vars: $checks, $pass, $flashes. */
defined('WB') || exit;

$groups = ['setup.grp_php' => [], 'setup.grp_files' => [], 'setup.grp_server' => []];
foreach ($checks as $c) {
    $g = str_starts_with($c['id'], 'dir_') ? 'setup.grp_files' : ((str_starts_with($c['id'], 'ext_') || $c['id'] === 'php') ? 'setup.grp_php' : 'setup.grp_server');
    $groups[$g][] = $c;
}
$failed = count(array_filter($checks, static fn($c) => $c['level'] === 'required' && $c['status'] === 'fail'));
$warned = count(array_filter($checks, static fn($c) => $c['status'] === 'warn'));
$okCount = count(array_filter($checks, static fn($c) => $c['status'] === 'ok'));
$scored = count(array_filter($checks, static fn($c) => $c['status'] !== 'info'));
$tr = static fn(string $s): string => str_starts_with($s, 'setup.') ? __($s) : $s;
// Isolate Latin tokens (paths, extension names) so neutral characters such as a trailing "/" never flip in RTL.
$iso = static fn(array $p): array => array_map(static fn($v) => "\u{2066}" . $v . "\u{2069}", $p);
$icons = ['ok' => 'check', 'fail' => 'x', 'warn' => 'alert', 'info' => 'info'];
?>
<header class="panel-head">
  <span class="eyebrow"><?= _e('setup.step_of', ['n' => 1, 'total' => 4]) ?></span>
  <h1 class="display"><?= _e('setup.req_title') ?></h1>
  <p class="lead"><?= _e('setup.req_lead') ?></p>
</header>
<?= \WB\Setup\Wizard::partial('_flashes', ['flashes' => $flashes]) ?>

<div class="verdict <?= $pass ? 'verdict-ok' : 'verdict-fail' ?>" role="status">
  <span class="verdict-icon"><svg class="ic" aria-hidden="true"><use href="#i-<?= $pass ? 'check' : 'alert' ?>"/></svg></span>
  <div>
    <strong><?= $pass ? _e('setup.req_all_ok') : _e('setup.req_fix', ['n' => $failed]) ?></strong>
    <span class="verdict-sub"><?= _e('setup.req_score', ['ok' => $okCount, 'total' => $scored]) ?><?= $warned ? ' · ' . _e('setup.req_warned', ['n' => $warned]) : '' ?></span>
  </div>
</div>

<?php foreach ($groups as $gLabel => $items): if (!$items) { continue; } ?>
<section class="check-group">
  <h2 class="group-title"><?= _e($gLabel) ?></h2>
  <ul class="checklist">
<?php foreach ($items as $c): ?>
    <li class="check st-<?= e($c['status']) ?>"<?= $c['id'] === 'rewrite' && $c['status'] === 'info' ? ' data-rewrite-check data-url="' . e(base_path() . '/healthz') . '"' : '' ?>>
      <span class="check-icon"><svg class="ic" aria-hidden="true"><use href="#i-<?= $icons[$c['status']] ?>"/></svg></span>
      <span class="check-main">
        <span class="check-label"><?= e(__($c['label'], $iso($c['params']))) ?>
          <?php if ($c['level'] !== 'info'): ?><span class="tag tag-<?= e($c['level']) ?>"><?= _e('setup.req_' . $c['level']) ?></span><?php endif; ?>
        </span>
        <?php if ($c['hint'] && $c['status'] !== 'ok'): ?><span class="check-hint" data-hint><?= _e($c['hint']) ?></span><?php endif; ?>
      </span>
      <span class="check-detail" data-detail><bdi><?= e($tr($c['detail'])) ?></bdi></span>
    </li>
<?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>

<div class="actions">
  <a class="btn btn-ghost" href="<?= e(\WB\Setup\Wizard::url(1)) ?>"><svg class="ic" aria-hidden="true"><use href="#i-refresh"/></svg><?= _e('setup.recheck') ?></a>
<?php if ($pass): ?>
  <a class="btn btn-primary" href="<?= e(\WB\Setup\Wizard::url(2)) ?>"><?= _e('setup.continue') ?><svg class="ic flip" aria-hidden="true"><use href="#i-arrow"/></svg></a>
<?php else: ?>
  <span class="btn btn-primary is-disabled" aria-disabled="true"><?= _e('setup.continue') ?><svg class="ic flip" aria-hidden="true"><use href="#i-arrow"/></svg></span>
<?php endif; ?>
</div>
