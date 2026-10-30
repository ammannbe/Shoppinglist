# AGENTS.md

Shoppinglist: offline-first PWA for shared shopping lists. One Laravel app serves the UI and the sync API.

## Stack

- Laravel 13, PHP 8.4, MariaDB 10.11, DDEV (`shoppinglist.ddev.site`). Run PHP/Composer/Artisan/npm via `ddev`.
- Auth: Fortify (session cookie, Blade views in `resources/views/auth`), email verification required for syncing. Passkeys via Fortify + `laravel/passkeys` (client `@laravel/passkeys` in `resources/js/auth.js`); managing them requires a password (or passkey) confirmation. Account page `/account` (password change, passkeys) is server rendered and needs a connection.
- Frontend: Blade + Alpine.js + Tailwind 4 (Vite). No SPA framework, no Livewire (must work offline).
- Client database: RxDB (Dexie/IndexedDB storage) with HTTP replication.
- Hosting target: shared hosting with KeyHelp (PHP-FPM + cron, no long running processes, no websockets). Cron every minute: `schedule:run` and `queue:work --max-time=50` (like RecipeManager).

## Sync architecture

- The UI reads and writes only the local RxDB database; replication runs in the background (`resources/js/db`).
- Server side: `app/Sync/*Resource.php` implement pull (checkpoint `updated_at` + id) and push (conflict detection) per collection, routes `GET /sync/{collection}/pull`, `POST /sync/{collection}/push`.
- IDs are UUIDs generated on the client. Timestamps have millisecond precision (`Syncable` trait).
- Conflicts: every local change increases `version` (RxDB `preSave` hook); the server accepts a push only if `assumedMasterState.version` equals its version and keeps the client's version. Otherwise it returns its document and the server state wins.
- Lists are pulled via memberships (`shopping_list_user`), so revoked access and deleted lists arrive as deleted documents. Any list change has to call `touchForMembers()`; adding a member also touches the items.
- Items of a list unknown to the server return 409, the client retries (list created offline, not pushed yet).
- Sharing/leaving lists needs a connection (`MemberController`). Unknown emails get an account and a password-reset invitation.
- Products, units and shops (`CatalogResource`): own entries per user, public ones (no owner) are read only. Shops (`stores`: name + optional code, max. 10 chars, case kept) have five standard shops from their migration (Migros M, Coop C, Aldi A, Lidl L, Denner D). Items keep their shops as tags in `items.stores` (JSON list: the code, or the name of a shop without code; copied like `image`, so members of shared lists see them), the badge shows the shop name as tooltip. Names and codes must not collide (both are tags). The edit dialog picks shops as chips (`suggestField` source `store`), an unknown typed one becomes an own shop without code; changing an own shop's tag updates the items. Own shops are managed in `#/stores`; standard shops can be hidden from the shop choice per user (`preferences.hidden_stores`, codes). Lists can be filtered by shop on the device (`shoppinglist.store-filter`; items without shop stay visible, dragging while filtered moves next to the target row). Pushes from older clients with `store` (one code) and without `recipes` keep working (`ItemResource::attributes`).
- Standard products: public products from `database/data/standard-products.csv` (name, category for review only), synced by `shoppinglist:standard-products` (`--dry-run`): adds missing, removes unlisted ones and deletes own duplicates without image. Run by a migration; when the list changes, add a new migration that calls it again. An own product with the name of a standard product only carries the user's photo: the products view shows the standard product with it, removing the photo deletes the own copy.
- `items.recipes`: recipes the item was planned for (`[{name, url}]`), shown in the row, linked in the edit dialog (own recipes only; AI ideas have no URL).
- Sorting: options `manual`, `name_asc`, `name_desc`, `newest`, `oldest` (ids are UUID v7 = creation time). Lists overview per user (`preferences.list_sort`), items per list (`shopping_lists.item_sort`, shared). Manual order: `items.position` (shared) and `shopping_list_user.position` (personal, list document field `position`); null positions sort after the positioned ones. Dragging a row writes the shown order as positions (only changed ones) and switches to `manual`.
- Product images: `POST/DELETE /products/{id}/image` (`ImageController`, creates the product if not synced yet), files in `storage/app/private/images/<uuid>` (max. 1200 px, full view) and `<uuid>-thumb` (max. 240 px, rows; older images have none, then the full image is served), served by `GET /images/{uuid}` and `/images/{uuid}/thumb` to the product owner and members of lists with an item using it. The client scales photos to both sizes as WebP/JPEG (`resources/js/image.js`, also drops EXIF), the server accepts max. 700 KB / 100 KB. Tap on a thumbnail opens the full view (`resources/js/lightbox.js`: double tap, pinch and wheel zoom, closes with tap, ✕, Esc or back). The image id is copied to `items.image` (push only accepts images of own products); the service worker caches `/images/*` (CacheFirst).

## Translations

- Languages: `de_CH` (default, Swiss spelling "ss", informal "du") and `en`; keys are the English texts.
- One source for server and client: `lang/de_CH.json` (+ `lang/de_CH/*.php` for Laravel's validation/auth texts from laravel-lang, dev dependency). The app shell translates itself with `$t()` (`resources/js/i18n.js` imports the JSON), Blade pages and mails use `__()`.
- Locale per user (`users.locale`, `PUT /locale`), guests via cookie, otherwise browser language (`SetLocale` middleware). Mails use `HasLocalePreference`.
- After `php artisan lang:update`, replace "ß" by "ss" again and check that the informal texts are kept.

## PWA

- `vite-plugin-pwa` (generateSW) writes `public/sw.js` (not committed, built on deploy).
- Favicons + `public/site.webmanifest`: RealFaviconGenerator via `ddev npm run favicon -- --source src/favicon.png --theme '#047857' --bg '#fafaf9' --padding-bg '#ffd6d6'` (`src/scripts/favicon.mjs`, keeps extra manifest fields). Never use `/icons/`: Apache has a global `Alias /icons/`.
- Updates: `registerType: 'prompt'`; `resources/js/pwa.js` checks for a new service worker on foreground, reconnect and hourly, the app shows "Neue Version verfügbar" and reloads on click (otherwise the new version starts with the next app start).
- Links to server pages in the app shell get `data-online`: dimmed offline, click shows a notice. If a server page is still opened offline, the app shell shows the `offline` view (any path other than `/`).
- The service worker precaches the app shell `/`, build assets and favicons (one 404 aborts the install!); other navigations fall back to the app shell offline.

## UI patterns

- Install instructions: `resources/js/install.js` (platform detection, steps per OS/browser, native `beforeinstallprompt` button), first visit hint + `#/install` view, linked in the settings.

- Rows use `<x-swipe-row actions="…">` (`resources/js/swipe.js`): on touch either swipe left to reveal the actions (no full swipe action) or long press for the actions menu, per user setting `preferences.row_actions` (`users.preferences` JSON, `PUT /preferences`, `resources/js/prefs.js`: cached in localStorage and resent when offline). Mouse gets a hover "…" menu (`pointer-fine`/`pointer-coarse`) and the right-click menu. Actions are defined once in `listActions()`/`itemActions()`/`catalogActions()`.
- Suggestions: `suggestField` (`resources/js/suggest.js`) + `<x-suggestions>` instead of `<datalist>`, sources `item` (quick entry: units right after the amount, picking one keeps typing; then products, picking one adds the item), `product` and `unit` (lists all on focus). Accent-insensitive, ranked exact > prefix > word start > anywhere, then by usage counted from the current items. Adding a product that is already open on the list asks to increase the amount (same unit only) or add anyway. Own products/units: `#/products`, `#/units` (rename to an existing name deletes the renamed one); new units from the edit dialog are stored.
- Row gestures (`resources/js/swipe.js` + `drag.js`): touch hold and move drags a row (rows with `data-sort-id`, event `row-move` → `moveRow()`), mouse drags directly; hold and release opens the menu in the long-press setting. While a row is held, `touchmove` is prevented to block scrolling.
- Deletions are undoable (`resources/js/undo.js`): rows are hidden, the RxDB remove runs after 5 s or when the app is hidden. Never revive deleted documents.

## Week planner (AI) and recipe app

- `#/planner` (`resources/views/partials/planner.blade.php`, `resources/js/planner.js`): offers (images, PDF, text) → pantry photos (optional) → recipe suggestions → shopping list into a new or existing list. Shown for verified users when `OPENAI_API_KEY` is set (`/me` `assistant`). State kept in localStorage (`shoppinglist.planner`). Per-shop instructions for getting the offers (copy text or download the PDF) are a collapsible list in the partial; pasted text is sent in parts of max. 18,000 characters (server limit 20,000). Without a recipe app connection a notice at the top links to `/recipes/connect`.
- Server: `PlannerController` (stateless, the client sends offers/pantry back), `app/Ai/Assistant.php` (prompts + strict JSON schemas), `app/Ai/OpenAiClient.php` (Responses API via `Http`, no SDK; `OPENAI_MODEL` default gpt-6-luna (cheapest model that read the test offers reliably), `OPENAI_REASONING_EFFORT`). One global key for all users (free); keep the client per request so own user keys can be added later. Rate limit `throttle:ai` (60/h per user). Requests may take up to ~2 min (`set_time_limit`, `OPENAI_TIMEOUT`).
- Offers pool (`#/offers`, `resources/js/offers.js`, `OfferController`, `offers` table, server only, no offline sync): own offers plus the ones others shared (`shared`, default on; device setting `shoppinglist.share-offers` for read offers). Reading offers (`POST /planner/offers`, also used by the offers page) saves them in the pool and returns them with ids; the same offer (shop, name, brand, end date) is not added twice. Only the creator may edit or delete. The planner shows the saved offers not taken yet per shop as soon as it is opened ("Take" per shop or all); taken offers are grouped by shop (collapsible, select/remove per shop). `DELETE /offers` with `store` deletes the own offers of a shop. Planner steps use `<x-planner-step>` cards. `offers:prune` (daily) removes expired offers and undated ones after `Offer::UNDATED_DAYS`. Shared reading UI: `<x-offer-reader>` with `requests()` + `offerReader()`.
- Shop codes: `Assistant::SHOPS` (the standard shops, keep in sync with the `stores` migration) → `items.stores`; offer details → `items.note`. Own shops are not passed to the AI.
- Recipe app ("white label"): `RECIPES_NAME` (quote values with spaces, e.g. `"Ammanns Best"`), `RECIPES_URL`, `RECIPES_CLIENT_ID`, `RECIPES_CLIENT_SECRET` (Passport client in the recipe app, redirect URI `<APP_URL>/recipes/callback`). OAuth flow in `RecipesConnectionController`, API calls and token refresh in `app/Recipes/RecipesClient.php`, tokens encrypted in `users.recipes_connection`. API contract: RecipeManager `AGENTS.md`.
- Progress and errors are shown at the button that started a request (`run(key, label, action)`, `busyKey`/`errorKey`, `<x-spinner>`, `<x-planner-error>`). "Suggest more recipes" sends the shown ones as `exclude` (recipe ids + names) and appends the new ones unselected.
- Suggestions prefer recipes combining several offers: own recipes are searched by offer foods first, then pantry foods, and sorted by matched offers. With a connected recipe app, AI ideas use its categories, units and complexities (`GET /api/vocabulary`).
- AI ideas are never saved directly: "Import into …" posts them as a draft (`POST /api/recipe-drafts`, recipe import JSON) and opens the returned import wizard URL in a new tab, where the user matches foods, units and the category and picks cookbook and visibility. Unknown values can be omitted there, or requested (admins approve them).
- Tests fake OpenAI and the recipe app with `Http::fake()` (`tests/Feature/PlannerTest.php`).

## Link previews

- `resources/views/partials/meta.blade.php` (fixed German texts, cached app shell) in the app shell and the guest layout; image `public/og-image.jpg` (1200×630). `og:url`/`og:image` use `APP_URL`.

## Deployment

- `.forgejo/workflows/deploy.yml` (same as RecipeManager): push to `main` builds assets in CI, uploads `public/build`, `public/sw.js` and `public/workbox-*.js`, then `git pull` + `composer install --no-dev` + `migrate` + `optimize` on the server via SSH.

## Commands

- Tests: `ddev artisan test`. Code style: `ddev exec vendor/bin/pint`.
- Assets: `ddev npm run build` (or `ddev npm run dev`).
- Production database: `ddev pull production` (`.ddev/providers/production.yaml`, SSH data in git-ignored `.ddev/config.local.yaml`: `SSH_USER`, `SSH_HOST`, `PROJECT_PATH`). Pulls the database and mirrors the product images into `storage/app/private/images` (nothing else of `storage/app`).
- Standard products: `ddev artisan shoppinglist:standard-products --dry-run` shows what would change.
- Import of the old Laravel 7 API database: `ddev artisan shoppinglist:import-legacy` (connection `legacy`, `LEGACY_DB_*`). Aborts if lists already exist.

## Gotchas

- After renaming/moving the project, Blade may serve stale compiled views (Mutagen keeps old mtimes): `ddev artisan optimize:clear`.
- After renaming the DDEV project, delete the old `.ddev/traefik/{certs,config}/<old-name>.*` files: `.ddev/.gitignore` only ignores the certificates of the current name, so dev keys would end up in a commit.
- `node` on the host may be a Docker wrapper; use `ddev npm …` or `~/.openclaw/tools/node-*/bin/node` for host scripts.

## Rules

- Alpine proxies (e.g. arrays from `x-model` state) must be copied to plain values before `insert`/`incrementalPatch`: RxDB clones documents for the BroadcastChannel (DataCloneError).
- Every synced field must exist in the RxDB schema (`resources/js/db/schemas.js`), the resource document and the push validation (`SyncController`). Schema changes in RxDB need a schema version bump and migration strategy.
- Keep the app shell (`resources/views/app.blade.php`) free of user data, it is cached by the service worker.
