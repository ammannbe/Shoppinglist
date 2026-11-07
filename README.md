# Shoppinglist

Offline-first web app (PWA) to manage shopping lists and share them with your family and flatmates. German and English.

- **Works offline**: all lists live on the device and sync in the background as soon as a connection is available.
- **Share lists**: invite others by email, changes of all members are merged.
- **Households**: share lists with a household (e.g. family or flatmates), all its members see them, new members too.
- **Installable**: add it to the home screen on Android and iOS, no app store needed.
- **Passkeys**: log in with Face ID, Touch ID, Windows Hello or the device PIN.

Built with Laravel, Alpine.js and RxDB. See [AGENTS.md](AGENTS.md) for the architecture.

## Development

Requires [DDEV](https://ddev.com).

```bash
ddev start
ddev composer install
ddev npm install
ddev artisan key:generate
ddev artisan migrate --seed   # test@example.com / password
ddev npm run dev
```

- Tests: `ddev artisan test`
- Code style: `ddev exec vendor/bin/pint`

## Deployment (shared hosting, e.g. KeyHelp)

- PHP 8.3+, MariaDB/MySQL, HTTPS (required for service worker and offline mode).
- Document root: `public/`.
- `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `php artisan migrate --force`, `php artisan optimize`.
- Cron jobs, every minute (same as RecipeManager):
  ```
  * * * * *  cd /path-to-the-project && php artisan schedule:run >> /dev/null 2>&1
  * * * * *  cd /path-to-the-project && php artisan queue:work --max-time=50 >> /dev/null 2>&1
  ```
  The queue sends the mails (verification, password reset, invitations) and applies the household webhooks of the AccountManager.
- Login with an OpenID Connect provider (optional, e.g. Pocket ID): `AUTH_OIDC_*` in `.env` (see `config/login.php`). An account linked to the provider logs in only there (no password, no passkeys, no password reset); keep the `AUTH_LOCAL_EMERGENCY_EMAILS` accounts unlinked as local emergency access. Way back to passwords: `php artisan users:unlink-oidc <email>` (or `--all`), then "Forgot your password?".
- Households from the AccountManager (optional, needs the OIDC login): `HOUSEHOLDS_DRIVER=account-manager`, `ACCOUNT_MANAGER_URL`, `ACCOUNT_MANAGER_SERVICE_KEY`, `ACCOUNT_MANAGER_SECRET`; in the AccountManager set the webhook URL `<APP_URL>/api/account-manager/webhook` and generate the secret of the service. Switching from local households: `php artisan households:import` (see `AGENTS.md`).
- Entitlements from the AccountManager (optional, needs the OIDC login): `ENTITLEMENTS_DRIVER=account-manager` with the same `ACCOUNT_MANAGER_*` values and webhook. Access of linked users (suspended or removed: signed out, the app shows the reason) and the AI feature `shoppinglist.ai` (week planner) come from the AccountManager; unlinked users keep `ENTITLEMENTS_LOCAL_FEATURES` (default `shoppinglist.ai`, empty = AI off for all). Snapshots are fetched on the OIDC login and daily (`entitlements:sync`); `user.deleted` deletes the user (shared lists go to another member). Contract: AccountManager `docs/ENTITLEMENTS-API.md`.
- Apache: `public/.htaccess` sets the manifest MIME type and disables caching of `sw.js` (needs `mod_headers`).

### Automatic deployment (Forgejo Actions)

`.forgejo/workflows/deploy.yml` deploys every push to `main`: builds the assets (incl. service worker) in CI, copies them via SSH, then runs `git pull`, `composer install --no-dev`, migrations and `optimize` on the server (`php85`).

- Secret: `SSH_KEY` (private deploy key)
- Variables: `SSH_HOST`, `SSH_USER`, `SSH_FINGERPRINT` (SHA256 ed25519 host key), `DEPLOY_PATH`, optional `PRIMARY_SITE_URL` (checked after the deploy)
- The server needs a git checkout of the repository in `DEPLOY_PATH` and a configured `.env`.

## Import from the old API

The data of the former Laravel 7 API (ShoppinglistApi ≤ 2020) can be imported once into an empty database:

```bash
# .env: LEGACY_DB_HOST, LEGACY_DB_DATABASE, LEGACY_DB_USERNAME, LEGACY_DB_PASSWORD
php artisan shoppinglist:import-legacy
```

Users keep their passwords.

## License

AGPLv3 or later, see [LICENSE](LICENSE).
