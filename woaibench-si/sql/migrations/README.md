# Database migrations

`sql/schema.sql` always describes the **complete current schema** (fresh installs use it).
Files in this folder upgrade databases that were installed with an older version.

* Name: `NNN_short_description.sql` — three or more digits, applied in numeric order
  (`001_add_model_notes.sql`, `002_index_runs_finished.sql`, …).
* Each file is applied **exactly once** per database by `WB\Setup\Migrator` (installer, or
  Admin → Settings → *Migrate database*, i.e. `POST /admin/api/migrate`). Applied names are recorded in
  `settings.schema_version` → `{"app":"1.0.0","migrations":["001_…sql"],"files":{…},"applied_at":"…"}`.
* Migrations also run on fresh installs, right after `schema.sql`. Write them so that is harmless:
  the Migrator tolerates "already exists" errors (duplicate table / column / key / FK, can't drop a
  missing column, duplicate entry), so `ALTER TABLE … ADD COLUMN x` is safe even when `schema.sql`
  already has column `x`. Data migrations (`UPDATE …`) must be safe on empty tables.
* Never edit a migration that has shipped — add a new one. Also update `schema.sql` in the same change.
* Plain SQL only: statements end with `;`, comments `--`, `#` and `/* */` are fine, `DELIMITER`
  (stored procedures / triggers) is not supported. DDL auto-commits in MySQL, so keep each file small.
* A failing migration stops the run and is **not** recorded; fix it and run *Migrate database* again.
