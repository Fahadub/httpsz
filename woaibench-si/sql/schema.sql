-- WOAIBench.si — database schema (MySQL 5.7+/8.x, MariaDB 10.4+)
-- All timestamps are stored in UTC. JSON payloads are stored as text and decoded in PHP
-- so the schema works on every MySQL/MariaDB version Hostinger offers.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v MEDIUMTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  name VARCHAR(120) NOT NULL,
  pass_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'owner',
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  k VARCHAR(120) NOT NULL PRIMARY KEY,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  scope VARCHAR(32) NOT NULL DEFAULT 'verifier',
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Models under test (and judge models). Synced from the OpenRouter catalog.
CREATE TABLE IF NOT EXISTS models (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(160) NOT NULL,
  or_id VARCHAR(190) NOT NULL,
  name VARCHAR(190) NOT NULL,
  provider VARCHAR(80) NOT NULL,
  provider_name VARCHAR(120) NULL,
  description TEXT NULL,
  open_weights TINYINT(1) NOT NULL DEFAULT 0,
  params_b DECIMAL(9,2) NULL,
  active_params_b DECIMAL(9,2) NULL,
  n_layers SMALLINT UNSIGNED NULL,
  kv_dim INT UNSIGNED NULL,
  context_length INT UNSIGNED NULL,
  price_in DECIMAL(14,6) NULL,
  price_out DECIMAL(14,6) NULL,
  supports_vision TINYINT(1) NOT NULL DEFAULT 0,
  supports_tools TINYINT(1) NOT NULL DEFAULT 0,
  release_date DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_judge TINYINT(1) NOT NULL DEFAULT 0,
  judge_order SMALLINT NOT NULL DEFAULT 0,
  color VARCHAR(9) NULL,
  gen_params MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_models_slug (slug),
  UNIQUE KEY uq_models_or (or_id),
  KEY ix_models_active (is_active),
  KEY ix_models_judge (is_judge, judge_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Benchmark tracks (categories).
CREATE TABLE IF NOT EXISTS tracks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) NOT NULL,
  name_ar VARCHAR(120) NOT NULL,
  name_en VARCHAR(120) NOT NULL,
  desc_ar TEXT NULL,
  desc_en TEXT NULL,
  icon VARCHAR(40) NOT NULL DEFAULT 'layers',
  color VARCHAR(9) NOT NULL DEFAULT '#D9B26A',
  sort SMALLINT NOT NULL DEFAULT 0,
  auto_weight DECIMAL(4,3) NOT NULL DEFAULT 0.400,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_tracks_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Benchmark tasks. checks_json / rubric_json formats are documented in docs/ARCHITECTURE.md.
CREATE TABLE IF NOT EXISTS tasks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(120) NOT NULL,
  track_id INT UNSIGNED NOT NULL,
  title_ar VARCHAR(200) NOT NULL,
  title_en VARCHAR(200) NOT NULL,
  summary_ar TEXT NULL,
  summary_en TEXT NULL,
  prompt MEDIUMTEXT NOT NULL,
  output_type VARCHAR(16) NOT NULL DEFAULT 'html',
  difficulty TINYINT UNSIGNED NOT NULL DEFAULT 2,
  rubric_json MEDIUMTEXT NULL,
  checks_json MEDIUMTEXT NULL,
  auto_weight DECIMAL(4,3) NULL,
  visibility VARCHAR(12) NOT NULL DEFAULT 'public',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  season VARCHAR(16) NULL,
  source VARCHAR(16) NOT NULL DEFAULT 'seed',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tasks_slug (slug),
  KEY ix_tasks_track (track_id, is_active),
  CONSTRAINT fk_tasks_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A campaign = one benchmark batch (models x tasks x attempts).
CREATE TABLE IF NOT EXISTS campaigns (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  season VARCHAR(16) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'running',
  runs_per_task TINYINT UNSIGNED NOT NULL DEFAULT 3,
  model_ids MEDIUMTEXT NOT NULL,
  task_ids MEDIUMTEXT NOT NULL,
  gen_params MEDIUMTEXT NULL,
  include_in_scores TINYINT(1) NOT NULL DEFAULT 1,
  est_cost DECIMAL(12,4) NULL,
  spent_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  KEY ix_campaigns_status (status),
  KEY ix_campaigns_season (season)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One generation attempt of one model on one task.
CREATE TABLE IF NOT EXISTS runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NOT NULL,
  model_id INT UNSIGNED NOT NULL,
  task_id INT UNSIGNED NOT NULL,
  attempt TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status VARCHAR(20) NOT NULL DEFAULT 'queued',
  locked_until DATETIME NULL,
  lock_token CHAR(32) NULL,
  tries TINYINT UNSIGNED NOT NULL DEFAULT 0,
  verify_tries TINYINT UNSIGNED NOT NULL DEFAULT 0,
  prompt_tokens INT UNSIGNED NULL,
  completion_tokens INT UNSIGNED NULL,
  reasoning_tokens INT UNSIGNED NULL,
  cost_usd DECIMAL(12,6) NULL,
  spent_usd DECIMAL(12,6) NOT NULL DEFAULT 0, -- every paid call of the run (all attempts + judges), only grows
  latency_ms INT UNSIGNED NULL,
  finish_reason VARCHAR(40) NULL,
  provider_used VARCHAR(80) NULL,
  gen_id VARCHAR(120) NULL,
  raw_output LONGTEXT NULL,
  code LONGTEXT NULL,
  code_bytes INT UNSIGNED NULL,
  static_json MEDIUMTEXT NULL,
  browser_json MEDIUMTEXT NULL,
  agent_json LONGTEXT NULL,
  verified TINYINT(1) NOT NULL DEFAULT 0,
  verified_by VARCHAR(20) NULL,
  screenshot VARCHAR(255) NULL,
  screenshot_mobile VARCHAR(255) NULL,
  auto_score DECIMAL(5,2) NULL,
  judge_score DECIMAL(5,2) NULL,
  judge_spread DECIMAL(5,2) NULL,
  final_score DECIMAL(5,2) NULL,
  contested TINYINT(1) NOT NULL DEFAULT 0,
  error TEXT NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  UNIQUE KEY uq_run (campaign_id, model_id, task_id, attempt),
  KEY ix_runs_status (status, locked_until),
  KEY ix_runs_model_task (model_id, task_id),
  KEY ix_runs_task (task_id, status),
  CONSTRAINT fk_runs_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_runs_model FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE,
  CONSTRAINT fk_runs_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One judge's verdict on one run.
CREATE TABLE IF NOT EXISTS judgments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT UNSIGNED NOT NULL,
  judge_model_id INT UNSIGNED NOT NULL,
  role VARCHAR(16) NOT NULL DEFAULT 'panel',
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  locked_until DATETIME NULL,
  lock_token CHAR(32) NULL,
  tries TINYINT UNSIGNED NOT NULL DEFAULT 0,
  score DECIMAL(5,2) NULL,
  criteria_json MEDIUMTEXT NULL,
  rationale TEXT NULL,
  used_vision TINYINT(1) NOT NULL DEFAULT 0,
  prompt_tokens INT UNSIGNED NULL,
  completion_tokens INT UNSIGNED NULL,
  cost_usd DECIMAL(12,6) NULL,
  latency_ms INT UNSIGNED NULL,
  error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_judgment (run_id, judge_model_id),
  KEY ix_judgments_status (status, locked_until),
  CONSTRAINT fk_judgments_run FOREIGN KEY (run_id) REFERENCES runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_judgments_model FOREIGN KEY (judge_model_id) REFERENCES models(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aggregated scores. scope = 'overall' or 'track:<slug>'.
CREATE TABLE IF NOT EXISTS model_scores (
  model_id INT UNSIGNED NOT NULL,
  season VARCHAR(16) NOT NULL,
  scope VARCHAR(48) NOT NULL,
  score DECIMAL(5,2) NOT NULL,
  ci_low DECIMAL(5,2) NULL,
  ci_high DECIMAL(5,2) NULL,
  n_tasks INT UNSIGNED NOT NULL DEFAULT 0,
  n_runs INT UNSIGNED NOT NULL DEFAULT 0,
  auto_avg DECIMAL(5,2) NULL,
  judge_avg DECIMAL(5,2) NULL,
  consistency DECIMAL(5,2) NULL,
  avg_cost DECIMAL(12,6) NULL,
  avg_latency_ms INT UNSIGNED NULL,
  avg_tokens INT UNSIGNED NULL,
  coverage DECIMAL(5,2) NOT NULL DEFAULT 0,
  provisional TINYINT(1) NOT NULL DEFAULT 0,
  rank_pos INT UNSIGNED NULL,
  stat_rank INT UNSIGNED NULL,
  n_failed INT UNSIGNED NOT NULL DEFAULT 0, -- runs excluded from this score (failed / not scorable)
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (model_id, season, scope),
  KEY ix_scores_scope (season, scope, score),
  CONSTRAINT fk_scores_model FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS score_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  model_id INT UNSIGNED NOT NULL,
  season VARCHAR(16) NOT NULL,
  score DECIMAL(5,2) NOT NULL,
  ci_low DECIMAL(5,2) NULL,
  ci_high DECIMAL(5,2) NULL,
  rank_pos INT UNSIGNED NULL,
  recorded_on DATE NOT NULL,
  UNIQUE KEY uq_hist (model_id, season, recorded_on),
  CONSTRAINT fk_hist_model FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Arena (blind human preference).
CREATE TABLE IF NOT EXISTS arena_pairs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token CHAR(32) NOT NULL,
  task_id INT UNSIGNED NOT NULL,
  run_a BIGINT UNSIGNED NOT NULL,
  run_b BIGINT UNSIGNED NOT NULL,
  voter_hash CHAR(64) NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pair_token (token),
  KEY ix_pair_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS arena_votes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pair_id BIGINT UNSIGNED NOT NULL,
  task_id INT UNSIGNED NOT NULL,
  run_a BIGINT UNSIGNED NOT NULL,
  run_b BIGINT UNSIGNED NOT NULL,
  model_a INT UNSIGNED NOT NULL,
  model_b INT UNSIGNED NOT NULL,
  winner VARCHAR(10) NOT NULL,
  voter_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vote_pair (pair_id),
  KEY ix_votes_models (model_a, model_b),
  KEY ix_votes_voter (voter_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS arena_ratings (
  model_id INT UNSIGNED NOT NULL PRIMARY KEY,
  rating DECIMAL(8,2) NOT NULL,
  ci_low DECIMAL(8,2) NULL,
  ci_high DECIMAL(8,2) NULL,
  votes INT UNSIGNED NOT NULL DEFAULT 0,
  wins INT UNSIGNED NOT NULL DEFAULT 0,
  losses INT UNSIGNED NOT NULL DEFAULT 0,
  ties INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_arena_model FOREIGN KEY (model_id) REFERENCES models(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Public "please benchmark this model" requests.
CREATE TABLE IF NOT EXISTS model_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  or_id VARCHAR(190) NULL,
  name VARCHAR(190) NOT NULL,
  email VARCHAR(190) NULL,
  note TEXT NULL,
  ip_hash CHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'new',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_requests_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Images produced in the admin Brand Studio (OpenRouter image generation).
CREATE TABLE IF NOT EXISTS brand_assets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(20) NOT NULL DEFAULT 'logo',
  prompt TEXT NULL,
  model VARCHAR(190) NULL,
  path VARCHAR(255) NOT NULL,
  width INT UNSIGNED NULL,
  height INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_brand_kind (kind, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS changelog (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  version VARCHAR(32) NOT NULL,
  title_ar VARCHAR(200) NOT NULL,
  title_en VARCHAR(200) NOT NULL,
  body_ar TEXT NULL,
  body_en TEXT NULL,
  published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_changelog_pub (published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hardware catalogue for the Local Fit Lab.
CREATE TABLE IF NOT EXISTS gpus (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  vendor VARCHAR(40) NOT NULL,
  kind VARCHAR(16) NOT NULL DEFAULT 'gpu',
  vram_gb DECIMAL(7,2) NOT NULL,
  bandwidth_gbs DECIMAL(8,2) NOT NULL,
  fp16_tflops DECIMAL(8,2) NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_gpu_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
