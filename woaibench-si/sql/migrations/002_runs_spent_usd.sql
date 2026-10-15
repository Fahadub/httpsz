-- runs.spent_usd: every paid call of a run — failed and discarded generation attempts and judge calls included.
-- It only grows (regenerate / re-judge keep it), and campaigns.spent_cost is its sum (WB\Eval\Campaign::refreshSpent).
-- runs.cost_usd stays the cost of the stored generation (used for the average cost per task).
ALTER TABLE runs ADD COLUMN spent_usd DECIMAL(12,6) NOT NULL DEFAULT 0 AFTER cost_usd;

-- Existing runs: their stored generation cost plus their judgments' costs (what campaigns.spent_cost summed so far).
UPDATE runs r
   SET r.spent_usd = COALESCE(r.cost_usd, 0) + (SELECT COALESCE(SUM(j.cost_usd), 0) FROM judgments j WHERE j.run_id = r.id)
 WHERE r.spent_usd = 0;
