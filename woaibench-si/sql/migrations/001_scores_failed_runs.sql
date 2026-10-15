-- model_scores.n_failed: how many of the model's runs in the season were excluded from the score
-- (status 'failed' after 3 infrastructure tries, or scored without a usable score). Written by WB\Eval\Aggregator.
ALTER TABLE model_scores ADD COLUMN n_failed INT UNSIGNED NOT NULL DEFAULT 0 AFTER stat_rank;
