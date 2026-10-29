# AGENTS.md

Shoppinglist: offline-first PWA for shared shopping lists. One Laravel app serves the UI and the sync API.

## Stack

- Laravel 13, PHP 8.4, MariaDB 10.11, DDEV (`shoppinglist.ddev.site`). Run PHP/Composer/Artisan/npm via `ddev`.
- Auth: Fortify (session cookie, Blade views in `resources/views/auth`), email verification required for syncing.
- Frontend: Blade + Alpine.js + Tailwind 4 (Vite). No SPA framework, no Livewire (must work offline).
- Client database: RxDB (Dexie/IndexedDB storage) with HTTP replication.
- Hosting target: shared hosting with KeyHelp (PHP-FPM + cron, no long running processes, no websockets).

## Sync architecture

- The UI reads and writes only the local RxDB database; replication runs in the background (`resources/js/db`).
- Server side: `app/Sync/*Resource.php` implement pull (checkpoint `updated_at` + id) and push (conflict detection) per collection, routes `GET /sync/{collection}/pull`, `POST /sync/{collection}/push`.
- IDs are UUIDs generated on the client. Timestamps have millisecond precision (`Syncable` trait).
- Conflicts: every local change increases `version` (RxDB `preSave` hook); the server accepts a push only if `assumedMasterState.version` equals its version and keeps the client's version. Otherwise it returns its document and the server state wins.
- Lists are pulled via memberships (`shopping_list_user`), so revoked access and deleted lists arrive as deleted documents. Any list change has to call `touchForMembers()`; adding a member also touches the items.
- Items of a list unknown to the server return 409, the client retries (list created offline, not pushed yet).
- Sharing/leaving lists needs a connection (`MemberController`). Unknown emails get an account and a password-reset invitation.
- Units are pull only, public products (no owner) are read only.

## Translations

- Languages: `de_CH` (default, Swiss spelling "ss", informal "du") and `en`; keys are the English texts.
- One source for server and client: `lang/de_CH.json` (+ `lang/de_CH/*.php` for Laravel's validation/auth texts from laravel-lang, dev dependency). The app shell translates itself with `$t()` (`resources/js/i18n.js` imports the JSON), Blade pages and mails use `__()`.
- Locale per user (`users.locale`, `PUT /locale`), guests via cookie, otherwise browser language (`SetLocale` middleware). Mails use `HasLocalePreference`.
- After `php artisan lang:update`, replace "ß" by "ss" again and check that the informal texts are kept.

## PWA

- `vite-plugin-pwa` (generateSW) writes `public/sw.js` (not committed, built on deploy).
- Favicons + `public/site.webmanifest`: RealFaviconGenerator via `ddev npm run favicon -- --source src/favicon.png --theme '#047857' --bg '#fafaf9' --padding-bg '#ffd6d6'` (`src/scripts/favicon.mjs`, keeps extra manifest fields). Never use `/icons/`: Apache has a global `Alias /icons/`.
- The service worker precaches the app shell `/`, build assets and favicons (one 404 aborts the install!); other navigations fall back to the app shell offline.

## UI patterns

- Rows use `<x-swipe-row actions="…">` (`resources/js/swipe.js`): touch swipe left reveals the actions, full swipe runs the last one; mouse gets a hover "…" menu (`pointer-fine`/`pointer-coarse`). Actions are defined once in `listActions()`/`itemActions()`.
- Deletions are undoable (`resources/js/undo.js`): rows are hidden, the RxDB remove runs after 5 s or when the app is hidden. Never revive deleted documents.

## Deployment

- `.forgejo/workflows/deploy.yml` (same as RecipeManager): push to `main` builds assets in CI, uploads `public/build`, `public/sw.js` and `public/workbox-*.js`, then `git pull` + `composer install --no-dev` + `migrate` + `optimize` on the server via SSH.

## Commands

- Tests: `ddev artisan test`. Code style: `ddev exec vendor/bin/pint`.
- Assets: `ddev npm run build` (or `ddev npm run dev`).
- Production database: `ddev pull production` (`.ddev/providers/production.yaml`, SSH data in git-ignored `.ddev/config.local.yaml`: `SSH_USER`, `SSH_HOST`, `PROJECT_PATH`). No files are pulled.
- Import of the old Laravel 7 API database: `ddev artisan shoppinglist:import-legacy` (connection `legacy`, `LEGACY_DB_*`). Aborts if lists already exist.

## Gotchas

- After renaming/moving the project, Blade may serve stale compiled views (Mutagen keeps old mtimes): `ddev artisan optimize:clear`.
- `node` on the host may be a Docker wrapper; use `ddev npm …` or `~/.openclaw/tools/node-*/bin/node` for host scripts.

## Rules

- Every synced field must exist in the RxDB schema (`resources/js/db/schemas.js`), the resource document and the push validation (`SyncController`). Schema changes in RxDB need a schema version bump and migration strategy.
- Keep the app shell (`resources/views/app.blade.php`) free of user data, it is cached by the service worker.
