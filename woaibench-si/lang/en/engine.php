<?php
// Benchmark engine strings: run notes, worker activity, judge roles, estimate notes, verifier API errors.
defined('WB') || exit;
return [
    'engine.note.no_judges' => 'No eligible judge from another vendor was available, so this run is scored on automated checks only.',
    'engine.note.judges_partial' => 'Only :detail judges from other vendors were available for this run.',
    'engine.note.tiebreak' => 'The judges disagreed by :detail points, so an extra tie-break judge was added.',
    'engine.note.no_code' => 'No code could be extracted from the reply, so every browser check and test counts as failed.',
    'engine.note.unverified_timeout' => 'No browser verifier was available within :detail minutes; scored without browser checks.',
    'engine.note.unverified_off' => 'Browser verification is switched off; scored without browser checks.',
    'engine.note.unverified_failed' => 'Browser verification failed three times (:detail); scored without browser checks.',
    'engine.note.render_fail' => 'The page did not render, so the final score was reduced by the render-failure factor.',
    'engine.note.judging_failed' => 'No judge returned a usable verdict.',
    'engine.note.generation' => 'Generation note: :detail.',
    'engine.note.retried' => 'This run was retried by an administrator.',

    'engine.kind.generate' => 'Generation',
    'engine.kind.judge' => 'Judgment',
    'engine.kind.score' => 'Scoring',
    'engine.kind.finalize' => 'Finalized',
    'engine.kind.verify_skipped' => 'Verification skipped',
    'engine.kind.verify_given_up' => 'Verification given up',
    'engine.kind.judge_expired' => 'Judge timed out',
    'engine.kind.generate_expired' => 'Generation timed out',
    'engine.kind.aggregate' => 'Leaderboard recomputed',
    'engine.kind.arena' => 'Arena ratings refreshed',

    'engine.role.panel' => 'Panel judge',
    'engine.role.replacement' => 'Replacement judge',
    'engine.role.tiebreak' => 'Tie-break judge',

    'engine.est.note_tokens' => 'Token use is estimated per output type (about 9k output tokens for web pages, 3.5k for code, 3k for answers); reasoning-heavy models can use several times more.',
    'engine.est.note_judges' => 'Judging assumes a panel of :n judges from other vendors per run, with screenshots for vision judges and a 15% allowance for tie-break judges.',
    'engine.est.note_reasoning' => 'Prices come from the model catalogue (USD per million tokens); models without a listed price are counted as free.',

    'engine.err.unauthorized' => 'Sign in as an administrator or use a verifier API token.',
    'engine.err.bad_request' => 'The request is missing a run id or lock token.',
    'engine.err.not_found' => 'This run no longer exists.',
    'engine.err.lease_lost' => 'This run is no longer reserved for you; it was released or taken by another verifier.',
    'engine.err.task_missing' => 'The task for this run no longer exists.',
    'engine.err.payload_too_large' => 'The submission is too large.',
];
