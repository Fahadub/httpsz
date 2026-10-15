<?php
/** Step 1 — server requirements + setup token. Vars: $checks, $pass, $token_ok, $token_file (null: not created), $flashes. */
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

<section class="setup-token" id="setup-token">
<?php if ($token_ok): ?>
  <div class="callout callout-success" role="status">
    <svg class="ic" aria-hidden="true"><use href="#i-check"/></svg>
    <div><?= _e('setup.token_ok') ?></div>
  </div>
<?php elseif ($token_file === null): ?>
  <div class="callout callout-error" role="alert">
    <svg class="ic" aria-hidden="true"><use href="#i-alert"/></svg>
    <div><strong><?= _e('setup.token_title') ?></strong><p><?= _e('setup.token_unwritable', ['file' => "\u{2066}storage/cache/setup-token.txt\u{2069}"]) ?></p></div>
  </div>
<?php else: ?>
  <form method="post" action="<?= e(\WB\Setup\Wizard::url(1)) ?>" data-busy autocomplete="off" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="token">
    <fieldset class="fs">
      <legend class="fs-title"><svg class="ic" aria-hidden="true"><use href="#i-key"/></svg><?= _e('setup.token_title') ?></legend>
      <p class="fs-lead"><?= _e('setup.token_help', ['file' => "\u{2066}" . $token_file . "\u{2069}"]) ?></p>
      <div class="field">
        <label class="label" for="setup_token"><?= _e('setup.token_label') ?></label>
        <div class="key-row">
          <input class="input mono" id="setup_token" name="setup_token" type="text" dir="ltr" required maxlength="64" spellcheck="false" autocapitalize="off" autocomplete="off" placeholder="0123456789abcdef…">
          <button class="btn btn-primary" type="submit"><?= _e('setup.token_submit') ?><svg class="ic flip" aria-hidden="true"><use href="#i-arrow"/></svg></button>
        </div>
      </div>
    </fieldset>
  </form>
<?php endif; ?>
</section>

<div class="actions">
  <a class="btn btn-ghost" href="<?= e(\WB\Setup\Wizard::url(1)) ?>"><svg class="ic" aria-hidden="true"><use href="#i-refresh"/></svg><?= _e('setup.recheck') ?></a>
<?php if ($pass && $token_ok): ?>
  <a class="btn btn-primary" href="<?= e(\WB\Setup\Wizard::url(2)) ?>"><?= _e('setup.continue') ?><svg class="ic flip" aria-hidden="true"><use href="#i-arrow"/></svg></a>
<?php else: ?>
  <span class="btn btn-primary is-disabled" aria-disabled="true"><?= _e('setup.continue') ?><svg class="ic flip" aria-hidden="true"><use href="#i-arrow"/></svg></span>
<?php endif; ?>
</div>
