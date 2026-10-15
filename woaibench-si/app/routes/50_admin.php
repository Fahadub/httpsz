<?php
/**
 * Back-office routes (/admin/*). Handlers live in app/admin/*Controller.php (namespace WB\Admin).
 * Not defined here: /admin/api/step (engine) and /admin/verify (verifier).
 */
declare(strict_types=1);

defined('WB') || exit;

use WB\Admin\AccessController as Access;
use WB\Admin\DashboardController as Dash;
use WB\Admin\ModelsController as Models;
use WB\Admin\TracksController as Tracks;
use WB\Admin\TasksController as Tasks;
use WB\Admin\ForgeController as ForgeC;
use WB\Admin\CampaignsController as Campaigns;
use WB\Admin\RunsController as Runs;
use WB\Admin\SettingsController as SettingsC;
use WB\Admin\BrandController as Brand;
use WB\Admin\ContentController as Content;

// Access
Router::get('/admin/login', static fn() => Access::loginForm());
Router::post('/admin/login', static fn() => Access::login());
Router::post('/admin/logout', static fn() => Access::logout());
Router::get('/admin/account', static fn() => Access::account());
Router::post('/admin/account/password', static fn() => Access::changePassword());
Router::post('/admin/account/admins', static fn() => Access::addAdmin());
Router::post('/admin/account/admins/{id:\d+}/delete', static fn(string $id) => Access::deleteAdmin((int) $id));
Router::get('/admin/tokens', static fn() => Access::tokens());
Router::post('/admin/tokens', static fn() => Access::createToken());
Router::post('/admin/tokens/{id:\d+}/revoke', static fn(string $id) => Access::revokeToken((int) $id));

// Dashboard + console
Router::get('/admin', static fn() => Dash::index());
Router::get('/admin/console', static fn() => Dash::console());
Router::post('/admin/halt/clear', static fn() => Dash::clearHalt());

// Models
Router::get('/admin/models', static fn() => Models::index());
Router::get('/admin/models/new', static fn() => Models::edit(null));
Router::post('/admin/models/new', static fn() => Models::save(null));
Router::get('/admin/models/sync', static fn() => Models::sync());
Router::post('/admin/models/sync', static fn() => Models::syncPost());
Router::get('/admin/models/{id:\d+}', static fn(string $id) => Models::edit((int) $id));
Router::post('/admin/models/{id:\d+}', static fn(string $id) => Models::save((int) $id));
Router::post('/admin/models/{id:\d+}/delete', static fn(string $id) => Models::delete((int) $id));
Router::post('/admin/api/models/{id:\d+}', static fn(string $id) => Models::apiUpdate((int) $id));

// Tracks
Router::get('/admin/tracks', static fn() => Tracks::index());
Router::post('/admin/tracks/{id:\d+}', static fn(string $id) => Tracks::save((int) $id));

// Tasks
Router::get('/admin/tasks', static fn() => Tasks::index());
Router::get('/admin/tasks/new', static fn() => Tasks::edit(null));
Router::post('/admin/tasks/new', static fn() => Tasks::save(null));
Router::get('/admin/tasks/import', static fn() => Tasks::importForm());
Router::post('/admin/tasks/import', static fn() => Tasks::import());
Router::get('/admin/tasks/export', static fn() => Tasks::export());
Router::get('/admin/tasks/{id:\d+}', static fn(string $id) => Tasks::edit((int) $id));
Router::post('/admin/tasks/{id:\d+}', static fn(string $id) => Tasks::save((int) $id));
Router::post('/admin/tasks/{id:\d+}/action', static fn(string $id) => Tasks::action((int) $id));
Router::post('/admin/api/validate-task', static fn() => Tasks::apiValidate());

// Task Forge
Router::get('/admin/forge', static fn() => ForgeC::index());
Router::post('/admin/forge', static fn() => ForgeC::generate());
Router::post('/admin/forge/save', static fn() => ForgeC::save());
Router::post('/admin/forge/clear', static fn() => ForgeC::clear());

// Campaigns
Router::get('/admin/campaigns', static fn() => Campaigns::index());
Router::get('/admin/campaigns/new', static fn() => Campaigns::create());
Router::post('/admin/campaigns/new', static fn() => Campaigns::store());
Router::get('/admin/campaigns/{id:\d+}', static fn(string $id) => Campaigns::show((int) $id));
Router::post('/admin/campaigns/{id:\d+}/action', static fn(string $id) => Campaigns::action((int) $id));
Router::post('/admin/api/estimate', static fn() => Campaigns::apiEstimate());
Router::post('/admin/api/campaigns', static fn() => Campaigns::apiProgress());

// Runs
Router::get('/admin/runs', static fn() => Runs::index());
Router::get('/admin/runs/{id:\d+}', static fn(string $id) => Runs::show((int) $id));
Router::post('/admin/runs/{id:\d+}/action', static fn(string $id) => Runs::action((int) $id));

// Settings
Router::get('/admin/settings', static fn() => SettingsC::index());
Router::post('/admin/settings', static fn() => SettingsC::save());
Router::post('/admin/settings/secrets', static fn() => SettingsC::saveSecrets());
Router::post('/admin/settings/recompute', static fn() => SettingsC::recompute());
Router::post('/admin/api/test-key', static fn() => SettingsC::apiTestKey());

// Brand Studio
Router::get('/admin/brand', static fn() => Brand::index());
Router::post('/admin/brand/generate', static fn() => Brand::generate());
Router::post('/admin/brand/{id:\d+}/action', static fn(string $id) => Brand::action((int) $id));

// Requests + changelog
Router::get('/admin/requests', static fn() => Content::requests());
Router::post('/admin/requests/{id:\d+}', static fn(string $id) => Content::requestStatus((int) $id));
Router::get('/admin/changelog', static fn() => Content::changelog());
Router::get('/admin/changelog/new', static fn() => Content::changelogEdit(null));
Router::post('/admin/changelog/new', static fn() => Content::changelogSave(null));
Router::get('/admin/changelog/{id:\d+}', static fn(string $id) => Content::changelogEdit((int) $id));
Router::post('/admin/changelog/{id:\d+}', static fn(string $id) => Content::changelogSave((int) $id));
Router::post('/admin/changelog/{id:\d+}/delete', static fn(string $id) => Content::changelogDelete((int) $id));
