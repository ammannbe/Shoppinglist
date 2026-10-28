# Shoppinglist

Offline-first web app (PWA) to manage shopping lists and share them with your family and flatmates. German and English.

- **Works offline**: all lists live on the device and sync in the background as soon as a connection is available.
- **Share lists**: invite others by email, changes of all members are merged.
- **Installable**: add it to the home screen on Android and iOS, no app store needed.

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
- Cron every minute: `php /path/to/artisan schedule:run` (also works off the mail queue, no queue worker needed).
- Apache: `public/.htaccess` sets the manifest MIME type and disables caching of `sw.js` (needs `mod_headers`).

## Import from the old API

The data of the former Laravel 7 API (ShoppinglistApi ≤ 2020) can be imported once into an empty database:

```bash
# .env: LEGACY_DB_HOST, LEGACY_DB_DATABASE, LEGACY_DB_USERNAME, LEGACY_DB_PASSWORD
php artisan shoppinglist:import-legacy
```

Users keep their passwords.

## License

AGPLv3 or later, see [LICENSE](LICENSE).
