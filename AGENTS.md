# AGENTS.md

## Project Structure

- `frontend/src/`: React frontend code. Entry point: `main.tsx`.
- `backend/`: PHP backend code.
  - `hooks/`: Route definitions.
    - `ajax.php`: AJAX routes.
    - `api.php`: REST API routes.
  - `app/HTTP/Controllers/`: PHP controllers for handling requests.
  - `app/Providers/*ServiceProvider.php`: wp-kit `Container`-backed DI. `Plugin::__construct()`
    registers every provider at load time; `Plugin::loaded()` hooks `registerProviders()` to
    `init:11`, which calls `$app->boot()` on all of them (kept at `init:11` for backward
    compatibility). Providers whose `register()` has WP-hook side effects (installer/activation,
    abilities) must run at _load_ time, not `boot()` — activation fires before `init:11` ever runs.
    `MailServiceProvider::boot()` force-resolves `WpMailBridge` so its constructor's
    `pre_wp_mail`/`wp_mail_succeeded` hooks actually register (a lazy singleton would silently never
    wire up if nothing else resolves it first). Access the container via `Plugin::instance()->app()`.
  - `app/Settings/PluginSettings.php`: schema + repository for the plugin's global preferences,
    backed by wp-kit `Settings`. Stored as a single option, `bit_smtp_preferences` (never re-prefix
    with `Config::withPrefix()` — read/write it with plain `get_option()`/`delete_option()` or through
    `PluginSettings`/`SettingsRepository`, not `Config::getOption()`). Distinct from the legacy,
    vestigial `bit_smtp_settings` option. `PluginSettings::getWithLegacyFallback()` reads the legacy
    option **only** while the preferences blob is entirely absent/empty; once seeded, the blob is the
    sole source of truth for that key, even if the legacy option is later changed. Seeding runs once,
    gated by a `Config::DB_VERSION` bump, via the `BitSmtpSettingsSeed` migration
    (`InstallerProvider::migration()`).
  - Cron: wp-kit `Scheduler`, wired in `CoreServiceProvider` (`retention_gc` daily job,
    `Config::RETENTION_GC_HOOK`). `deactivate()` clears it by explicit hook name via
    `wp_clear_scheduled_hook()`, not through a `Scheduler` instance — deactivation may fire before any
    provider's `boot()` runs this request.
  - Cache: wp-kit `CacheManager` (`transient` store, `bit_smtp_` prefix), consumed by
    `MailAnalyticsService::overview()` (5-minute TTL, success-only — a transient DB error is never
    cached). `TransientStore::flush()` is a documented no-op (WordPress can't enumerate/bulk-delete
    transients); a test or job that must invalidate a specific entry needs the exact cache key, not a
    flush.

## Agent Workflow

1. **Planning**
   - Always start with a detailed todo list for the task.
   - Break down complex tasks into actionable steps.
2. **Implementation**
   - Implement one todo at a time.
   - After each step, run lint, syntax check, and tests before proceeding.
3. **Feedback Loop**
   - Use a feedback loop: after each step, review results and clarify requirements if needed.
   - Ask for user feedback early to avoid unnecessary work and overuse of tokens.
   - Adjust plan based on feedback before continuing.

## Testing

- PHP suites are split (see `phpunit.xml.dist` vs `phpunit.integration.xml`): `composer test:unit`
  runs only `tests/Unit` + `tests/Golden` (no WordPress/DB, Brain\Monkey). Tests that touch `$wpdb`,
  REST routes, or `get_plugins()` belong in `tests/Integration` (`composer test:integration`, needs
  the docker `db`/`mailpit` services), not in the unit suite.
- `tests/Unit/Mail/Dispatch/MailEventLoggerTest::testBatchingFlushesWhenLimitIsBelowThreshold` is a
  known-flaky Mockery count assertion under the full unit run (passes in isolation); not a blocker.
- `IntegrationTestCase::setUp()` deletes the `bit_smtp_preferences` option before every test (mirrors
  its `options`/`failure_notification_active` resets), so any test seeding a legacy option (e.g.
  `Config::updateOption('log_retention', ...)`) actually reaches `PluginSettings::getWithLegacyFallback()`'s
  fallback path. A test that needs to exercise the seeded-preferences path must write
  `PluginSettings` explicitly. `wp-kit`'s `Response` static-singleton state (used by REST/Ability
  controllers) resets via the public `Response::reset()`, not reflection into private properties —
  the property name is an implementation detail that has already changed once (`$_instance` →
  `$_current`) across a wp-kit bump.
- Frontend tests are vitest, colocated as `*.test.tsx`. Gates: `pnpm test`, `pnpm lint`, `pnpm build`.

## Dependencies

- `bitapps/wp-kit` is pinned in `composer.json` by commit sha (`dev-feat/static-router#<sha>`) and
  imposter-scoped into `BitApps\SMTP\Deps\BitApps\WPKit\`. To pick up wp-kit changes: bump the sha in
  `composer.json`, then `composer update bitapps/wp-kit` (re-runs Imposter). wp-kit's own floor is PHP
  8.0; this plugin's is 8.1 — don't rely on 8.1+ syntax inside wp-kit-authored code.
- `composer.lock` is in `.prettierignore` — the `lefthook.yml` `pretty` pre-commit hook runs
  `pretty-quick --staged` with no glob filter, and prettier's JSON formatting fights composer's own.
- `php-cs-fixer` (`composer lint`) reformats `backend/app/Mail/MailMessage.php` when run under PHP
  8.5 (newer than the project's 8.1 floor); lint only files you actually changed, don't run it
  repo-wide on a newer PHP.

## Conventions

- Keep frontend and backend logic separated.
- Use the provided entry points and route files for new features.
- Follow existing code patterns for controllers and routes.
- Reference key files and folders for examples of project structure and conventions.

## Example

- For a new API endpoint:
  - Add route in `backend/hooks/api.php`.
  - Implement logic in a controller under `backend/app/HTTP/Controllers/`.
  - Backend Configuration in `backend/app/Config.php`
  - Request validator in `backend/app/HTTP/Requests`
  - Request Middleware in `backend/app/HTTP/Middleware`
  - Services in `backend/app/HTTP/Services`
  - Update frontend in `frontend/src/` as needed.
  - Frontend uses antd, typescript

---

**Always plan, implement, lint, check, and test in order. Use feedback to clarify and avoid wasted effort.**

## Maintaining this file

Keep this file for knowledge useful to almost every future agent session in this project.
Do not repeat what the codebase already shows; point to the authoritative file or command instead.
Prefer rewriting or pruning existing entries over appending new ones.
When updating this file, preserve this bar for all agents and keep entries concise.
